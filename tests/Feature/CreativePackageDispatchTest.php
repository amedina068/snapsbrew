<?php

namespace Tests\Feature;

use App\Jobs\ProcessCreativePackageItem;
use App\Models\CreativePackageItem;
use App\Services\CreativePackageDispatcher;
use App\Services\CreativePackageItemExecutor;
use App\Services\CreativePackageItemLease;
use App\Support\CreativePackageContract;
use App\Support\Queues;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\BuildsCreativePackages;
use Tests\TestCase;

/**
 * The dispatcher pushes one unique job per pending item in position order,
 * and the job claims the fence before it hands the item to the executor.
 */
class CreativePackageDispatchTest extends TestCase
{
    use BuildsCreativePackages;
    use RefreshDatabase;

    /** @var array<int, array{CreativePackageItem, CreativePackageItemLease}> */
    private array $executed = [];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow($this->now());
        $executed = &$this->executed;
        $this->app->instance(CreativePackageItemExecutor::class, new class($executed) extends CreativePackageItemExecutor
        {
            public function __construct(private array &$executed) {}

            public function execute(CreativePackageItem $item, CreativePackageItemLease $lease, ?string $correlationId = null): void
            {
                $this->executed[] = [$item, $lease];
            }
        });
    }

    public function test_the_dispatcher_pushes_one_job_per_pending_item_in_position_order_on_the_imports_queue(): void
    {
        Bus::fake();
        [$last, $first, $ready] = $this->items();

        app(CreativePackageDispatcher::class)->dispatch($first->package);

        Bus::assertDispatchedTimes(ProcessCreativePackageItem::class, 2);
        $this->assertSame([[$first->id, Queues::IMPORTS], [$last->id, Queues::IMPORTS]], Bus::dispatched(ProcessCreativePackageItem::class)->map(fn ($job) => [$job->itemId, $job->queue])->all());
    }

    /** A fake answers the call and skips the unique lock and the queue name. Only the real queue proves both. */
    public function test_a_real_dispatch_lands_each_item_once_on_the_imports_queue(): void
    {
        config(['queue.default' => 'database']);
        [$last, $first] = $this->items();

        app(CreativePackageDispatcher::class)->dispatch($first->package);
        app(CreativePackageDispatcher::class)->dispatch($first->package);

        $this->assertSame([Queues::IMPORTS, Queues::IMPORTS], DB::table('jobs')->orderBy('id')->pluck('queue')->all());
        $this->assertSame(0, count($this->executed));
    }

    /** The run of the job releases the unique lock in the same instant. Without the run, the lock lives one lease. */
    public function test_the_unique_lock_holds_one_job_per_item_for_one_lease_and_releases_when_the_job_runs(): void
    {
        config(['queue.default' => 'database']);
        $item = $this->oneItem();
        $ids = fn () => DB::table('jobs')->pluck('id')->all();

        ProcessCreativePackageItem::dispatch($item->id);
        $first = $ids()[0];
        app('queue')->connection('database')->pop(Queues::IMPORTS)->fire();
        ProcessCreativePackageItem::dispatch($item->id);
        $this->assertSame([1, false, 1], [count($ids()), in_array($first, $ids(), true), count($this->executed)]);

        ProcessCreativePackageItem::dispatch($item->id);
        $this->assertCount(1, $ids());
        CarbonImmutable::setTestNow($this->now()->addSeconds(CreativePackageContract::LEASE_SECONDS + 1));
        ProcessCreativePackageItem::dispatch($item->id);
        $this->assertCount(2, $ids());
    }

    public function test_the_job_claims_the_fence_and_hands_the_rendering_item_to_the_executor(): void
    {
        $item = $this->oneItem();

        app()->call([new ProcessCreativePackageItem($item->id), 'handle']);

        [$executedItem, $lease] = $this->executed[0];
        $this->assertSame([$item->id, CreativePackageItem::STATUS_RENDERING, 1], [$executedItem->id, $executedItem->status, $executedItem->attempts]);
        $this->assertSame([$item->id, $executedItem->lease_token, 1], [$lease->itemId, $lease->token, $lease->generation]);
    }

    public function test_the_job_stops_before_the_executor_when_another_worker_holds_the_item_or_the_item_is_gone(): void
    {
        $item = $this->oneItem();
        $item->update(['status' => CreativePackageItem::STATUS_RENDERING, 'lease_token' => str_repeat('c', 64), 'lease_generation' => 1, 'lease_expires_at' => $this->now()->addMinute()]);

        Log::spy();

        app()->call([new ProcessCreativePackageItem($item->id), 'handle']);
        app()->call([new ProcessCreativePackageItem($item->id + 100), 'handle']);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'creative_package_item.missing'
            && array_keys($context) === ['item_id', 'correlation_id']
            && [$context['item_id'], Str::isUuid($context['correlation_id'])] === [$item->id + 100, true])->once();
        $this->assertSame([], $this->executed);
        $this->assertSame([str_repeat('c', 64), 0], [$item->fresh()->lease_token, $item->fresh()->attempts]);
    }

    /** Three items in one package: positions 2 and 0 pending, position 1 ready. Returned in that order. */
    private function items(): array
    {
        $last = $this->oneItem();
        $last->update(['position' => 2]);
        $sibling = fn (int $position) => CreativePackageItem::factory()->create(['company_id' => $last->company_id, 'creative_package_id' => $last->creative_package_id, 'position' => $position]);

        return [$last, $sibling(0), tap($sibling(1))->update(['status' => CreativePackageItem::STATUS_READY, 'retryable' => false])];
    }
}
