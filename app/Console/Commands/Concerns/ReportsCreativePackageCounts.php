<?php

namespace App\Console\Commands\Concerns;

use App\Support\CreativePackageContract;
use Illuminate\Support\Facades\Log;

/**
 * Prints the counts of one pass and logs them as one structured line. The
 * source test that reads every log call on the package path cannot read a
 * call with a variable context, so this method checks the keys itself and
 * refuses a count outside `CreativePackageContract::LOG_FIELDS`.
 */
trait ReportsCreativePackageCounts
{
    /** @param array<string, int> $counts */
    private function reportCounts(string $event, array $counts): void
    {
        $unknown = array_diff(array_keys($counts), CreativePackageContract::LOG_FIELDS);
        if ($unknown !== []) {
            throw new \LogicException('The count key `'.implode('`, `', $unknown).'` is not in CreativePackageContract::LOG_FIELDS.');
        }
        foreach ($counts as $name => $count) {
            $this->line("{$name}: {$count}");
        }
        Log::info($event, $counts);
    }
}
