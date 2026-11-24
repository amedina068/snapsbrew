<?php

namespace Tests\Feature;

use App\Console\Commands\Concerns\ReportsCreativePackageCounts;
use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * The two prune commands are what run the retention passes. This pins what
 * each one prints and logs, and its schedule entry, not the server cron.
 */
class PruneCreativePackagesTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-19 12:00:00'));
        $this->logs = new TestHandler;
        Log::getLogger()->pushHandler($this->logs);
    }

    public function test_the_prune_commands_print_their_counts_and_log_one_line_each(): void
    {
        CreativePackage::factory()->create(['replay_expires_at' => CarbonImmutable::now()->subSecond()]);
        $this->failedItem(CarbonImmutable::now()->subSecond());

        $this->artisan('creative-packages:prune')->assertExitCode(0)
            ->expectsOutput('scanned: 1')->expectsOutput('deleted: 1')->expectsOutput('released: 1');
        $this->artisan('creative-packages:prune-diagnostics')->assertExitCode(0)
            ->expectsOutput('scanned: 1')->expectsOutput('cleared: 1');

        $lines = array_map(fn ($record) => [$record['message'], $record['context']], $this->logs->getRecords());
        $this->assertContains(['creative_packages.pruned', ['scanned' => 1, 'deleted' => 1, 'released' => 1]], $lines);
        $this->assertContains(['creative_package_diagnostics.pruned', ['scanned' => 1, 'cleared' => 1]], $lines);
    }

    public function test_both_prune_commands_are_scheduled_hourly_without_overlapping(): void
    {
        foreach (['creative-packages:prune', 'creative-packages:prune-diagnostics'] as $command) {
            $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_ends_with((string) $event->command, ' '.$command));

            $this->assertCount(1, $events, "{$command} is not scheduled.");
            $this->assertSame(['0 * * * *', true], [$events->first()->expression, $events->first()->withoutOverlapping]);
        }
    }

    /** The source test cannot read a log call with a variable context, so the trait guards the keys itself. */
    public function test_a_count_key_outside_the_allowlist_is_refused_before_it_is_logged(): void
    {
        $command = new class extends Command
        {
            use ReportsCreativePackageCounts;

            public function report(): void
            {
                $this->reportCounts('creative_packages.probe', ['scanned' => 1, 'idempotency_key' => 1]);
            }
        };
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('idempotency_key');
        $command->report();
    }

    private function failedItem(CarbonImmutable $diagnosticExpiresAt): CreativePackageItem
    {
        $item = CreativePackageItem::factory()->failed()->create();
        $item->forceFill(['diagnostic_code' => 'creative_package_render_failed', 'diagnostic_expires_at' => $diagnosticExpiresAt])->save();

        return $item;
    }
}
