<?php

namespace App\Services;

use App\Models\CreativePackageItem;
use Carbon\CarbonInterface;

/**
 * The attempt and heartbeat policy of one package item. The fence owns every
 * conditional write. This service orders the calls around it.
 */
class CreativePackageItemAttempts
{
    public function __construct(
        private CreativePackageItemFence $fence,
        private CreativePackageAggregator $aggregator,
    ) {}

    /**
     * Renew the lease. A stale fence throws. The lease is longer than the
     * attempt timeout, so a worker beats before and after the render and
     * needs no timer during the render.
     */
    public function heartbeat(CreativePackageItem $item, CreativePackageItemLease $lease, ?CarbonInterface $now = null): void
    {
        $now ??= now();
        $this->fence->renew($lease, $now);
    }

    /**
     * Close the cycle of an unowned item that spent every attempt: write the
     * retryable failure and recount the package. True
     * when this call closed it.
     */
    public function exhaustIfSpent(CreativePackageItem $item, ?CarbonInterface $now = null): bool
    {
        if (! $this->fence->exhaust($item, $now)) {
            return false;
        }
        $this->aggregator->recount($item->package);

        return true;
    }
}
