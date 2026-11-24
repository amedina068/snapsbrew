<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReportsCreativePackageCounts;
use App\Services\CreativePackageRetention;
use Illuminate\Console\Command;

/**
 * Deletes creative packages past their replay window. The window is what the
 * MCP workflow promises: a key replays its package for `REPLAY_DAYS`, and after
 * that the same key can claim a new package. This command is what makes the
 * second half true. It runs only when the server cron calls `schedule:run`.
 */
class PruneCreativePackages extends Command
{
    use ReportsCreativePackageCounts;

    protected $signature = 'creative-packages:prune';

    protected $description = 'Delete creative packages past the replay window and release their keys.';

    public function handle(CreativePackageRetention $retention): int
    {
        $this->reportCounts('creative_packages.pruned', $retention->prunePackages());

        return self::SUCCESS;
    }
}
