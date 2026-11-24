<?php

namespace App\Services;

use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes the cached item counts and the package state from the item
 * rows. It locks the package row alone, so a caller must invoke it outside
 * any fence transaction.
 */
class CreativePackageAggregator
{
    public function recount(CreativePackage $package): void
    {
        DB::transaction(function () use ($package): void {
            $locked = CreativePackage::query()->whereKey($package->id)->lockForUpdate()->firstOrFail();
            $items = CreativePackageItem::query()->where('creative_package_id', $locked->id);
            $counts = (clone $items)->selectRaw('status, retryable, count(*) as items')->groupBy('status', 'retryable')->get();
            $count = fn (string $status, ?bool $retryable = null) => (int) $counts
                ->filter(fn ($row) => $row->status === $status && ($retryable === null || (bool) $row->retryable === $retryable))->sum('items');
            $pending = $count(CreativePackageItem::STATUS_PENDING);
            $rendering = $count(CreativePackageItem::STATUS_RENDERING);
            $ready = $count(CreativePackageItem::STATUS_READY);
            $failed = $count(CreativePackageItem::STATUS_FAILED);
            $status = match (true) {
                $pending + $rendering > 0 => CreativePackage::STATUS_BUILDING,
                $failed === 0 => CreativePackage::STATUS_READY,
                $ready === 0 && $count(CreativePackageItem::STATUS_FAILED, true) === 0 => CreativePackage::STATUS_FAILED,
                default => CreativePackage::STATUS_PARTIAL,
            };
            $locked->update([
                'status' => $status, 'pending_items_count' => $pending, 'rendering_items_count' => $rendering,
                'ready_items_count' => $ready, 'failed_items_count' => $failed,
                'completed_at' => $status === CreativePackage::STATUS_BUILDING ? null : ($locked->completed_at ?? now()),
            ]);
        });
    }
}
