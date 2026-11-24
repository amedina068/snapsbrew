<?php

namespace Tests\Feature;

use App\Models\ApiLog;
use App\Models\CreativePackageItem;
use App\Services\CreativePackageItemFence;
use App\Services\CreativePackageItemLease;
use App\Services\StaleFenceException;
use App\Services\UsageRecorder;
use App\Support\CreativePackageContract;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCreativePackages;
use Tests\TestCase;

/**
 * The fence is the one guard between a worker and every later side effect.
 * A claim wins or returns null. A guarded write needs the live token, the
 * live generation and an unexpired lease, or it throws.
 */
class CreativePackageItemFenceTest extends TestCase
{
    use BuildsCreativePackages;
    use RefreshDatabase;

    private CreativePackageItemFence $fence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fence = app(CreativePackageItemFence::class);
        CarbonImmutable::setTestNow($this->now());
    }

    public function test_one_worker_claims_a_pending_item_and_the_second_worker_gets_null(): void
    {
        $item = $this->oneItem();

        $lease = $this->fence->claim($item);
        $second = $this->fence->claim($item);

        $this->assertInstanceOf(CreativePackageItemLease::class, $lease);
        $this->assertNull($second);
        $fresh = $item->fresh();
        $this->assertSame([$item->id, $fresh->lease_token, 1], [$lease->itemId, $lease->token, $lease->generation]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $lease->token);
        $this->assertSame([CreativePackageItem::STATUS_RENDERING, 1, 1, $this->now()->timestamp, $this->now()->addSeconds(CreativePackageContract::LEASE_SECONDS)->timestamp], [$fresh->status, $fresh->attempts, $fresh->lease_generation, $fresh->started_at->timestamp, $fresh->lease_expires_at->timestamp]);
    }

    public function test_the_lease_length_honors_config_below_the_contract_ceiling(): void
    {
        config(['creative-packages.limits.lease_seconds' => 60]);
        $item = $this->oneItem();

        $this->fence->claim($item);

        $this->assertSame($this->now()->addSeconds(60)->timestamp, $item->fresh()->lease_expires_at->timestamp);
    }

    public function test_a_retryable_failed_item_is_claimed_with_the_next_generation_and_attempt(): void
    {
        $item = $this->oneItem();
        $item->update(['status' => CreativePackageItem::STATUS_FAILED, 'retryable' => true, 'attempts' => 1, 'lease_generation' => 1, 'lease_token' => str_repeat('a', 64), 'lease_expires_at' => $this->now()->subMinute()]);

        $lease = $this->fence->claim($item);

        $this->assertSame(2, $lease->generation);
        $this->assertSame([CreativePackageItem::STATUS_RENDERING, 2], [$item->fresh()->status, $item->fresh()->attempts]);
    }

    #[DataProvider('terminalStates')]
    public function test_a_terminal_item_is_never_claimed(callable $state): void
    {
        $item = $this->oneItem();
        $state($item);
        $before = $item->fresh()->getAttributes();

        $this->assertNull($this->fence->claim($item));
        $this->assertSame($before, $item->fresh()->getAttributes());
    }

    public static function terminalStates(): array
    {
        return [
            'ready' => [fn (CreativePackageItem $item) => $item->update(['status' => CreativePackageItem::STATUS_READY, 'retryable' => false])],
            'permanent failed' => [fn (CreativePackageItem $item) => $item->update(['status' => CreativePackageItem::STATUS_FAILED, 'retryable' => false])],
        ];
    }

    public function test_a_stale_token_or_a_moved_generation_cannot_write(): void
    {
        $item = $this->oneItem();
        $lease = $this->fence->claim($item);

        foreach ([new CreativePackageItemLease($item->id, str_repeat('b', 64), 1), new CreativePackageItemLease($item->id, $lease->token, 2)] as $stale) {
            try {
                $this->fence->guard($stale, ['snap_id' => null]);
                $this->fail('A stale fence wrote.');
            } catch (StaleFenceException $exception) {
                $this->assertSame($item->id, $exception->itemId);
            }
        }
        $this->fence->guard($lease, ['started_at' => $this->now()->addSecond()]);
        $this->assertSame($this->now()->addSecond()->timestamp, $item->fresh()->started_at->timestamp);
    }

    public function test_a_fenced_write_may_not_set_a_status_other_than_ready_or_failed(): void
    {
        $item = $this->oneItem();
        $lease = $this->fence->claim($item);
        $before = $item->fresh()->getAttributes();

        try {
            $this->fence->guard($lease, ['status' => CreativePackageItem::STATUS_PENDING, 'attempts' => 9]);
            $this->fail('A fenced write set status to pending.');
        } catch (\LogicException) {
        }

        $this->assertSame($before, $item->fresh()->getAttributes());
    }

    /** MySQL counts changed rows, not matched rows. A live fence that repeats the stored values is still live. */
    public function test_a_guard_that_repeats_the_stored_values_is_still_live(): void
    {
        $item = $this->oneItem();
        $lease = $this->fence->claim($item);

        $this->fence->guard($lease, ['attempts' => 1, 'started_at' => $this->now()]);
        $this->fence->guard($lease, []);

        $this->assertSame(1, $item->fresh()->attempts);
    }

    public function test_the_lease_is_live_up_to_and_including_its_expiry_and_dead_after_it(): void
    {
        $item = $this->oneItem();
        $lease = $this->fence->claim($item);
        $expiresAt = $this->now()->addSeconds(CreativePackageContract::LEASE_SECONDS);

        CarbonImmutable::setTestNow($expiresAt->subSecond());
        $this->fence->guard($lease, ['attempts' => 1]);
        CarbonImmutable::setTestNow($expiresAt);
        $this->fence->guard($lease, ['attempts' => 1]);
        $this->assertNull($this->fence->claim($item));
        CarbonImmutable::setTestNow($expiresAt->addSecond());
        $this->expectException(StaleFenceException::class);
        $this->fence->guard($lease, ['attempts' => 1]);
    }

    public function test_a_claim_after_expiry_transfers_the_lease_and_the_first_fence_fails(): void
    {
        $item = $this->oneItem();
        $first = $this->fence->claim($item);
        CarbonImmutable::setTestNow($this->now()->addSeconds(CreativePackageContract::LEASE_SECONDS + 1));

        $second = $this->fence->claim($item);

        $this->assertSame([2, 2], [$second->generation, $item->fresh()->attempts]);
        $this->assertNotSame($first->token, $second->token);
        $this->fence->guard($second, ['attempts' => 2]);
        $this->expectException(StaleFenceException::class);
        $this->fence->guard($first, ['attempts' => 2]);
    }

    #[DataProvider('terminalTransitions')]
    public function test_a_fenced_terminal_write_is_final_even_for_the_live_fence(array $terminal): void
    {
        $item = $this->oneItem();
        $lease = $this->fence->claim($item);

        $this->fence->guard($lease, $terminal);

        $this->assertSame($terminal['status'], $item->fresh()->status);
        $this->assertNull($this->fence->claim($item));
        $this->expectException(StaleFenceException::class);
        $this->fence->guard($lease, ['attempts' => 9]);
    }

    public static function terminalTransitions(): array
    {
        return [
            'ready' => [['status' => CreativePackageItem::STATUS_READY, 'retryable' => false]],
            'permanent failed' => [['status' => CreativePackageItem::STATUS_FAILED, 'retryable' => false]],
        ];
    }

    /**
     * The usage recorder writes a row for any item without an api_log_id, so
     * the fence is the only guard. A worker that lost its lease fails at the
     * fence before it can record usage.
     */
    public function test_a_stale_fence_cannot_advance_an_item_whose_usage_is_not_recorded(): void
    {
        $item = $this->oneItem();
        $first = $this->fence->claim($item);
        CarbonImmutable::setTestNow($this->now()->addSeconds(CreativePackageContract::LEASE_SECONDS + 1));
        $this->fence->claim($item);
        $this->assertNull($item->fresh()->api_log_id);

        try {
            $this->fence->guard($first, ['status' => CreativePackageItem::STATUS_READY]);
            $this->fail('A stale fence advanced the item.');
        } catch (StaleFenceException) {
        }

        $this->assertSame([CreativePackageItem::STATUS_RENDERING, null, 0], [$item->fresh()->status, $item->fresh()->api_log_id, ApiLog::count()]);
        // The recorder alone would have written the row. Only the fence stopped the stale worker.
        $recorded = UsageRecorder::recordPackageItem($item);
        $this->assertSame(ApiLog::query()->sole()->id, $recorded->api_log_id);
    }
}
