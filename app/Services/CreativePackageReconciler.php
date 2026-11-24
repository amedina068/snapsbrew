<?php

namespace App\Services;

use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use App\Support\CreativePackageContract;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Repairs incomplete packages. Each item scan reads one equality-valued
 * (status, retryable) pair with no live lease, ordered by (lease_expires_at,
 * id) with a limit, so the reconciliation index serves it. The reconciler
 * never renders, settles or releases. It redispatches the job, and the fence
 * plus the executor replay path do the rest: a completed snap is reused and a
 * recorded item is marked ready with no second usage row. The one item write it
 * makes goes through CreativePackageItemAttempts::exhaustIfSpent().
 *
 * A retryable failed item is redispatched only when its block cleared and its
 * attempt cycle is not spent. An entitlement-blocked item clears when the
 * creator is entitled again. An attempts-exhausted item, an item with a spent cycle and
 * an item with a code this class does not know wait for a same-key replay:
 * resume() reopens each cleared failed item and treats the replay itself as
 * the clearing condition for those three.
 *
 * The counts name what this class did, not what the queue accepted. A
 * `*_redispatched` count is one dispatch call per item. The unique lock of
 * the job can drop a call for an item whose job is already queued, and the
 * fence makes such a duplicate harmless.
 *
 * A building package with an expired package lease is renewed while one item
 * is still pending or rendering, so it keeps its active-limit slot for one
 * more lease. With no active item it is recounted, which moves it to its
 * terminal state and repairs cached-count drift.
 */
class CreativePackageReconciler
{
    public const ACTIONS = ['pending_redispatched', 'rendering_redispatched', 'failed_redispatched', 'exhausted', 'waiting', 'packages_renewed', 'packages_recounted'];

    public function __construct(
        private CreativePackageDispatcher $dispatcher,
        private CreativePackageItemFence $fence,
        private CreativePackageItemAttempts $attempts,
        private CreativePackageAggregator $aggregator,
    ) {}

    /** @return array<string, int> */
    public function reconcile(?CarbonInterface $now = null, int $limit = 100): array
    {
        $now ??= now();
        $counts = array_fill_keys(self::ACTIONS, 0);
        foreach ([CreativePackageItem::STATUS_PENDING, CreativePackageItem::STATUS_RENDERING, CreativePackageItem::STATUS_FAILED] as $status) {
            foreach ($this->unowned(CreativePackageItem::query(), [$status], $now, $limit) as $item) {
                $counts[$this->reclaim($item, $now, replay: false, status: $status)]++;
            }
        }
        $packages = CreativePackage::query()->where('status', CreativePackage::STATUS_BUILDING)
            ->where('lease_expires_at', '<', $now)->orderBy('lease_expires_at')->orderBy('id')->limit($limit)->get();
        foreach ($packages as $package) {
            if ($package->items()->whereIn('status', [CreativePackageItem::STATUS_PENDING, CreativePackageItem::STATUS_RENDERING])->exists()) {
                $package->update(['lease_expires_at' => $now->copy()->addSeconds(CreativePackageContract::limit('lease_seconds', CreativePackageContract::LEASE_SECONDS))]);
                $counts['packages_renewed']++;
            } else {
                $this->aggregator->recount($package);
                $counts['packages_recounted']++;
            }
        }

        return $counts;
    }

    /**
     * The second step of an authorized same-key replay: restart every
     * unowned item of one package and reopen its retryable failures.
     *
     * @return array<string, int>
     */
    public function resume(CreativePackage $package, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $counts = array_fill_keys(self::ACTIONS, 0);
        $statuses = [CreativePackageItem::STATUS_PENDING, CreativePackageItem::STATUS_RENDERING, CreativePackageItem::STATUS_FAILED];
        foreach ($this->unowned($package->items(), $statuses, $now, PHP_INT_MAX) as $item) {
            $counts[$this->reclaim($item, $now, replay: true, status: $item->status)]++;
        }

        return $counts;
    }

    /**
     * Unowned items of the query in the given statuses, oldest lease first. An
     * item of a package past its replay window is left alone: the prune
     * deletes that package within the hour, so a render now is work
     * nobody can read. The relations that cleared() reads are loaded in one
     * query each.
     *
     * @param  array<int, string>  $statuses
     */
    private function unowned(Builder|HasMany $query, array $statuses, CarbonInterface $now, int $limit): Collection
    {
        return $query->unowned($statuses, $now)->whereHas('package', fn (Builder $package) => $package->where('replay_expires_at', '>', $now))
            ->with(['package.creator', 'company'])->orderBy('lease_expires_at')->orderBy('id')->limit($limit)->get();
    }

    /** Redispatch, exhaust or leave one unowned item. Returns the count key of the action. */
    private function reclaim(CreativePackageItem $item, CarbonInterface $now, bool $replay, string $status): string
    {
        $spent = $item->attempts >= CreativePackageContract::limit('max_attempts', CreativePackageContract::MAX_ATTEMPTS);
        if ($status === CreativePackageItem::STATUS_FAILED) {
            if (! $this->cleared($item, $now, $replay) || ($replay ? ! $this->fence->reopen($item, $now) : $spent)) {
                return 'waiting';
            }
        } elseif ($spent) {
            return $this->attempts->exhaustIfSpent($item, $now) ? 'exhausted' : 'waiting';
        }
        $this->dispatcher->dispatchItems([$item->id]);

        return "{$status}_redispatched";
    }

    /** True when the condition that blocked a retryable failed item no longer holds. */
    private function cleared(CreativePackageItem $item, CarbonInterface $now, bool $replay): bool
    {
        return match ($item->failure_code) {
            'creative_package_attempts_exhausted' => $replay,
            'creative_package_entitlement_required' => ($creator = $item->package->creator) !== null
                && $creator->company_id === $item->company_id && CreativePackageClaim::entitled($creator, $item->company),
            default => $replay,
        };
    }
}
