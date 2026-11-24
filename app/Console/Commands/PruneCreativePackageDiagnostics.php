<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReportsCreativePackageCounts;
use App\Services\CreativePackageRetention;
use Illuminate\Console\Command;

/**
 * Clears the hidden diagnostic of every item past its diagnostic window. The
 * public failure columns stay, so the package still reads the same to its
 * owner. It runs only when the server cron calls `schedule:run`.
 */
class PruneCreativePackageDiagnostics extends Command
{
    use ReportsCreativePackageCounts;

    protected $signature = 'creative-packages:prune-diagnostics';

    protected $description = 'Clear item diagnostics past the diagnostic window.';

    public function handle(CreativePackageRetention $retention): int
    {
        $this->reportCounts('creative_package_diagnostics.pruned', $retention->clearDiagnostics());

        return self::SUCCESS;
    }
}
