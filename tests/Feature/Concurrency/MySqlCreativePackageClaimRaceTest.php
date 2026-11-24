<?php

namespace Tests\Feature\Concurrency;

use App\Models\Company;
use App\Models\CreativePackage;
use App\Models\User;
use App\Support\CreativePackageRequestValidator;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCreativePackages;
use Tests\TestCase;

/**
 * Two claims for one company on two MySQL connections. The parent holds the
 * first connection and one open transaction. The child runs a competing
 * claim on the second connection.
 *
 * These tests do not use RefreshDatabase. See RunsCompetingMySqlProcess.
 */
class MySqlCreativePackageClaimRaceTest extends TestCase
{
    use BuildsCreativePackages;
    use RunsCompetingMySqlProcess;

    private const CLAIM_BODY = <<<'PHP_BODY'
        $actor = App\Models\User::query()->findOrFail($args['actor_id']);
        config(['creative-packages.limits.active_per_company' => $args['active_limit']]);
        $result = app(App\Services\CreativePackageClaim::class)->claim($actor, $args['request'], $now);

        return ['replayed' => $result->replayed, 'package_uuid' => $result->package->uuid];
    PHP_BODY;

    private ?User $actor = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireMysqlBarrier();
        $this->recordDispatches();
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('SET SESSION foreign_key_checks = 1');
        }
        $this->tearDownRace($this->actor?->company);
        parent::tearDown();
    }

    /**
     * Guard under test: the Company row lock in CreativePackageClaim::claim().
     * The loser waits on the lock, then existing() reads the committed
     * package before it reaches the insert.
     */
    public function test_a_same_key_claim_that_waits_on_the_company_lock_replays_the_committed_package(): void
    {
        $this->actor = $this->member();
        $request = $this->request('shared-key', [$this->source($this->actor, 1)]);

        DB::beginTransaction();
        Company::query()->whereKey($this->actor->company_id)->lockForUpdate()->firstOrFail();
        $winner = $this->claim($this->actor, $request);
        $this->startCompetingProcess(self::CLAIM_BODY, $this->args($request));
        $this->assertChildIsBlocked();
        DB::commit();

        $loser = $this->childSucceeded();

        $this->assertFalse($winner->replayed);
        $this->assertSame(['replayed' => true, 'package_uuid' => $winner->package->uuid], $loser);
        $this->assertSame(1, $this->packagesWithKey('shared-key'));
    }

    /**
     * Guard under test: the shared lock InnoDB takes on the companies row for
     * the foreign key of every creative_packages insert. A package insert in
     * flight makes the Company lock in claim() wait, so existing() reads the
     * committed winner. The unique claim key is never reached on this path.
     */
    public function test_a_claim_waits_for_a_package_insert_in_flight_and_replays_it(): void
    {
        $this->actor = $this->member();
        $request = $this->request('shared-key', [$this->source($this->actor, 1)]);

        DB::beginTransaction();
        $winner = $this->insertPackage($request);
        $this->startCompetingProcess(self::CLAIM_BODY, $this->args($request));
        $this->assertChildIsBlocked();
        DB::commit();

        $loser = $this->childSucceeded();

        $this->assertSame(['replayed' => true, 'package_uuid' => $winner->uuid], $loser);
        $this->assertSame(1, $this->packagesWithKey('shared-key'));
    }

    /**
     * Guard under test: the locking read in CreativePackageClaim::existing()
     * behind the UniqueConstraintViolationException catch. The parent inserts
     * the winner with foreign key checks off, so it holds no lock on the
     * companies row and the child passes existing() before it blocks on the
     * unique claim key. After the commit the child loses the key. A plain
     * read inside the same REPEATABLE READ transaction reads the snapshot
     * from before the commit and finds nothing. The locking read reads the
     * committed winner.
     */
    public function test_the_duplicate_key_loser_reloads_the_committed_package(): void
    {
        $this->actor = $this->member();
        $request = $this->request('shared-key', [$this->source($this->actor, 1)]);

        DB::statement('SET SESSION foreign_key_checks = 0');
        DB::beginTransaction();
        $winner = $this->insertPackage($request);
        $this->startCompetingProcess(self::CLAIM_BODY, $this->args($request));
        $this->assertChildIsBlocked();
        DB::commit();
        DB::statement('SET SESSION foreign_key_checks = 1');

        $loser = $this->childSucceeded();

        $this->assertSame(['replayed' => true, 'package_uuid' => $winner->uuid], $loser);
        $this->assertSame(1, $this->packagesWithKey('shared-key'));
    }

    /**
     * Guard under test: the Company row lock that serializes the active
     * count. With the limit at one, the second claim waits for the first
     * commit and then reads one building package.
     */
    public function test_one_more_claim_than_the_active_limit_is_rate_limited(): void
    {
        $this->actor = $this->member();
        config(['creative-packages.limits.active_per_company' => 1]);
        $first = $this->request('key-a', [$this->source($this->actor, 1)]);
        $second = $this->request('key-b', [$this->source($this->actor, 1)]);

        DB::beginTransaction();
        Company::query()->whereKey($this->actor->company_id)->lockForUpdate()->firstOrFail();
        $this->claim($this->actor, $first);
        $this->startCompetingProcess(self::CLAIM_BODY, $this->args($second, activeLimit: 1));
        $this->assertChildIsBlocked();
        DB::commit();

        $loser = $this->childFailed();

        $this->assertSame('creative_package_rate_limited', $loser['code_name']);
        $this->assertSame(1, CreativePackage::query()->where('company_id', $this->actor->company_id)->count());
    }

    /** @return array<string, mixed> */
    private function args(array $request, int $activeLimit = 3): array
    {
        return ['actor_id' => $this->actor->id, 'request' => $request, 'now' => $this->now()->toDateTimeString(), 'active_limit' => $activeLimit];
    }

    /** One committed-looking package row for the request, with the fingerprint the claim computes. */
    private function insertPackage(array $request): CreativePackage
    {
        $normalized = app(CreativePackageRequestValidator::class)->normalize($request);
        $fingerprint = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

        return CreativePackage::factory()->create([
            'company_id' => $this->actor->company_id, 'created_by' => $this->actor->id,
            'idempotency_key' => $request['idempotency_key'], 'request_fingerprint' => $fingerprint,
        ]);
    }

    private function packagesWithKey(string $key): int
    {
        return CreativePackage::query()->where('company_id', $this->actor->company_id)->where('idempotency_key', $key)->count();
    }
}
