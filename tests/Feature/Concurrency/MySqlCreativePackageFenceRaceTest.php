<?php

namespace Tests\Feature\Concurrency;

use App\Models\ApiLog;
use App\Models\Company;
use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\CreativePackageItemFence;
use App\Services\CreativePackageItemLease;
use App\Services\StaleFenceException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The fence on two MySQL connections. A worker on the child connection
 * holds a lease. The parent moves the lease to a second worker while the
 * first one waits on the item row. The first worker must then write
 * nothing: no usage row, no status, no lease.
 */
class MySqlCreativePackageFenceRaceTest extends TestCase
{
    use RunsCompetingMySqlProcess;

    private const EXECUTE_BODY = <<<'PHP_BODY'
        app()->bind(App\Contracts\SnapRenderer::class, Tests\Support\FakeRenderer::class);
        $item = App\Models\CreativePackageItem::query()->findOrFail($args['item_id']);
        $lease = new App\Services\CreativePackageItemLease($item->id, $args['token'], $args['generation']);
        app(App\Services\CreativePackageItemExecutor::class)->execute($item, $lease);
        $fresh = $item->fresh();

        return ['status' => $fresh->status, 'api_log_id' => $fresh->api_log_id, 'lease_generation' => $fresh->lease_generation];
    PHP_BODY;

    private const GUARD_BODY = <<<'PHP_BODY'
        $item = App\Models\CreativePackageItem::query()->findOrFail($args['item_id']);
        $lease = new App\Services\CreativePackageItemLease($item->id, $args['token'], $args['generation']);
        app(App\Services\CreativePackageItemFence::class)->guard($lease, ['status' => App\Models\CreativePackageItem::STATUS_READY, 'completed_at' => $now], $now);

        return ['status' => $item->fresh()->status];
    PHP_BODY;

    private ?Company $company = null;

    private CarbonImmutable $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireMysqlBarrier();
        $this->clock = CarbonImmutable::now()->startOfSecond();
    }

    protected function tearDown(): void
    {
        $this->tearDownRace($this->company);
        parent::tearDown();
    }

    /**
     * Guard under test: the token and generation predicates in
     * CreativePackageItemFence::guard(). The executor guards before it
     * renders, so a worker whose lease moved renders nothing, records no
     * usage and writes no status.
     */
    public function test_a_worker_whose_lease_moved_before_execute_writes_nothing(): void
    {
        $item = $this->pendingItem();
        $stale = $this->claim($item);

        DB::beginTransaction();
        CreativePackageItem::query()->whereKey($item->id)->update(['lease_expires_at' => $this->clock->subSecond()]);
        $this->startCompetingProcess(self::EXECUTE_BODY, $this->args($item, $stale));
        $this->assertChildIsBlocked();
        $current = $this->claim($item);
        DB::commit();

        $outcome = $this->childSucceeded();

        $fresh = $item->fresh();
        $this->assertSame(['rendering', null, 2], [$outcome['status'], $outcome['api_log_id'], $outcome['lease_generation']]);
        $this->assertSame([$current->token, 2, 'rendering', null, null], [$fresh->lease_token, $fresh->lease_generation, $fresh->status, $fresh->snap_id, $fresh->api_log_id]);
        $this->assertSame(0, ApiLog::query()->where('user_id', $item->package->created_by)->count());
    }

    /**
     * Guard under test: the same predicates on a terminal write. A stale
     * worker that waits on the item row and then writes ready fails at the
     * fence, and the item keeps the state of the current lease.
     */
    public function test_a_stale_worker_cannot_write_the_terminal_state_after_the_lease_moved(): void
    {
        $item = $this->pendingItem();
        $stale = $this->claim($item);

        DB::beginTransaction();
        CreativePackageItem::query()->whereKey($item->id)->update(['lease_expires_at' => $this->clock->subSecond()]);
        $this->startCompetingProcess(self::GUARD_BODY, $this->args($item, $stale));
        $this->assertChildIsBlocked();
        $current = $this->claim($item);
        DB::commit();

        $failure = $this->childFailed();

        $this->assertSame(StaleFenceException::class, $failure['class']);
        $this->assertSame([$current->token, 2, 'rendering', null], [$item->fresh()->lease_token, $item->fresh()->lease_generation, $item->fresh()->status, $item->fresh()->completed_at]);
    }

    private function claim(CreativePackageItem $item): CreativePackageItemLease
    {
        $lease = app(CreativePackageItemFence::class)->claim($item, $this->clock);
        $this->assertNotNull($lease);

        return $lease;
    }

    /** One pending item of a package that a customer member of the company created. */
    private function pendingItem(): CreativePackageItem
    {
        $this->company = Company::factory()->create();
        $user = User::factory()->for($this->company)->create(['role' => User::ROLE_CUSTOMER]);
        $template = Template::factory()->for($this->company)->for($user)->create();
        $version = TemplateVersion::factory()->for($template)->create(['version_number' => 1, 'created_by' => $user->id, 'published' => true]);
        $package = CreativePackage::factory()->create(['company_id' => $this->company->id, 'created_by' => $user->id, 'total_items' => 1, 'pending_items_count' => 1]);

        return CreativePackageItem::factory()->create(['company_id' => $this->company->id, 'creative_package_id' => $package->id, 'source_template_version_id' => $version->id]);
    }

    /** @return array<string, mixed> */
    private function args(CreativePackageItem $item, CreativePackageItemLease $lease): array
    {
        return ['item_id' => $item->id, 'token' => $lease->token, 'generation' => $lease->generation, 'now' => $this->clock->toDateTimeString()];
    }
}
