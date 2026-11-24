<?php

namespace Tests\Feature;

use App\Contracts\SnapRenderer;
use App\Models\ApiLog;
use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use App\Services\CreativePackageDispatcher;
use App\Services\CreativePackageItemFence;
use App\Services\CreativePackageReconciler;
use App\Support\CreativePackageContract;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;
use Tests\Support\BuildsCreativePackages;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

/**
 * The queue drops a job on a crash between commit and dispatch and on a
 * killed worker. A reconciler nothing runs repairs nothing, so this pins the
 * command and its schedule entry, not the server cron.
 */
class ReconcileCreativePackagesTest extends TestCase
{
    use BuildsCreativePackages;
    use RefreshDatabase;

    private TestHandler $logs;

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
        $this->logs = new TestHandler;
        Log::getLogger()->pushHandler($this->logs);
    }

    public function test_the_command_runs_one_pass_and_prints_every_count(): void
    {
        $run = $this->artisan('creative-packages:reconcile')->assertExitCode(0);
        foreach (CreativePackageReconciler::ACTIONS as $action) {
            $run->expectsOutputToContain("{$action}: 0");
        }
    }

    /** One package the claim committed and never dispatched, one item whose worker died after its lease. Both render once. */
    public function test_the_command_repairs_committed_undispatched_and_expired_lease_work_and_logs_the_counts(): void
    {
        $actor = $this->member();
        $undispatched = $this->claim($actor, $this->request('undispatched', [$this->source20($actor)]))->package;
        $expired = $this->claim($actor, $this->request('expired', [$this->source20($actor)]))->package;
        app(CreativePackageItemFence::class)->claim($expired->items()->sole());
        CarbonImmutable::setTestNow($this->now()->addSeconds(CreativePackageContract::LEASE_SECONDS + 1));

        $this->artisan('creative-packages:reconcile')->assertExitCode(0)
            ->expectsOutput('pending_redispatched: 1')->expectsOutput('rendering_redispatched: 1');

        $this->assertSame([CreativePackage::STATUS_READY, CreativePackage::STATUS_READY, 2, 2], [$undispatched->fresh()->status, $expired->fresh()->status, CreativePackageItem::query()->where('status', CreativePackageItem::STATUS_READY)->count(), ApiLog::count()]);
        $lines = array_values(array_filter($this->logs->getRecords(), fn ($record) => $record['message'] === 'creative_packages.reconciled'));
        $this->assertCount(1, $lines);
        $this->assertSame(['pending_redispatched' => 1, 'rendering_redispatched' => 1, 'failed_redispatched' => 0, 'exhausted' => 0, 'waiting' => 0, 'packages_renewed' => 0, 'packages_recounted' => 0], $lines[0]['context']);
    }

    public function test_the_command_is_scheduled_every_minute_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'creative-packages:reconcile'));

        $this->assertCount(1, $events, 'creative-packages:reconcile is not scheduled.');
        $this->assertSame(['* * * * *', true], [$events->first()->expression, $events->first()->withoutOverlapping]);
    }
}
