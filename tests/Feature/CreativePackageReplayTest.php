<?php

namespace Tests\Feature;

use App\Jobs\ProcessCreativePackageItem;
use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use App\Models\User;
use App\Services\CreativePackageDispatcher;
use App\Support\CreativePackageContract;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsCreativePackages;
use Tests\TestCase;

/**
 * A same-key replay is a read first and a redispatch second. The second
 * step runs only for an entitled member and only for the items the
 * reconciler calls reclaimable. The claim itself dispatches nothing on a
 * replay, so every job here comes from the resume step.
 */
class CreativePackageReplayTest extends TestCase
{
    use BuildsCreativePackages;
    use RefreshDatabase;

    private User $actor;

    private array $request;

    private CreativePackage $package;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow($this->now());
        $this->recordDispatches();
        $this->actor = $this->member();
        $this->request = $this->request('key', [$this->source($this->actor, 1), $this->source($this->actor, 2)]);
        $this->package = $this->claim($this->actor, $this->request)->package;
    }

    public function test_a_replay_redispatches_the_committed_pending_items_that_have_no_lease(): void
    {
        [$first, $second] = $this->items();
        $second->update(['status' => CreativePackageItem::STATUS_RENDERING, 'lease_expires_at' => $this->now()->addMinute()]);

        $replay = $this->claim($this->actor, $this->request);

        $this->assertTrue($replay->replayed);
        $this->assertPushed([$first->id]);
        $this->assertSame(1, count($this->dispatches));
    }

    public function test_a_replay_reopens_an_attempts_exhausted_item_that_the_reconciler_leaves_alone(): void
    {
        [$first, $second] = $this->items();
        $first->update(['status' => CreativePackageItem::STATUS_FAILED, 'attempts' => CreativePackageContract::MAX_ATTEMPTS, 'failure_code' => 'creative_package_attempts_exhausted', 'lease_expires_at' => null]);
        $second->update(['status' => CreativePackageItem::STATUS_READY, 'retryable' => false]);

        $this->claim($this->actor, $this->request);

        $this->assertPushed([$first->id]);
        $this->assertSame([CreativePackageItem::STATUS_FAILED, 0], [$first->fresh()->status, $first->fresh()->attempts]);
    }

    public function test_a_replay_redispatches_a_failed_item_with_a_code_the_reconciler_does_not_know(): void
    {
        [$first, $second] = $this->items();
        $first->update(['status' => CreativePackageItem::STATUS_FAILED, 'attempts' => 1, 'failure_code' => 'creative_package_future_code', 'lease_expires_at' => null]);
        $second->update(['status' => CreativePackageItem::STATUS_READY, 'retryable' => false]);

        $this->claim($this->actor, $this->request);

        $this->assertPushed([$first->id]);
        $this->assertSame(0, $first->fresh()->attempts);
    }

    public function test_a_replay_whose_redispatch_throws_still_returns_the_package_and_logs_the_failure(): void
    {
        $this->app->instance(CreativePackageDispatcher::class, new class extends CreativePackageDispatcher
        {
            public function dispatchItems(iterable $itemIds): void
            {
                throw new \RuntimeException('queue down');
            }
        });
        Log::spy();

        $replay = $this->claim($this->actor, $this->request);

        $this->assertSame([true, $this->package->id], [$replay->replayed, $replay->package->id]);
        Log::shouldHaveReceived('warning')->once()->with('creative_package.resume_failed', ['package_id' => $this->package->id, 'exception_class' => \RuntimeException::class]);
    }

    public function test_a_replay_after_entitlement_loss_returns_the_state_and_dispatches_nothing(): void
    {
        User::query()->whereKey($this->actor->id)->update(['role' => 'viewer']);

        $replay = $this->claim($this->actor, $this->request);

        $this->assertSame([true, $this->package->id], [$replay->replayed, $replay->package->id]);
        Queue::assertNothingPushed();
    }

    public function test_a_replay_of_a_ready_or_permanently_failed_package_dispatches_nothing(): void
    {
        [$first, $second] = $this->items();
        $first->update(['status' => CreativePackageItem::STATUS_READY, 'retryable' => false]);
        $second->update(['status' => CreativePackageItem::STATUS_READY, 'retryable' => false]);
        $this->package->update(['status' => CreativePackage::STATUS_READY]);
        $this->claim($this->actor, $this->request);

        $second->update(['status' => CreativePackageItem::STATUS_FAILED, 'retryable' => false, 'failure_code' => 'creative_package_render_failed', 'lease_expires_at' => null]);
        $this->package->update(['status' => CreativePackage::STATUS_FAILED]);
        $replay = $this->claim($this->actor, $this->request);

        $this->assertTrue($replay->replayed);
        Queue::assertNothingPushed();
    }

    /** @return array<int, CreativePackageItem> */
    private function items(): array
    {
        return $this->package->items()->orderBy('position')->get()->all();
    }

    /** @param array<int, int> $itemIds */
    private function assertPushed(array $itemIds): void
    {
        $this->assertSame($itemIds, Queue::pushed(ProcessCreativePackageItem::class)->map(fn ($job) => $job->itemId)->all());
    }
}
