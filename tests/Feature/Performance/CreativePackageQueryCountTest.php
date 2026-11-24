<?php

namespace Tests\Feature\Performance;

use App\Contracts\SnapRenderer;
use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use App\Models\User;
use App\Services\CreativePackageDispatcher;
use App\Services\CreativePackageReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsCreativePackages;
use Tests\Support\CallsCreativePackageMcpTools;
use Tests\Support\FakeRenderer;
use Tests\Support\InteractsWithMcp;
use Tests\Support\WithPassportKeys;
use Tests\TestCase;

/**
 * T-018: the query count of one item against ten, over the real `/mcp`
 * route. The claim grows by exactly one insert per item. The poll and the
 * reconciler scan do not grow at all.
 */
class CreativePackageQueryCountTest extends TestCase
{
    use BuildsCreativePackages;
    use CallsCreativePackageMcpTools;
    use InteractsWithMcp;
    use RefreshDatabase;
    use WithPassportKeys;

    /** The queries a claim adds per extra item: the one `creative_package_items` insert. */
    private const QUERIES_PER_EXTRA_ITEM = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWithPassportKeys();
        CarbonImmutable::setTestNow($this->now());
        Storage::fake('public');
        $this->app->instance(SnapRenderer::class, new FakeRenderer);
        // readyPackage() renders through reconcile() inline. The CI ledger job runs with no phpunit.xml, so the driver is set here.
        config(['queue.default' => 'sync']);
    }

    public function test_the_claim_of_ten_items_runs_nine_more_queries_than_the_claim_of_one(): void
    {
        Queue::fake();
        $actor = $this->member();
        $token = $this->token($actor);
        $one = $this->request('one', [$this->source20($actor)]);
        $ten = $this->request('ten', array_map(fn () => $this->source20($actor), range(1, 10)));
        $this->warmTheToken($token);

        $oneCount = $this->countQueries(fn () => $this->export($token, $one));
        $tenCount = $this->countQueries(fn () => $this->export($token, $ten));

        // The batched source lookup, the lock, the replay lookup, the entitlement, the active count, the
        // capacity sums and the package insert are fixed. Only the item insert loop grows with the request.
        $this->assertSame(9 * self::QUERIES_PER_EXTRA_ITEM, $tenCount - $oneCount, "One item: {$oneCount} queries. Ten items: {$tenCount} queries.");
        $this->assertSame([1, 10], [CreativePackage::query()->where('idempotency_key', 'one')->sole()->total_items, CreativePackage::query()->where('idempotency_key', 'ten')->sole()->total_items]);
    }

    public function test_the_poll_of_a_ready_package_runs_the_same_queries_for_one_item_and_for_ten(): void
    {
        $actor = $this->member();
        $token = $this->token($actor);
        $one = $this->readyPackage($actor, 'one', 1);
        $ten = $this->readyPackage($actor, 'ten', 10);
        $this->warmTheToken($token);

        $oneCount = $this->countQueries(fn () => $this->poll($token, $one));
        $tenCount = $this->countQueries(fn () => $this->poll($token, $ten));

        // The package read, the item load and the signer's media and snap loads are one query each, whatever the item count.
        $this->assertSame($oneCount, $tenCount, "One item: {$oneCount} queries. Ten items: {$tenCount} queries.");
        $this->assertCount(10, $this->mcpToolPayload($this->poll($token, $ten))['manifest']['items']);
    }

    public function test_the_reconciler_scan_runs_the_same_queries_for_one_pending_item_and_for_ten(): void
    {
        Queue::fake();
        $this->app->instance(CreativePackageDispatcher::class, new class extends CreativePackageDispatcher
        {
            public function dispatch(CreativePackage $package): void {}
        });
        $actor = $this->member();
        $this->claim($actor, $this->request('one', [$this->source20($actor)]));
        $oneCount = $this->countQueries(fn () => $this->assertSame(1, $this->reconcile()['pending_redispatched']));
        // The dispatcher is a no-op here, so the first item stays pending. The second scan must read the ten-item package alone.
        CreativePackageItem::query()->delete();
        CreativePackage::query()->delete();
        $this->claim($actor, $this->request('ten', array_map(fn () => $this->source20($actor), range(1, 10))));

        $tenCount = $this->countQueries(fn () => $this->assertSame(10, $this->reconcile()['pending_redispatched']));

        $this->assertSame($oneCount, $tenCount, "One item: {$oneCount} queries. Ten items: {$tenCount} queries.");
        foreach (['templates', 'template_versions', 'media', 'snaps'] as $table) {
            $this->assertSame(0, $this->queriesTouching($table), "The scan read {$table} per item.");
        }
    }

    /** @return array<string, int> */
    private function reconcile(): array
    {
        $this->sql = [];
        $this->recordQueries();

        return app(CreativePackageReconciler::class)->reconcile(CarbonImmutable::now(), 100);
    }

    private function countQueries(\Closure $run): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** One claimed package of `$items` sources, rendered to ready by the reconciler on the sync queue. */
    private function readyPackage(User $actor, string $key, int $items): CreativePackage
    {
        $this->app->instance(CreativePackageDispatcher::class, new class extends CreativePackageDispatcher
        {
            public function dispatch(CreativePackage $package): void {}
        });
        $package = $this->claim($actor, $this->request($key, array_map(fn () => $this->source20($actor), range(1, $items))))->package;
        app(CreativePackageReconciler::class)->reconcile(CarbonImmutable::now());
        $this->assertSame([CreativePackage::STATUS_READY, $items], [$package->fresh()->status, $package->fresh()->ready_items_count]);

        return $package->fresh();
    }

    private function export(string $token, array $request): void
    {
        $this->forgetTheCaller()->callMcpTool($token, 'export-creative-package', $request);
    }

    private function poll(string $token, CreativePackage $package): TestResponse
    {
        return $this->forgetTheCaller()->callMcpTool($token, 'get-creative-package', ['package_id' => $package->uuid]);
    }

    /** Sanctum writes `last_used_at` on the first call of a token only: the clock is frozen, so the second save is not dirty. */
    private function warmTheToken(string $token): void
    {
        $this->forgetTheCaller()->callMcpTool($token, 'get-creative-package', ['package_id' => '00000000-0000-4000-8000-000000000000']);
    }
}
