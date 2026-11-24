<?php

namespace App\Jobs;

use App\Models\CreativePackageItem;
use App\Services\CreativePackageAttemptFailed;
use App\Services\CreativePackageItemAttempts;
use App\Services\CreativePackageItemExecutor;
use App\Services\CreativePackageItemFence;
use App\Services\CreativePackageItemLease;
use App\Services\StaleFenceException;
use App\Support\CreativePackageContract;
use App\Support\CreativePackageDiagnostic;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One queued job per package item. The queue holds at most one copy per item
 * for one lease length, so a redispatch after a lost worker is possible. The
 * database fence stays authoritative: the job claims it or stops.
 *
 * Attempts: the fence counts one attempt per claim and refuses the claim
 * after MAX_ATTEMPTS. The framework bounds one run to the attempt timeout
 * and retries a throwing run with the contract backoff. A run that throws
 * surrenders its lease first, so the retry can claim the next attempt. A
 * timed-out run is killed and keeps its lease, so the item is reclaimed only
 * after the lease expires. A run that finds no claim closes a spent cycle.
 * A run that throws escapes as CreativePackageAttemptFailed, which names no
 * message content.
 *
 * Every run mints one correlation id. The queue payload uuid is not used,
 * because a framework retry pushes the same payload and every attempt needs
 * its own id.
 */
class ProcessCreativePackageItem implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $uniqueFor;

    public int $tries;

    public int $timeout;

    public function __construct(public readonly int $itemId)
    {
        $this->uniqueFor = CreativePackageContract::limit('lease_seconds', CreativePackageContract::LEASE_SECONDS);
        $this->tries = CreativePackageContract::limit('max_attempts', CreativePackageContract::MAX_ATTEMPTS);
        $this->timeout = CreativePackageContract::limit('attempt_timeout_seconds', CreativePackageContract::ATTEMPT_TIMEOUT_SECONDS);
        $this->onQueue(Queues::IMPORTS);
    }

    public function uniqueId(): string
    {
        return (string) $this->itemId;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return CreativePackageContract::ATTEMPT_BACKOFF_SECONDS;
    }

    public function handle(CreativePackageItemFence $fence, CreativePackageItemExecutor $executor, CreativePackageItemAttempts $attempts): void
    {
        $correlationId = (string) Str::uuid();
        $item = CreativePackageItem::query()->find($this->itemId);
        if ($item === null) {
            Log::warning('creative_package_item.missing', ['item_id' => $this->itemId, 'correlation_id' => $correlationId]);

            return;
        }
        $lease = $fence->claim($item);
        if ($lease === null) {
            $attempts->exhaustIfSpent($item);

            return;
        }
        try {
            $executor->execute($item->fresh(), $lease, $correlationId);
        } catch (\Throwable $exception) {
            $this->surrender($fence, $lease, $correlationId);
            Log::warning('creative_package_item.attempt_failed', ['item_id' => $this->itemId, 'correlation_id' => $correlationId, 'exception_class' => CreativePackageDiagnostic::className($exception)]);

            throw new CreativePackageAttemptFailed($this->itemId, $correlationId);
        }
    }

    /** After the last try, close a spent cycle. An item under a live lease is left to the reconciler. */
    public function failed(\Throwable $exception): void
    {
        $item = CreativePackageItem::query()->find($this->itemId);
        if ($item !== null) {
            app(CreativePackageItemAttempts::class)->exhaustIfSpent($item);
        }
    }

    /**
     * Expire the lease this run holds so the retry can claim. A fence that
     * already moved needs nothing. Any other failure here is logged and never
     * masks the throwable the caller rethrows.
     */
    private function surrender(CreativePackageItemFence $fence, CreativePackageItemLease $lease, string $correlationId): void
    {
        try {
            $fence->guard($lease, ['lease_expires_at' => now()->subSecond()]);
        } catch (StaleFenceException) {
        } catch (\Throwable $exception) {
            Log::warning('creative_package_item.surrender_failed', ['item_id' => $lease->itemId, 'correlation_id' => $correlationId, 'exception_class' => CreativePackageDiagnostic::className($exception)]);
        }
    }
}
