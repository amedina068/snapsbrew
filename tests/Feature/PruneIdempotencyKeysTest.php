<?php

namespace Tests\Feature;

use App\Console\Commands\PruneIdempotencyKeys;
use App\Models\Company;
use App\Models\IdempotencyKey;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `ApiSpec.php` tells a client that a key is honored for 24 hours. A documented
 * window that nothing enforces is a false statement on a money path, so this
 * command is what makes that sentence true.
 */
class PruneIdempotencyKeysTest extends TestCase
{
    use RefreshDatabase;

    private function key(string $name, int $hoursOld): IdempotencyKey
    {
        $row = IdempotencyKey::create([
            'company_id' => Company::factory()->create()->id,
            'key' => $name,
            'status' => IdempotencyKey::STATUS_COMPLETED,
        ]);

        $row->forceFill(['created_at' => now()->subHours($hoursOld)])->save();

        return $row;
    }

    public function test_it_deletes_keys_past_the_window_and_keeps_the_rest()
    {
        $this->key('expired', 25);
        $this->key('fresh', 1);

        $this->artisan('snaps:prune-idempotency-keys')->assertExitCode(0);

        $this->assertDatabaseMissing('idempotency_keys', ['key' => 'expired']);
        $this->assertDatabaseHas('idempotency_keys', ['key' => 'fresh']);
    }

    public function test_it_keeps_a_key_that_is_exactly_inside_the_window()
    {
        $this->key('edge', PruneIdempotencyKeys::RETENTION_HOURS - 1);

        $this->artisan('snaps:prune-idempotency-keys')->assertExitCode(0);

        $this->assertDatabaseHas('idempotency_keys', ['key' => 'edge']);
    }

    public function test_it_runs_clean_when_there_is_nothing_to_delete()
    {
        $this->artisan('snaps:prune-idempotency-keys')->assertExitCode(0);

        $this->assertSame(0, IdempotencyKey::query()->count());
    }

    public function test_the_command_is_scheduled()
    {
        // Without a schedule entry the retention window is a promise nothing
        // keeps. This asserts the entry, not the server cron.
        $schedule = app(Schedule::class);

        $commands = collect($schedule->events())
            ->map(fn ($event) => $event->command)
            ->filter(fn ($command) => str_contains((string) $command, 'snaps:prune-idempotency-keys'));

        $this->assertNotEmpty($commands, 'snaps:prune-idempotency-keys is not scheduled.');
    }
}
