<?php

namespace Tests\Feature\Performance;

use App\Models\CreativePackage;
use App\Models\User;
use App\Services\CreativePackageClaim;
use App\Services\CreativePackageClaimException;
use App\Services\CreativePackageReconciler;
use App\Services\CreativePackageRetention;
use App\Services\CreativePackageSourcePreflight;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ExplainsCapturedQueries;
use Tests\Support\SeedsProductionShapedRows;
use Tests\TestCase;

/**
 * T-018: the EXPLAIN of every hot creative-package query, captured from the
 * real service call against production-shaped rows. Each test asserts the
 * named index, a seek or a range, no temporary table, no avoidable filesort,
 * and an estimate bounded by the seeded matching rows.
 *
 * The reconciler and retention scans at a small scale are pinned in
 * CreativePackageReconcilerTest and CreativePackageRetentionTest. The tests
 * here add the scale and the estimate bound only.
 */
class MySqlCreativePackageAccessPlanTest extends TestCase
{
    use ExplainsCapturedQueries;
    use SeedsProductionShapedRows;

    /** @var array<int, array{id: int, user_id: int, hot: bool}> */
    private array $companies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireMysql();
        Queue::fake();
        $this->companies = $this->seedProductionShape();
        $this->recordCapturedQueries();
    }

    public function test_the_seed_is_production_shaped_and_lands_under_thirty_seconds(): void
    {
        $companyIds = array_column($this->companies, 'id');

        $this->assertSame(self::SEED_API_LOGS, DB::table('api_logs')->where('uuid', 'like', 'perf-seed-%')->count());
        $this->assertSame(self::SEED_HOT_COMPANY_API_LOGS, DB::table('api_logs')->where('user_id', $this->companies[0]['user_id'])->count());
        $this->assertSame(self::SEED_TEMPLATES, DB::table('templates')->whereIn('company_id', $companyIds)->count());
        $this->assertSame(self::SEED_TEMPLATES * 3, DB::table('template_versions')->whereIn('template_id', fn ($templates) => $templates->select('id')->from('templates')->whereIn('company_id', $companyIds))->count());
        $this->assertSame(self::SEED_PACKAGES, DB::table('creative_packages')->whereIn('company_id', $companyIds)->count());
        $this->assertSame(19_875, DB::table('creative_package_items')->whereIn('company_id', $companyIds)->count());
        $this->assertLessThan(30.0, self::$seedSeconds, 'The seed took '.round(self::$seedSeconds, 1).' seconds.');
        // A seed that keeps its bindings holds the memory for the rest of the suite. The bound is on the growth over
        // the seed, because the absolute value depends on the tests that ran before in the same process.
        $this->assertLessThan(32 * 1024 * 1024, self::$seedMemoryBytes, 'The seed added '.round(self::$seedMemoryBytes / 1024 / 1024).' MB to the process.');
    }

    public function test_the_batched_source_lookup_for_ten_items_seeks_both_unique_indexes(): void
    {
        $company = $this->companies[0];
        $items = $this->tenItems($company['id']);

        ['templates' => $templates, 'versions' => $versions] = $this->explainQueries(fn () => app(CreativePackageSourcePreflight::class)->preflight($company['id'], $items), templates: 'select `id`, `uuid`, `width`, `height` from `templates`', versions: 'select * from `template_versions`');

        $this->assertCount(1, $templates);
        $this->assertCount(1, $versions);
        $this->assertCount(1, $templates[0]['plan']);
        $this->assertCount(1, $versions[0]['plan']);
        // Ten (company_id, uuid) pairs: one seek each, so the estimate is at most ten.
        $this->assertAccessPlan($templates[0]['plan'][0], 'templates_company_id_uuid_unique', ['range'], 10);
        // Ten (template_id, version_number) pairs: one seek each.
        $this->assertAccessPlan($versions[0]['plan'][0], 'template_versions_template_id_version_number_unique', ['range'], 10);
        $this->recordPlanEvidence('source_lookup_templates', $templates);
        $this->recordPlanEvidence('source_lookup_versions', $versions);
    }

    public function test_the_replay_lookup_seeks_the_unique_claim_index(): void
    {
        $company = $this->companies[0];
        $actor = User::query()->findOrFail($company['user_id']);
        $package = CreativePackage::query()->where('company_id', $company['id'])->where('idempotency_key', 'perf-seed-3')->firstOrFail();
        $request = ['idempotency_key' => 'perf-seed-3', 'items' => [$this->tenItems($company['id'])[0]]];

        $explained = $this->explainQueries(function () use ($actor, $request, $package): void {
            try {
                app(CreativePackageClaim::class)->claim($actor, $request, CarbonImmutable::now());
            } catch (CreativePackageClaimException $exception) {
                // The stored fingerprint differs from this request. The lookup ran before the conflict.
                $this->assertSame('creative_package_idempotency_conflict', $exception->codeName);
            }
            $this->assertTrue($package->exists);
        }, lookup: 'select * from `creative_packages` where `company_id` = ? and `operation_namespace` = ? and `idempotency_key` = ?')['lookup'];

        $this->assertCount(1, $explained);
        $this->assertStringEndsWith('for update', $explained[0]['sql']);
        $this->assertAccessPlan($explained[0]['plan'][0], 'creative_packages_company_claim_unique', ['const'], 1);
        $this->recordPlanEvidence('replay_lookup', $explained);
    }

    public function test_the_active_count_ranges_the_active_limit_index(): void
    {
        $company = $this->companies[0];
        $actor = User::query()->findOrFail($company['user_id']);
        $request = ['idempotency_key' => 'plan-test-active-'.uniqid(), 'items' => [$this->tenItems($company['id'])[0]]];

        $explained = $this->explainQueries(function () use ($actor, $request): void {
            try {
                app(CreativePackageClaim::class)->claim($actor, $request, CarbonImmutable::now());
                $this->fail('The seeded company holds 150 building packages, so the claim must be rate limited.');
            } catch (CreativePackageClaimException $exception) {
                // The count ran and answered above the active limit. That is the read this test explains.
                $this->assertSame('creative_package_rate_limited', $exception->codeName);
            }
        }, count: 'select count(*) as `aggregate` from `creative_packages` where `company_id` = ? and `status` = ? and `lease_expires_at` > ?')['count'];

        $this->assertCount(1, $explained);
        // One company holds 600 packages, 150 of them building. The range covers only the building rows with a live lease.
        $this->assertAccessPlan($explained[0]['plan'][0], 'creative_packages_active_limit_index', ['range'], 150);
        $this->assertStringContainsString('using index', strtolower((string) $explained[0]['plan'][0]['Extra']));
        $this->recordPlanEvidence('active_count', $explained);
    }

    public function test_the_reconciler_scans_stay_on_their_indexes_at_scale(): void
    {
        // One pass. The reconciler mutates leases and counts, so a second pass would explain a different pool.
        ['items' => $items, 'packages' => $packages] = $this->explainQueries(fn () => app(CreativePackageReconciler::class)->reconcile(CarbonImmutable::now(), 100), items: 'select * from `creative_package_items` where `status` in (?)', packages: 'select * from `creative_packages` where `status` = ?');

        $this->assertCount(3, $items);
        foreach ($items as $scan) {
            $row = collect($scan['plan'])->firstWhere('table', 'creative_package_items');
            // One (status, retryable) pair with an expired or null lease, ordered by the index tail.
            $this->assertAccessPlan($row, 'creative_package_items_reconciliation_index', ['range'], 10_000);
        }
        $this->assertCount(1, $packages);
        $this->assertAccessPlan($packages[0]['plan'][0], 'creative_packages_reconciliation_index', ['range'], 1_000);
        $this->recordPlanEvidence('reconciler_items', $items);
        $this->recordPlanEvidence('reconciler_packages', $packages);
    }

    public function test_the_retention_seeks_stay_on_their_cleanup_indexes_at_scale(): void
    {
        config(['creative-packages.limits.retention_page' => 100]);

        $packages = $this->explainQueries(fn () => app(CreativePackageRetention::class)->prunePackages(CarbonImmutable::now(), 200), seek: 'select * from `creative_packages` where `replay_expires_at` < ?')['seek'];
        $diagnostics = $this->explainQueries(fn () => app(CreativePackageRetention::class)->clearDiagnostics(CarbonImmutable::now(), 200), seek: 'select * from `creative_package_items` where `diagnostic_expires_at` <= ?')['seek'];

        $this->assertGreaterThanOrEqual(2, count($packages));
        $this->assertGreaterThanOrEqual(2, count($diagnostics));
        foreach (array_merge($packages, $diagnostics) as $index => $seek) {
            $this->assertAccessPlan($seek['plan'][0], str_starts_with($seek['sql'], 'select * from `creative_packages`') ? 'creative_packages_replay_cleanup_index' : 'creative_package_items_diagnostic_cleanup_index', ['range'], 10_000);
        }
        // The second page seeks past the last row of the first one.
        $this->assertStringContainsString('`id` >', $packages[1]['sql']);
        $this->assertStringContainsString('`id` >', $diagnostics[1]['sql']);
        $this->recordPlanEvidence('retention_packages', $packages);
        $this->recordPlanEvidence('retention_diagnostics', $diagnostics);
    }

    /** Ten distinct published sources of one seeded company, in claim-request shape. */
    private function tenItems(int $companyId): array
    {
        $templates = DB::table('templates')->where('company_id', $companyId)->orderBy('id')->limit(10)->pluck('uuid');

        return $templates->map(fn (string $uuid, int $index) => ['client_creative_id' => "creative-{$index}", 'template_id' => $uuid, 'template_version' => 3, 'label' => "Creative {$index}", 'alt_text' => 'A branded image', 'format' => 'png'])->all();
    }
}
