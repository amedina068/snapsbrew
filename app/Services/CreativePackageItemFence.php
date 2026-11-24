<?php

namespace App\Services;

use App\Models\CreativePackageItem;
use App\Support\CreativePackageContract;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Claims and fences one package item. A claim is one conditional UPDATE that
 * moves a pending, rendering or retryable failed item with no live lease to
 * `rendering` under a fresh token and the next generation. A ready item and a
 * permanently failed item match no claim and no guard. Every later write goes
 * through guard(), which is one conditional UPDATE keyed on the item, the
 * token, the generation, an unexpired lease and the `rendering` status.
 *
 * A lease is live up to and including `lease_expires_at`. Claim reclaims
 * only after that second. Guard accepts it up to that second.
 *
 * Attempts are bounded. A claim refuses an item whose `attempts` already
 * reached MAX_ATTEMPTS, and exhaust() moves such an unowned pending or
 * rendering item to a retryable failed state. A failed row never matches
 * exhaust(), so a second call is a no-op read. A replay opens one new cycle
 * with reopen(), which locks a retryable failed row and resets `attempts`
 * to zero before the replay redispatches. `started_at` is write-once: the
 * first claim sets it and a later claim keeps it.
 *
 * The fence is the only guard between a worker and the usage row. A worker
 * must pass guard() with its live lease before it records usage, and must
 * write the terminal state through guard() after. The item keeps its
 * api_log_id, so a record that lands after the lease moved writes no second
 * row. The terminal write then fails at the fence.
 */
class CreativePackageItemFence
{
    private const GUARDED_STATUSES = [CreativePackageItem::STATUS_READY, CreativePackageItem::STATUS_FAILED];

    private const CLAIMABLE_STATUSES = [CreativePackageItem::STATUS_PENDING, CreativePackageItem::STATUS_RENDERING, CreativePackageItem::STATUS_FAILED];

    private const EXHAUSTIBLE_STATUSES = [CreativePackageItem::STATUS_PENDING, CreativePackageItem::STATUS_RENDERING];

    /** The new lease, or null when another worker holds the item or the item is terminal. */
    public function claim(CreativePackageItem $item, ?CarbonInterface $now = null): ?CreativePackageItemLease
    {
        $now ??= now();
        $token = bin2hex(random_bytes(32));
        $claimed = $this->unowned($item, self::CLAIMABLE_STATUSES, $now)->where('attempts', '<', self::maxAttempts())
            ->update([
                'status' => CreativePackageItem::STATUS_RENDERING, 'lease_token' => $token,
                'lease_generation' => DB::raw('lease_generation + 1'), 'attempts' => DB::raw('attempts + 1'),
                'lease_expires_at' => self::leaseExpiry($now),
                'started_at' => DB::raw("coalesce(started_at, '{$now->format('Y-m-d H:i:s')}')"), 'updated_at' => $now,
            ]);
        if ($claimed !== 1) {
            return null;
        }

        return new CreativePackageItemLease($item->id, $token, (int) CreativePackageItem::query()->where('lease_token', $token)->value('lease_generation'));
    }

    /** Move an unowned pending or rendering item that spent every attempt to a retryable failed state. True when this call moved it. */
    public function exhaust(CreativePackageItem $item, ?CarbonInterface $now = null): bool
    {
        $now ??= now();

        return $this->unowned($item, self::EXHAUSTIBLE_STATUSES, $now)->where('attempts', '>=', self::maxAttempts())->update([
            'status' => CreativePackageItem::STATUS_FAILED, 'retryable' => true, 'lease_expires_at' => null,
            'failure_code' => 'creative_package_attempts_exhausted',
            'failure_details' => 'The item used every automatic render attempt.',
            'completed_at' => $now, 'updated_at' => $now,
        ]) === 1;
    }

    /**
     * Open one new attempt cycle on a retryable failed item. The row is locked
     * under the failed predicate first and written by id second, so two
     * replays in the same second both see the row and both succeed.
     */
    public function reopen(CreativePackageItem $item, ?CarbonInterface $now = null): bool
    {
        $now ??= now();

        return DB::transaction(function () use ($item, $now): bool {
            $held = CreativePackageItem::query()->whereKey($item->id)
                ->where('status', CreativePackageItem::STATUS_FAILED)->where('retryable', true)
                ->lockForUpdate()->value('id');
            if ($held === null) {
                return false;
            }
            CreativePackageItem::query()->whereKey($item->id)->update(['attempts' => 0, 'lease_expires_at' => null, 'updated_at' => $now]);

            return true;
        });
    }

    /** Extend the lease of a live fence by one lease length. Throws when the fence is stale. */
    public function renew(CreativePackageItemLease $lease, ?CarbonInterface $now = null): void
    {
        $now ??= now();
        $this->guard($lease, ['lease_expires_at' => self::leaseExpiry($now)], $now);
    }

    /**
     * Write attributes only while the fence is live. Throws when it is not.
     *
     * The row is locked under the fence predicate first and written second.
     * One conditional UPDATE is not enough here: MySQL reports changed rows,
     * not matched rows, so a write that repeats the stored values would read
     * as stale. The claim has no such problem because its token is always new.
     *
     * A heartbeat writes lease fields and no status. A terminal write passes
     * ready or failed. Any other status is a caller error, not a stale fence.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function guard(CreativePackageItemLease $lease, array $attributes = [], ?CarbonInterface $now = null): void
    {
        if (array_key_exists('status', $attributes) && ! in_array($attributes['status'], self::GUARDED_STATUSES, true)) {
            throw new \LogicException("A fenced write may set status to ready or failed, not '{$attributes['status']}'.");
        }
        $now ??= now();
        DB::transaction(function () use ($lease, $attributes, $now): void {
            $held = CreativePackageItem::query()->whereKey($lease->itemId)
                ->where('lease_token', $lease->token)->where('lease_generation', $lease->generation)
                ->where('lease_expires_at', '>=', $now)->where('status', CreativePackageItem::STATUS_RENDERING)
                ->lockForUpdate()->value('id');
            if ($held === null) {
                throw new StaleFenceException($lease->itemId);
            }
            CreativePackageItem::query()->whereKey($lease->itemId)->update($attributes + ['updated_at' => $now]);
        });
    }

    /**
     * An item in one of the statuses that no live lease holds.
     *
     * @param  array<int, string>  $statuses
     */
    private function unowned(CreativePackageItem $item, array $statuses, CarbonInterface $now): Builder
    {
        return CreativePackageItem::query()->whereKey($item->id)->unowned($statuses, $now);
    }

    private static function leaseExpiry(CarbonInterface $now): CarbonInterface
    {
        return $now->copy()->addSeconds(CreativePackageContract::limit('lease_seconds', CreativePackageContract::LEASE_SECONDS));
    }

    private static function maxAttempts(): int
    {
        return CreativePackageContract::limit('max_attempts', CreativePackageContract::MAX_ATTEMPTS);
    }
}
