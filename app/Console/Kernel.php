<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // The entries run only if the server cron calls `schedule:run`.
        // onOneServer() takes its lock in the default cache. With more than one
        // server, that cache must be shared (Redis), or each server runs its own copy.
        // The prune enforces the 24-hour idempotency window that `ApiSpec.php` documents.
        // The reconcile restarts package items the queue dropped; the reconciler bounds each pass.
        $schedule->command('snaps:prune-idempotency-keys')->hourly();
        $schedule->command('creative-packages:reconcile')->everyMinute()->withoutOverlapping()->onOneServer();
        // The two prunes enforce the replay and diagnostic windows in `CreativePackageContract`; each pass is bounded.
        $schedule->command('creative-packages:prune')->hourly()->withoutOverlapping()->onOneServer();
        $schedule->command('creative-packages:prune-diagnostics')->hourly()->withoutOverlapping()->onOneServer();
        // Registration at /oauth/register takes no authentication. The prune deletes clients that got no token in 7 days.
        $schedule->command('oauth-clients:prune')->daily()->withoutOverlapping()->onOneServer();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
