<?php

namespace App\Services;

use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use App\Support\CreativePackageContract;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Deletes what the retention windows no longer promise. A package is kept for
 * `REPLAY_DAYS` after its claim, and its idempotency key is reusable only
 * once the package row is gone. An item diagnostic is kept for
 * `DIAGNOSTIC_DAYS` after the failure.
 *
 * Each pass reads one page at a time by a tuple seek on (expires_at, id), so
 * the cleanup index serves every page and a row a pass cannot delete does not
 * make the next page read it again. A pass is bounded by `LIMIT` rows and is
 * resumable: every step is idempotent, so a crash between steps leaves a
 * package that the next pass finishes. A package that throws stops the pass
 * with that exception, and the next hourly pass resumes at the same row.
 *
 * The snap and its media stay: they follow the retention of every other snap
 * (PLAN section 6), and the dashboard lists that snap as a paid render.
 */
class CreativePackageRetention
{
    /** Rows one page reads by default, and the most a configured page can read. */
    public const PAGE = 100;

    public const MAX_PAGE = 1_000;

    /** Rows one pass touches by default, and the most a configured pass can touch. */
    public const LIMIT = 1_000;

    public const MAX_LIMIT = 10_000;

    /**
     * Delete the packages whose replay window closed. Per package the order
     * is the item rows, then the package row. The key is free only after the
     * last step.
     *
     * @return array{scanned: int, deleted: int, released: int}
     */
    public function prunePackages(?CarbonInterface $now = null, ?int $limit = null): array
    {
        $now ??= now();
        $counts = ['scanned' => 0, 'deleted' => 0, 'released' => 0];
        $this->pages(CreativePackage::query()->where('replay_expires_at', '<', $now), 'replay_expires_at', $limit, function (Collection $packages) use (&$counts) {
            foreach ($packages as $package) {
                $counts['scanned']++;
                $this->deleteItems($package);
                $deleted = $this->deletePackage($package);
                $counts['deleted'] += $deleted;
                $counts['released'] += $deleted;
            }
        });

        return $counts;
    }

    /**
     * Clear the hidden diagnostic columns of the items whose diagnostic window
     * closed, at or after `diagnostic_expires_at`. The public failure columns
     * stay.
     *
     * @return array{scanned: int, cleared: int}
     */
    public function clearDiagnostics(?CarbonInterface $now = null, ?int $limit = null): array
    {
        $now ??= now();
        $counts = ['scanned' => 0, 'cleared' => 0];
        $this->pages(CreativePackageItem::query()->where('diagnostic_expires_at', '<=', $now), 'diagnostic_expires_at', $limit, function (Collection $items) use (&$counts) {
            $counts['scanned'] += $items->count();
            $counts['cleared'] += CreativePackageItem::query()->whereIn('id', $items->pluck('id'))
                ->update(array_fill_keys(CreativePackageItem::DIAGNOSTIC_COLUMNS, null));
        });

        return $counts;
    }

    /**
     * Read the matching rows one page at a time, ordered by (column, id), and
     * seek past the last row of each page. Stops at the limit or at a short page.
     *
     * @param  \Closure(Collection): void  $handle
     */
    private function pages(Builder $query, string $column, ?int $limit, \Closure $handle): void
    {
        $limit ??= CreativePackageContract::limit('retention_limit', self::LIMIT, self::MAX_LIMIT);
        $page = min(CreativePackageContract::limit('retention_page', self::PAGE, self::MAX_PAGE), $limit);
        $last = null;
        for ($read = 0; $read < $limit; $read += $rows->count()) {
            $rows = (clone $query)->when($last, fn (Builder $seek) => $seek->where(fn (Builder $after) => $after
                ->where($column, '>', $last->{$column})
                ->orWhere(fn (Builder $tie) => $tie->where($column, $last->{$column})->where('id', '>', $last->id))))
                ->orderBy($column)->orderBy('id')->limit(min($page, $limit - $read))->get();
            if ($rows->isEmpty()) {
                return;
            }
            $handle($rows);
            $last = $rows->last();
            if ($rows->count() < $page) {
                return;
            }
        }
    }

    protected function deleteItems(CreativePackage $package): void
    {
        CreativePackageItem::query()->where('creative_package_id', $package->id)->delete();
    }

    /** The last step. The unique claim index frees the key when this row goes. */
    protected function deletePackage(CreativePackage $package): int
    {
        return CreativePackage::query()->whereKey($package->id)->delete();
    }
}
