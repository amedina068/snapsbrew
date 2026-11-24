<?php

namespace App\Services;

use App\Jobs\ProcessCreativePackageItem;
use App\Models\CreativePackage;
use App\Models\CreativePackageItem;

/**
 * Starts item work for a committed package: one job per pending item, in
 * position order. The claim calls this seam only after its transaction
 * commits, so a job never reads a row before it is visible.
 */
class CreativePackageDispatcher
{
    public function dispatch(CreativePackage $package): void
    {
        $this->dispatchItems($package->items()->where('status', CreativePackageItem::STATUS_PENDING)->pluck('id'));
    }

    /**
     * One job per item id. The reconciler and a same-key replay call this for
     * the items they reclaim, outside any transaction. A caller passes ids it
     * read under its own tenant scope. The job carries no tenant check of its
     * own beyond the item row.
     *
     * @param  iterable<int>  $itemIds
     */
    public function dispatchItems(iterable $itemIds): void
    {
        foreach ($itemIds as $itemId) {
            ProcessCreativePackageItem::dispatch($itemId);
        }
    }
}
