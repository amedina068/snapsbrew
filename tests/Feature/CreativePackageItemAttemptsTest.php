<?php

namespace Tests\Feature;

use App\Jobs\ProcessCreativePackageItem;
use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use App\Services\CreativePackageAttemptFailed;
use App\Services\CreativePackageItemAttempts;
use App\Services\CreativePackageItemExecutor;
use App\Services\CreativePackageItemFence;
use App\Services\CreativePackageItemLease;
use App\Services\StaleFenceException;
use App\Support\CreativePackageContract;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\BuildsCreativePackages;
use Tests\TestCase;

/**
 * The heartbeat renews the lease only while the fence is live. The attempt
 * cycle is finite: three claims, then a retryable failure, and no fourth
 * claim until a replay reopens the item.
 */
class CreativePackageItemAttemptsTest extends TestCase
{
    use BuildsCreativePackages;
    use RefreshDatabase;

    private CreativePackageItemFence $fence;

    private CreativePackageItemAttempts $attempts;

    /** What the executor double does with the item it is handed: null leaves the item rendering, like a killed worker. */
    private ?\Closure $executor = null;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow($this->now());
        $this->fence = app(CreativePackageItemFence::class);
        $this->attempts = app(CreativePackageItemAttempts::class);
        $executor = &$this->executor;
        $this->app->instance(CreativePackageItemExecutor::class, new class($executor) extends CreativePackageItemExecutor
        {
            public function __construct(private ?\Closure &$executor) {}

            public function execute(CreativePackageItem $item, CreativePackageItemLease $lease, ?string $correlationId = null): void
            {
                if ($this->executor !== null) {
                    ($this->executor)($item, $lease);
                }
            }
        });
        $this->recordQueries();
    }

    public function test_a_heartbeat_before_expiry_renews_the_lease(): void
    {
        $item = $this->oneItem();
        $lease = $this->fence->claim($item);
        $later = $this->now()->addSeconds(100);
        CarbonImmutable::setTestNow($later);

        $this->attempts->heartbeat($item, $lease);

        $this->assertLeaseRenewedAt($item, $later);
    }

    public function test_a_heartbeat_at_expiry_renews_and_a_heartbeat_after_expiry_renews_nothing(): void
    {
        $item = $this->oneItem();
        $lease = $this->fence->claim($item);
        $expiresAt = $this->now()->addSeconds(CreativePackageContract::LEASE_SECONDS);

        CarbonImmutable::setTestNow($expiresAt);
        $this->attempts->heartbeat($item, $lease);
        $this->assertLeaseRenewedAt($item, $expiresAt);

        CarbonImmutable::setTestNow($expiresAt->addSeconds(CreativePackageContract::LEASE_SECONDS + 1));
        try {
            $this->attempts->heartbeat($item, $lease);
            $this->fail('A dead lease renewed.');
        } catch (StaleFenceException) {
        }
        $this->assertLeaseRenewedAt($item, $expiresAt);
    }

    public function test_a_stale_heartbeat_cannot_extend_ownership_after_a_second_claim(): void
    {
        $item = $this->oneItem();
        $first = $this->fence->claim($item);
        $transfer = $this->now()->addSeconds(CreativePackageContract::LEASE_SECONDS + 1);
        CarbonImmutable::setTestNow($transfer);
        $second = $this->fence->claim($item);
        CarbonImmutable::setTestNow($transfer->addSeconds(60));

        try {
            $this->attempts->heartbeat($item, $first);
            $this->fail('A stale heartbeat renewed.');
        } catch (StaleFenceException) {
        }

        $this->assertSame($second->token, $item->fresh()->lease_token);
        $this->assertLeaseRenewedAt($item, $transfer);
    }

    public function test_three_timed_out_attempts_end_in_a_retryable_failure_and_no_fourth_claim_runs(): void
    {
        $item = $this->oneItem();

        $this->runAttempts($item, 3);
        $this->assertSame([CreativePackageItem::STATUS_RENDERING, 3], [$item->fresh()->status, $item->fresh()->attempts]);

        $this->runAttempts($item, 2);

        $fresh = $item->fresh();
        $this->assertSame([CreativePackageItem::STATUS_FAILED, true, 'creative_package_attempts_exhausted', 3, null], [$fresh->status, $fresh->retryable, $fresh->failure_code, $fresh->attempts, $fresh->lease_expires_at]);
        $this->assertSame([CreativePackage::STATUS_PARTIAL, 1, 0], [$fresh->package->status, $fresh->package->failed_items_count, $fresh->package->rendering_items_count]);
    }

    public function test_a_second_exhaust_on_a_closed_item_returns_false_and_locks_nothing(): void
    {
        $item = $this->oneItem();
        $this->runAttempts($item, 4);
        $this->assertSame(CreativePackageItem::STATUS_FAILED, $item->fresh()->status);
        $this->sql = [];

        $this->assertFalse($this->attempts->exhaustIfSpent($item));

        $this->assertSame(0, $this->queriesTouching('creative_packages'));
    }

    public function test_two_reopens_in_the_same_second_both_succeed_and_leave_attempts_at_zero(): void
    {
        $item = $this->oneItem();
        $this->runAttempts($item, 4);

        $this->assertSame([true, true], [$this->fence->reopen($item), $this->fence->reopen($item)]);

        $this->assertSame([CreativePackageItem::STATUS_FAILED, 0, null], [$item->fresh()->status, $item->fresh()->attempts, $item->fresh()->lease_expires_at]);
    }

    public function test_a_replay_reopens_one_new_bounded_cycle(): void
    {
        $item = $this->oneItem();
        $this->runAttempts($item, 3);
        $this->assertFalse($this->fence->reopen($item));
        $this->runAttempts($item, 1);

        $this->assertTrue($this->fence->reopen($item));
        $this->assertSame([CreativePackageItem::STATUS_FAILED, 0], [$item->fresh()->status, $item->fresh()->attempts]);
        $this->runAttempts($item, 3);
        $this->assertSame([CreativePackageItem::STATUS_RENDERING, 3], [$item->fresh()->status, $item->fresh()->attempts]);
        $this->runAttempts($item, 2);

        $this->assertSame([CreativePackageItem::STATUS_FAILED, 3], [$item->fresh()->status, $item->fresh()->attempts]);
    }

    public function test_started_at_is_written_by_the_first_claim_only(): void
    {
        $item = $this->oneItem();
        $this->fence->claim($item);
        CarbonImmutable::setTestNow($this->now()->addSeconds(CreativePackageContract::LEASE_SECONDS + 1));

        $this->fence->claim($item);

        $this->assertSame($this->now()->timestamp, $item->fresh()->started_at->timestamp);
    }

    public function test_a_run_that_throws_surrenders_its_lease_and_the_last_try_closes_the_cycle(): void
    {
        $item = $this->oneItem();
        $this->executor = fn () => throw new \RuntimeException('database went away');
        $job = new ProcessCreativePackageItem($item->id);

        foreach ([1, 2, 3] as $attempt) {
            try {
                app()->call([$job, 'handle']);
                $this->fail('The run swallowed the throwable.');
            } catch (\RuntimeException) {
            }
            $this->assertSame([CreativePackageItem::STATUS_RENDERING, $attempt, true], [$item->fresh()->status, $item->fresh()->attempts, $item->fresh()->lease_expires_at->lt($this->now())]);
        }
        $job->failed(new \RuntimeException('database went away'));

        $fresh = $item->fresh();
        $this->assertSame([CreativePackageItem::STATUS_FAILED, 'creative_package_attempts_exhausted', 3, null], [$fresh->status, $fresh->failure_code, $fresh->attempts, $fresh->lease_expires_at]);
    }

    public function test_the_failed_hook_leaves_an_item_under_a_live_lease_to_the_reconciler(): void
    {
        $item = $this->oneItem();
        $this->runAttempts($item, 3);
        CarbonImmutable::setTestNow($item->fresh()->lease_expires_at);
        $before = $item->fresh()->getAttributes();

        (new ProcessCreativePackageItem($item->id))->failed(new \RuntimeException('timed out'));

        $this->assertSame($before, $item->fresh()->getAttributes());
    }

    public function test_a_failed_surrender_is_logged_and_the_executor_throwable_still_escapes(): void
    {
        $item = $this->oneItem();
        $this->executor = fn () => throw new \DomainException('renderer crashed');
        $this->app->instance(CreativePackageItemFence::class, new class extends CreativePackageItemFence
        {
            public function guard(CreativePackageItemLease $lease, array $attributes = [], ?CarbonInterface $now = null): void
            {
                throw new \RuntimeException('lost connection');
            }
        });
        Log::spy();

        try {
            app()->call([new ProcessCreativePackageItem($item->id), 'handle']);
            $this->fail('The run swallowed the throwable.');
        } catch (\Throwable $escaped) {
        }

        $this->assertSame([CreativePackageAttemptFailed::class, false], [$escaped::class, str_contains($escaped->getMessage(), 'renderer crashed')]);
        $this->assertNull($escaped->getPrevious());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'creative_package_item.surrender_failed'
            && array_keys($context) === ['item_id', 'correlation_id', 'exception_class']
            && [$context['item_id'], $context['exception_class'], Str::isUuid($context['correlation_id'])] === [$item->id, \RuntimeException::class, true])->once();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'creative_package_item.attempt_failed'
            && array_keys($context) === ['item_id', 'correlation_id', 'exception_class']
            && [$context['item_id'], $context['exception_class'], Str::isUuid($context['correlation_id'])] === [$item->id, \DomainException::class, true])->once();
    }

    public function test_the_redis_retry_window_is_longer_than_the_attempt_timeout(): void
    {
        $this->assertGreaterThan((new ProcessCreativePackageItem(1))->timeout, config('queue.connections.redis.retry_after'));
    }

    public function test_the_job_declares_the_contract_timeout_tries_and_backoff(): void
    {
        $job = new ProcessCreativePackageItem(1);
        $this->assertSame([CreativePackageContract::ATTEMPT_TIMEOUT_SECONDS, CreativePackageContract::MAX_ATTEMPTS, [5, 15, 45]], [$job->timeout, $job->tries, $job->backoff()]);

        config(['creative-packages.limits.attempt_timeout_seconds' => 60, 'creative-packages.limits.max_attempts' => 9]);

        $this->assertSame([60, 3], [(new ProcessCreativePackageItem(1))->timeout, (new ProcessCreativePackageItem(1))->tries]);
    }

    /** Run the job once per lease window. Each run claims, or finds no claim and closes a spent cycle. */
    private function runAttempts(CreativePackageItem $item, int $runs): void
    {
        foreach (range(1, $runs) as $run) {
            app()->call([new ProcessCreativePackageItem($item->id), 'handle']);
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(CreativePackageContract::LEASE_SECONDS + 1));
        }
    }

    private function assertLeaseRenewedAt(CreativePackageItem $item, CarbonImmutable $renewedAt): void
    {
        $this->assertSame($renewedAt->addSeconds(CreativePackageContract::LEASE_SECONDS)->timestamp, $item->fresh()->lease_expires_at->timestamp);
    }
}
