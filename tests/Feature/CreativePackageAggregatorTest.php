<?php

namespace Tests\Feature;

use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use App\Services\CreativePackageAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCreativePackages;
use Tests\TestCase;

/** The aggregator recomputes the cached counts and the package state from the item rows alone. */
class CreativePackageAggregatorTest extends TestCase
{
    use BuildsCreativePackages;
    use RefreshDatabase;

    /** @return array<string, array{array<int, string>, string, array{int, int, int, int}}> */
    public static function matrix(): array
    {
        return [
            'a pending item keeps the package building' => [['pending', 'ready'], CreativePackage::STATUS_BUILDING, [1, 0, 1, 0]],
            'a rendering item keeps the package building' => [['rendering', 'failed'], CreativePackage::STATUS_BUILDING, [0, 1, 0, 1]],
            'every item ready is ready' => [['ready', 'ready'], CreativePackage::STATUS_READY, [0, 0, 2, 0]],
            'a retryable failure beside ready items is partial' => [['ready', 'retryable'], CreativePackage::STATUS_PARTIAL, [0, 0, 1, 1]],
            'only retryable failures is partial with zero ready items' => [['retryable', 'retryable'], CreativePackage::STATUS_PARTIAL, [0, 0, 0, 2]],
            'a permanent failure beside a ready item is partial' => [['ready', 'failed'], CreativePackage::STATUS_PARTIAL, [0, 0, 1, 1]],
            'only permanent failures is failed' => [['failed', 'failed'], CreativePackage::STATUS_FAILED, [0, 0, 0, 2]],
        ];
    }

    /**
     * @param  array<int, string>  $states
     * @param  array{int, int, int, int}  $counts
     */
    #[DataProvider('matrix')]
    public function test_the_package_state_and_counts_follow_the_items(array $states, string $status, array $counts): void
    {
        CarbonImmutable::setTestNow($this->now());
        $first = $this->oneItem();
        $package = $first->package;
        $package->update(['total_items' => count($states), 'pending_items_count' => 9, 'completed_at' => $this->now()->subDay()]);
        $first->delete();
        foreach ($states as $position => $state) {
            $factory = CreativePackageItem::factory()->for($package, 'package')->state(['company_id' => $package->company_id, 'position' => $position]);
            $factory = match ($state) {
                'pending' => $factory,
                'rendering' => $factory->rendering(),
                'ready' => $factory->ready(),
                'failed' => $factory->failed(),
                'retryable' => $factory->failed()->state(['retryable' => true, 'failure_code' => 'creative_package_entitlement_required']),
            };
            $factory->create();
        }

        app(CreativePackageAggregator::class)->recount($package);

        $fresh = $package->fresh();
        $this->assertSame([$status, $counts, $fresh->total_items], [$fresh->status, [$fresh->pending_items_count, $fresh->rendering_items_count, $fresh->ready_items_count, $fresh->failed_items_count], array_sum($counts)]);
        $this->assertSame($status !== CreativePackage::STATUS_BUILDING, $fresh->completed_at !== null);
    }
}
