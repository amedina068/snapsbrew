<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReportsCreativePackageCounts;
use App\Services\CreativePackageReconciler;
use Illuminate\Console\Command;

/**
 * Runs one bounded reconciliation pass over incomplete creative packages.
 *
 * The queue drops a job on a crash between commit and dispatch, on a killed
 * worker and on a lapsed unique lock. This pass is what starts that work
 * again. It runs only when the server cron calls `schedule:run`.
 */
class ReconcileCreativePackages extends Command
{
    use ReportsCreativePackageCounts;

    protected $signature = 'creative-packages:reconcile';

    protected $description = 'Redispatch, exhaust or recount incomplete creative packages.';

    public function handle(CreativePackageReconciler $reconciler): int
    {
        $this->reportCounts('creative_packages.reconciled', $reconciler->reconcile());

        return self::SUCCESS;
    }
}
