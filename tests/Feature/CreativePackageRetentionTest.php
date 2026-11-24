<?php

namespace Tests\Feature;

use App\Contracts\SnapRenderer;
use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use App\Models\Snap;
use App\Models\User;
use App\Services\CreativePackageDispatcher;
use App\Services\CreativePackageReconciler;
use App\Services\CreativePackageRetention;
use App\Support\CreativePackageContract;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCreativePackages;
use Tests\Support\ExplainsCapturedQueries;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

/**
 * The replay window and the diagnostic window are promises the contract
 * makes. The prune passes are what make them true: after `REPLAY_DAYS` the
 * same key claims a new package, and after `DIAGNOSTIC_DAYS` the hidden
 * diagnostic is gone while the public failure stays.
 */
class CreativePackageRetentionTest extends TestCase
{
    use BuildsCreativePackages;
    use ExplainsCapturedQueries;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow($this->now());
        Storage::fake('public');
        $this->app->instance(CreativePackageDispatcher::class, new class extends CreativePackageDispatcher
        {
            public function dispatch(CreativePackage $package): void {}
        });
        $this->app->instance(SnapRenderer::class, new FakeRenderer);
        $this->recordQueries();
        $this->recordCapturedQueries();
    }

    public function test_a_key_replays_until_the_window_closes_and_claims_a_new_package_once_the_prune_ran(): void
    {
        $actor = $this->member();
        $package = $this->readyPackage($actor, 'key');
        $snap = $package->items()->sole()->snap;
        $this->assertNotNull($snap->getFirstMedia('image'));
        $other = $this->request('key', [$this->source20($actor)]);

        CarbonImmutable::setTestNow($this->now()->addDays(CreativePackageContract::REPLAY_DAYS));
        $this->assertSame('creative_package_idempotency_conflict', $this->rejected($actor, $other)->codeName);
        $this->assertSame(['scanned' => 0, 'deleted' => 0, 'released' => 0], app(CreativePackageRetention::class)->prunePackages());
        $this->assertSame('creative_package_idempotency_conflict', $this->rejected($actor, $other)->codeName);

        CarbonImmutable::setTestNow($this->now()->addDays(CreativePackageContract::REPLAY_DAYS)->addSecond());
        $this->assertSame(['scanned' => 1, 'deleted' => 1, 'released' => 1], app(CreativePackageRetention::class)->prunePackages());

        $this->assertSame([0, 0], [CreativePackage::count(), CreativePackageItem::count()]);
        // The snap follows the retention of every other snap. The dashboard still lists it as a paid render.
        $this->assertSame([Snap::STATUS_COMPLETED, true], [$snap->fresh()->status, $snap->fresh()->getFirstMedia('image') !== null]);
        $claimed = $this->claim($actor, $other);
        $this->assertSame([false, 'key'], [$claimed->replayed, $claimed->package->idempotency_key]);
        $this->assertNotSame($package->id, $claimed->package->id);
    }

    /** A pass that dies after the item delete leaves the package row and the key. The next pass finishes the package. */
    public function test_the_key_is_held_until_the_package_row_goes_and_an_interrupted_pass_is_finished_by_the_next_one(): void
    {
        $actor = $this->member();
        $package = $this->readyPackage($actor, 'key');
        $other = $this->request('key', [$this->source20($actor)]);
        CarbonImmutable::setTestNow($this->now()->addDays(CreativePackageContract::REPLAY_DAYS)->addSecond());
        $interrupted = new class extends CreativePackageRetention
        {
            protected function deleteItems(CreativePackage $package): void
            {
                parent::deleteItems($package);
                throw new \RuntimeException('killed after the item delete');
            }
        };

        try {
            $interrupted->prunePackages();
            $this->fail('The pass was not interrupted.');
        } catch (\RuntimeException) {
        }

        $this->assertSame([0, 1], [CreativePackageItem::count(), CreativePackage::query()->whereKey($package->id)->count()]);
        $this->assertSame('creative_package_idempotency_conflict', $this->rejected($actor, $other)->codeName);
        $this->assertSame(['scanned' => 1, 'deleted' => 1, 'released' => 1], app(CreativePackageRetention::class)->prunePackages());
        $this->assertSame([0, 0], [CreativePackageItem::count(), CreativePackage::count()]);
        $this->assertFalse($this->claim($actor, $other)->replayed);
    }

    public function test_each_page_seeks_past_the_last_row_and_a_pass_stops_at_its_limit(): void
    {
        config(['creative-packages.limits.retention_page' => 1]);
        $expired = $this->now()->subSecond();
        $packages = CreativePackage::factory()->count(3)->create(['replay_expires_at' => $expired]);
        CreativePackage::factory()->create(['replay_expires_at' => $this->now()]);

        $this->assertSame(['scanned' => 2, 'deleted' => 2, 'released' => 2], app(CreativePackageRetention::class)->prunePackages(limit: 2));
        $this->assertSame([$packages[2]->id], CreativePackage::query()->where('replay_expires_at', $expired)->pluck('id')->all());
        $this->assertSame(['scanned' => 1, 'deleted' => 1, 'released' => 1], app(CreativePackageRetention::class)->prunePackages());

        $this->assertSame(1, CreativePackage::count());
    }

    /** `limit()` clamps at its ceiling, so a configured value above the default takes effect up to the ceiling. */
    public function test_a_configured_page_above_the_default_is_honored_up_to_the_ceiling(): void
    {
        config(['creative-packages.limits.retention_page' => CreativePackageRetention::MAX_PAGE + 1, 'creative-packages.limits.retention_limit' => CreativePackageRetention::MAX_LIMIT + 1]);
        CreativePackage::factory()->count(CreativePackageRetention::PAGE + 1)->create(['replay_expires_at' => $this->now()->subSecond()]);

        app(CreativePackageRetention::class)->prunePackages();

        $selects = array_values(array_filter($this->sql, fn (string $query) => str_starts_with($query, 'select * from') && str_contains($query, 'replay_expires_at')));
        $this->assertCount(1, $selects, 'One page above the default holds every row, and a short page ends the pass.');
        $this->assertStringContainsString('limit '.CreativePackageRetention::MAX_PAGE, $selects[0]);
        $this->assertSame(0, CreativePackage::count());
    }

    public function test_the_diagnostic_clear_keeps_an_item_before_expiry_and_clears_at_and_after_without_touching_the_failure_columns(): void
    {
        $before = $this->failedItem($this->now()->addSecond());
        $at = $this->failedItem($this->now());
        $after = $this->failedItem($this->now()->subSecond());

        $this->assertSame(['scanned' => 2, 'cleared' => 2], app(CreativePackageRetention::class)->clearDiagnostics());

        $this->assertSame(['creative_package_render_failed', \RuntimeException::class, 'corr', str_repeat('f', 64), $this->now()->addSecond()->toDateTimeString()], array_values($before->fresh()->diagnostics()));
        $this->assertSame([null, null, null, null, null], array_values($at->fresh()->diagnostics()));
        $this->assertSame([null, null, null, null, null], array_values($after->fresh()->diagnostics()));
        foreach ([$before, $at, $after] as $item) {
            $this->assertSame(['failed', 'creative_package_render_failed', 'The renderer did not produce an image.', false], [$item->fresh()->status, $item->fresh()->failure_code, $item->fresh()->failure_details, $item->fresh()->retryable]);
        }
        $this->assertSame(['scanned' => 0, 'cleared' => 0], app(CreativePackageRetention::class)->clearDiagnostics());
    }

    public function test_the_package_seek_uses_the_replay_cleanup_index_on_every_page_on_mysql(): void
    {
        $this->requireMysql();
        config(['creative-packages.limits.retention_page' => 1]);
        CreativePackage::factory()->count(2)->create(['replay_expires_at' => $this->now()->subSecond()]);
        CreativePackage::factory()->create();

        $plans = $this->explainQueries(fn () => app(CreativePackageRetention::class)->prunePackages(), seek: 'select * from `creative_packages` where `replay_expires_at` < ?')['seek'];

        // Two rows at one row per page: two pages with a row and a third seek that reads none.
        $this->assertCount(3, $plans);
        foreach ($plans as $seek) {
            $this->assertAccessPlan($seek['plan'][0], 'creative_packages_replay_cleanup_index', ['range'], 10);
        }
        $this->assertStringContainsString('`id` >', $plans[1]['sql']);
        $this->assertStringContainsString('`id` >', $plans[2]['sql']);
    }

    public function test_the_diagnostic_seek_uses_the_diagnostic_cleanup_index_on_mysql(): void
    {
        $this->requireMysql();
        $this->failedItem($this->now()->subSecond());
        $this->failedItem($this->now()->addDay());

        $plans = $this->explainQueries(fn () => app(CreativePackageRetention::class)->clearDiagnostics(), seek: 'select * from `creative_package_items` where `diagnostic_expires_at` <= ?')['seek'];

        $this->assertAccessPlan($plans[0]['plan'][0], 'creative_package_items_diagnostic_cleanup_index', ['range'], 10);
    }

    /** One claimed package with one rendered, recorded item and its media file. */
    private function readyPackage(User $actor, string $key): CreativePackage
    {
        $package = $this->claim($actor, $this->request($key, [$this->source20($actor)]))->package;
        app(CreativePackageReconciler::class)->reconcile(CarbonImmutable::now());
        $this->assertSame([CreativePackage::STATUS_READY, CreativePackageItem::STATUS_READY], [$package->fresh()->status, $package->items()->sole()->status]);

        return $package->fresh();
    }

    private function failedItem(CarbonImmutable $diagnosticExpiresAt): CreativePackageItem
    {
        $item = CreativePackageItem::factory()->failed()->create(['failure_details' => 'The renderer did not produce an image.']);
        $item->forceFill([
            'diagnostic_code' => 'creative_package_render_failed', 'diagnostic_exception_class' => \RuntimeException::class,
            'diagnostic_correlation_id' => 'corr', 'diagnostic_message_fingerprint' => str_repeat('f', 64), 'diagnostic_expires_at' => $diagnosticExpiresAt,
        ])->save();

        return $item;
    }
}
