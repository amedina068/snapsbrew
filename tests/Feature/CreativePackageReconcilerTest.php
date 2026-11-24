<?php

namespace Tests\Feature;

use App\Contracts\SnapRenderer;
use App\Models\ApiLog;
use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use App\Models\User;
use App\Services\CreativePackageDispatcher;
use App\Services\CreativePackageItemExecutor;
use App\Services\CreativePackageItemFence;
use App\Services\CreativePackageItemLease;
use App\Services\CreativePackageReconciler;
use App\Support\CreativePackageContract;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCreativePackages;
use Tests\Support\ExplainsCapturedQueries;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

/**
 * A fault at each durable boundary, repaired through reconcile() alone. The
 * claim dispatches nothing here, so every item starts as the commit-before-
 * dispatch crash, and the sync queue runs each redispatched job inline
 * against the real executor and a fake renderer.
 */
class CreativePackageReconcilerTest extends TestCase
{
    use BuildsCreativePackages;
    use ExplainsCapturedQueries;
    use RefreshDatabase;

    private FakeRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow($this->now());
        Storage::fake('public');
        $this->app->instance(CreativePackageDispatcher::class, new class extends CreativePackageDispatcher
        {
            public function dispatch(CreativePackage $package): void {}
        });
        $this->renderer = new FakeRenderer;
        $this->app->instance(SnapRenderer::class, $this->renderer);
        $this->recordQueries();
        $this->recordCapturedQueries();
    }

    public function test_a_crash_between_commit_and_dispatch_is_repaired_with_one_render_per_item(): void
    {
        $actor = $this->member();
        $package = $this->claim($actor, $this->request('key', [$this->source20($actor), $this->source20($actor)]))->package;
        $this->assertSame([2, 0], [$package->items()->where('status', CreativePackageItem::STATUS_PENDING)->count(), count($this->renderer->screenshotCalls)]);

        $counts = $this->reconcile();

        $this->assertSame(2, $counts['pending_redispatched']);
        $this->assertConverged($package, CreativePackage::STATUS_READY, renders: 2, usageRows: 2);
    }

    public function test_an_expired_lease_on_an_attempt_that_never_rendered_converges_with_one_render(): void
    {
        $item = $this->item();
        app(CreativePackageItemFence::class)->claim($item);
        $this->travelPastLease();

        $counts = $this->reconcile();

        $this->assertSame([1, 2], [$counts['rendering_redispatched'], $item->fresh()->attempts]);
        $this->assertConverged($item->package, CreativePackage::STATUS_READY, renders: 1, usageRows: 1);
    }

    public function test_an_attempt_killed_every_time_converges_to_partial_without_a_usage_row(): void
    {
        $item = $this->item();
        $this->app->instance(CreativePackageItemExecutor::class, new class extends CreativePackageItemExecutor
        {
            public function __construct() {}

            public function execute(CreativePackageItem $item, CreativePackageItemLease $lease, ?string $correlationId = null): void {}
        });

        $counts = [];
        foreach (range(1, CreativePackageContract::MAX_ATTEMPTS + 1) as $reclaim) {
            $counts[] = array_filter($this->reconcile());
            $this->travelPastLease();
        }

        $alive = ['rendering_redispatched' => 1, 'packages_renewed' => 1];
        $this->assertSame([['pending_redispatched' => 1], $alive, $alive, ['exhausted' => 1, 'waiting' => 1]], $counts);
        $this->assertSame(['waiting' => 1], array_filter($this->reconcile()));
        $this->assertSame([CreativePackageItem::STATUS_FAILED, true, 'creative_package_attempts_exhausted', 3], [$item->fresh()->status, $item->fresh()->retryable, $item->fresh()->failure_code, $item->fresh()->attempts]);
        $this->assertConverged($item->package, CreativePackage::STATUS_PARTIAL, renders: 0, usageRows: 0);
    }

    public function test_a_crash_after_the_media_write_and_before_the_usage_row_records_usage_once_without_a_render(): void
    {
        $item = $this->readyItem();
        $item->forceFill(['status' => CreativePackageItem::STATUS_RENDERING, 'retryable' => true, 'media_id' => null, 'completed_at' => null, 'lease_expires_at' => $this->now()->subSecond(), 'api_log_id' => null])->save();
        ApiLog::query()->delete();

        $this->reconcile();

        $this->assertSame([$item->snap_id, ApiLog::query()->sole()->id], [$item->fresh()->snap_id, $item->fresh()->api_log_id]);
        $this->assertConverged($item->package, CreativePackage::STATUS_READY, renders: 1, usageRows: 1);
    }

    public function test_a_crash_after_the_usage_row_and_before_the_ready_write_marks_the_item_ready_without_a_render(): void
    {
        $item = $this->readyItem();
        $item->forceFill(['status' => CreativePackageItem::STATUS_RENDERING, 'retryable' => true, 'completed_at' => null, 'lease_expires_at' => $this->now()->subSecond()])->save();

        $this->reconcile();

        $this->assertConverged($item->package, CreativePackage::STATUS_READY, renders: 1, usageRows: 1);
    }

    public function test_cached_count_drift_on_an_aged_building_package_is_recounted_to_its_terminal_state(): void
    {
        $item = $this->readyItem();
        $item->package->update(['status' => CreativePackage::STATUS_BUILDING, 'ready_items_count' => 0, 'pending_items_count' => 1, 'lease_expires_at' => $this->now()->subSecond()]);

        $counts = $this->reconcile();

        $this->assertSame(1, $counts['packages_recounted']);
        $this->assertConverged($item->package, CreativePackage::STATUS_READY, renders: 1, usageRows: 1);
    }

    public function test_an_aged_building_package_with_a_live_item_keeps_its_active_slot(): void
    {
        $item = $this->item();
        $item->package->update(['lease_expires_at' => $this->now()->subSecond()]);
        $item->update(['status' => CreativePackageItem::STATUS_RENDERING, 'lease_token' => str_repeat('c', 64), 'lease_generation' => 1, 'lease_expires_at' => $this->now()->addMinute()]);

        $counts = $this->reconcile();

        $this->assertSame([1, 0, $this->now()->addSeconds(CreativePackageContract::LEASE_SECONDS)->timestamp], [$counts['packages_renewed'], $counts['packages_recounted'], $item->package->fresh()->lease_expires_at->timestamp]);
        $this->assertSame([], $this->renderer->screenshotCalls);
    }

    public function test_an_entitlement_blocked_item_is_redispatched_only_after_entitlement_returns(): void
    {
        $item = $this->item();
        User::query()->whereKey($item->package->created_by)->update(['role' => 'viewer']);
        $this->reconcile();
        $this->assertSame('creative_package_entitlement_required', $item->fresh()->failure_code);
        $this->travelPastLease();

        $this->assertSame(['waiting' => 1], array_filter($this->reconcile()));
        User::query()->whereKey($item->package->created_by)->update(['role' => User::ROLE_CUSTOMER]);
        $this->assertSame(['failed_redispatched' => 1], array_filter($this->reconcile()));

        $this->assertConverged($item->package, CreativePackage::STATUS_READY, renders: 1, usageRows: 1);
    }

    public function test_each_scan_is_bounded_by_the_limit_and_the_next_call_takes_the_rest(): void
    {
        $actor = $this->member();
        $this->claim($actor, $this->request('key', array_map(fn () => $this->source20($actor), range(1, 8))));

        $first = $this->reconcile(limit: 3);
        $second = $this->reconcile(limit: 3);
        $third = $this->reconcile(limit: 3);

        $this->assertSame([3, 3, 2, 0], [$first['pending_redispatched'], $second['pending_redispatched'], $third['pending_redispatched'], $third['packages_recounted']]);
        $this->assertSame([8, 8], [count($this->renderer->screenshotCalls), ApiLog::count()]);
    }

    public function test_a_failed_item_with_a_code_the_reconciler_does_not_know_waits_for_a_replay(): void
    {
        $item = $this->item();
        $item->update(['status' => CreativePackageItem::STATUS_FAILED, 'failure_code' => 'creative_package_future_code', 'lease_expires_at' => null]);

        $this->assertSame(['waiting' => 1], array_filter($this->reconcile()));

        $this->assertSame([], $this->renderer->screenshotCalls);
    }

    public function test_the_failed_scan_loads_the_creator_and_the_company_in_one_query_each(): void
    {
        $actor = $this->member();
        $package = $this->claim($actor, $this->request('key', [$this->source20($actor), $this->source20($actor), $this->source20($actor)]))->package;
        User::query()->whereKey($actor->id)->update(['role' => 'viewer']);
        $this->reconcile();
        $this->travelPastLease();
        $this->sql = [];

        $this->assertSame(['waiting' => 3], array_filter($this->reconcile()));

        $this->assertSame([3, 1, 1], [$package->items()->where('failure_code', 'creative_package_entitlement_required')->count(), $this->queriesTouching('users'), $this->queriesTouching('companies')]);
    }

    /** T-073: a package past its replay window is hours from the prune. Redispatching its item would render work that is about to be deleted. */
    public function test_an_unowned_item_of_a_package_past_its_replay_window_is_neither_redispatched_nor_recorded(): void
    {
        $expired = $this->item();
        $expired->package->update(['replay_expires_at' => $this->now()]);
        $live = $this->item();

        $counts = $this->reconcile();

        $this->assertSame(['pending_redispatched' => 1], array_filter($counts));
        $this->assertSame([CreativePackageItem::STATUS_PENDING, CreativePackageItem::STATUS_READY, 1, 1], [$expired->fresh()->status, $live->fresh()->status, count($this->renderer->screenshotCalls), ApiLog::count()]);
    }

    public function test_the_item_scan_uses_the_reconciliation_index_on_mysql(): void
    {
        $this->requireMysql();
        $this->readyItem();
        $this->item()->update(['status' => CreativePackageItem::STATUS_FAILED, 'failure_code' => 'creative_package_entitlement_required', 'lease_expires_at' => null]);
        CreativePackageItem::factory()->rendering()->create();

        $scan = $this->explainQueries(fn () => $this->reconcile(), scan: 'select * from `creative_package_items` where `status` in (?)')['scan'][0];

        $this->assertAccessPlan(collect($scan['plan'])->firstWhere('table', 'creative_package_items'), 'creative_package_items_reconciliation_index', ['range'], 10);
        $this->assertStringContainsString('replay_expires_at', $scan['sql']);
    }

    public function test_the_package_scan_uses_the_package_reconciliation_index_on_mysql(): void
    {
        $this->requireMysql();
        $this->readyItem();
        CreativePackage::factory()->failed()->create();
        CreativePackage::factory()->create(['lease_expires_at' => $this->now()->subSecond()]);

        $scan = $this->explainQueries(fn () => $this->reconcile(), scan: 'select * from `creative_packages` where `status` = ?')['scan'][0];

        $this->assertAccessPlan($scan['plan'][0], 'creative_packages_reconciliation_index', ['range'], 10);
    }

    /** @return array<string, int> */
    private function reconcile(int $limit = 100): array
    {
        return app(CreativePackageReconciler::class)->reconcile(CarbonImmutable::now(), $limit);
    }

    private function travelPastLease(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(CreativePackageContract::LEASE_SECONDS + 1));
    }

    private function item(): CreativePackageItem
    {
        $actor = $this->member();

        return $this->claim($actor, $this->request('key', [$this->source20($actor)]))->package->items()->sole();
    }

    private function readyItem(): CreativePackageItem
    {
        $item = $this->item();
        $this->reconcile();
        $this->assertSame(CreativePackageItem::STATUS_READY, $item->fresh()->status);

        return $item->fresh();
    }

    /** Every recorded item points at its own usage row, so the item count with an api_log_id equals the row count. */
    private function assertConverged(CreativePackage $package, string $status, int $renders, int $usageRows): void
    {
        $fresh = $package->fresh();
        $this->assertSame([$status, 0, 0, $renders, $usageRows, $usageRows], [$fresh->status, $fresh->pending_items_count, $fresh->rendering_items_count, count($this->renderer->screenshotCalls), ApiLog::count(), $fresh->items()->whereNotNull('api_log_id')->count()]);
    }
}
