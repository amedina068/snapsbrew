<?php

namespace Tests\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Production-shaped rows for the MySQL access-plan and cardinality tests:
 * the seed and its cleanup. The EXPLAIN capture lives in
 * ExplainsCapturedQueries.
 *
 * One seed per test class, written with bulk inserts and read by every test
 * of the class. The rows belong to companies named `perf-seed-{n}`, and the
 * class deletes them once through the PDO handle it kept, because the
 * application is gone when PHPUnit reaches tearDownAfterClass().
 *
 * The shape: five companies, 50,000 `api_logs` with 20,000 rows on the hot
 * company, 2,000 templates with three versions each, and 3,000 packages in
 * mixed statuses with about 20,000 items. A test
 * that uses this trait cannot use RefreshDatabase: ANALYZE TABLE commits, and
 * the seed must survive the test that wrote it.
 */
trait SeedsProductionShapedRows
{
    use RequiresMysql;

    public const SEED_COMPANIES = 5;

    /** The tables the seed fills. ANALYZE runs on each after the seed and after the cleanup. */
    private const ANALYZED_TABLES = ['api_logs', 'creative_packages', 'creative_package_items', 'templates', 'template_versions'];

    public const SEED_API_LOGS = 50_000;

    /** The `api_logs` rows of the user of the hot company. */
    public const SEED_HOT_COMPANY_API_LOGS = 20_000;

    public const SEED_TEMPLATES = 2_000;

    public const SEED_PACKAGES = 3_000;

    /** Items per package, cycled in package order. The mean is 6.625, so 3,000 packages hold 19,875 items. */
    private const ITEMS_PER_PACKAGE = [1, 2, 3, 5, 8, 10, 12, 12];

    private const PACKAGE_STATUSES = ['ready', 'ready', 'ready', 'ready', 'ready', 'ready', 'partial', 'partial', 'failed', 'building', 'building', 'building_aged'];

    private const CHUNK = 1_000;

    private static ?\PDO $seedPdo = null;

    private static ?string $seededBy = null;

    /** @var array<int, array{id: int, user_id: int, hot: bool}> */
    private static array $seedCompanies = [];

    private static float $seedSeconds = 0.0;

    /**
     * The growth of memory_get_usage() over the seed, for the bounded-memory
     * assertion. The absolute value depends on the tests that ran before.
     */
    private static int $seedMemoryBytes = 0;

    /** Seed one time per class. Returns the companies, hot company first. */
    private function seedProductionShape(): array
    {
        if (self::$seededBy === static::class) {
            return self::$seedCompanies;
        }
        self::deleteProductionShape(DB::connection()->getPdo());
        $started = microtime(true);
        $memoryBefore = memory_get_usage();
        $now = CarbonImmutable::now();

        $companies = [];
        $this->withoutQueryListeners(function () use (&$companies, $now): void {
            $companies = $this->seedCompanies($now);
            $this->seedApiLogs($companies, $now);
            $templates = $this->seedTemplates($companies, $now);
            $this->seedPackages($companies, $templates, $now);
            foreach (self::ANALYZED_TABLES as $table) {
                DB::statement("ANALYZE TABLE {$table}");
            }
        });

        self::$seedSeconds = microtime(true) - $started;
        self::$seedMemoryBytes = memory_get_usage() - $memoryBefore;
        self::$seedPdo = DB::connection()->getPdo();
        self::$seededBy = static::class;

        return self::$seedCompanies = $companies;
    }

    /** @return array<string, mixed> */
    private function apiLogRow(string $uuid, int $userId, string $method, int $status, CarbonImmutable $now): array
    {
        return [
            'uuid' => $uuid, 'user_id' => $userId, 'renders' => 1, 'url' => '/v1/snaps',
            'ip_address' => '127.0.0.1', 'feature' => 'api', 'type' => 'snap', 'method' => $method, 'request' => '{}',
            'status_code' => $status, 'created_at' => $now, 'updated_at' => $now,
        ];
    }

    /**
     * Run the closure with no QueryExecuted listener on the connection. The
     * framework exception renderer and Ignition's QueryRecorder keep the
     * bindings of every query they see, about 340 MB for one seed. The
     * dispatcher comes back in the finally, with every listener it had.
     */
    private function withoutQueryListeners(\Closure $run): void
    {
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        $connection->unsetEventDispatcher();
        try {
            $run();
        } finally {
            $connection->setEventDispatcher($dispatcher);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$seedPdo !== null) {
            self::deleteProductionShape(self::$seedPdo);
            self::$seedPdo = null;
            self::$seededBy = null;
        }
        parent::tearDownAfterClass();
    }

    private static function deleteProductionShape(\PDO $pdo): void
    {
        $companies = "SELECT id FROM companies WHERE name LIKE 'perf-seed-%'";
        $pdo->exec("DELETE FROM api_logs WHERE user_id IN (SELECT id FROM users WHERE company_id IN ({$companies}))");
        $pdo->exec("DELETE FROM companies WHERE name LIKE 'perf-seed-%'");
        // The seed refreshed the statistics at 20,000 items. A later class that explains a scan of a
        // near-empty table reads those stale numbers and gets a full scan, so refresh them again.
        foreach (self::ANALYZED_TABLES as $table) {
            // ANALYZE returns a result set. Read it, or the next query fails on the open cursor.
            $pdo->query("ANALYZE TABLE {$table}")->fetchAll();
        }
    }

    /** @return array<int, array{id: int, user_id: int, hot: bool}> */
    private function seedCompanies(CarbonImmutable $now): array
    {
        $companies = [];
        foreach (range(1, self::SEED_COMPANIES) as $number) {
            $companyId = DB::table('companies')->insertGetId(['uuid' => (string) Str::uuid(), 'name' => "perf-seed-{$number}", 'created_at' => $now, 'updated_at' => $now]);
            $userId = DB::table('users')->insertGetId(['company_id' => $companyId, 'name' => "perf-seed-{$number}", 'email' => "perf-seed-{$number}@example.test", 'password' => 'perf-seed', 'role' => 'customer', 'created_at' => $now, 'updated_at' => $now]);
            $companies[] = ['id' => $companyId, 'user_id' => $userId, 'hot' => $number === 1];
        }

        return $companies;
    }

    /** 50,000 rows. The user of the hot company holds 20,000. The four other users share the rest. */
    private function seedApiLogs(array $companies, CarbonImmutable $now): void
    {
        $cold = array_slice($companies, 1);
        $perCold = intdiv(self::SEED_API_LOGS - self::SEED_HOT_COMPANY_API_LOGS, count($cold));

        $rows = [];
        $number = 0;
        $write = function (int $userId, int $count) use (&$rows, &$number, $now): void {
            foreach (range(1, $count) as $offset) {
                $number++;
                $rows[] = $this->apiLogRow("perf-seed-{$number}", $userId, $number % 10 === 0 ? 'GET' : 'POST', match ($number % 5) {
                    0 => 402, 1 => 201, default => 200
                }, $now->subMinutes($number % 40_000));
                $rows = $this->flush('api_logs', $rows);
            }
        };
        $write($companies[0]['user_id'], self::SEED_HOT_COMPANY_API_LOGS);
        foreach ($cold as $index => $company) {
            $write($company['user_id'], $index === count($cold) - 1 ? self::SEED_API_LOGS - $number : $perCold);
        }
        $this->flush('api_logs', $rows, force: true);
    }

    /**
     * 2,000 templates, three versions each, version three published.
     *
     * @return array<int, array<int, array{template_id: int, uuid: string, version_id: int}>> published sources by company id
     */
    private function seedTemplates(array $companies, CarbonImmutable $now): array
    {
        $rows = [];
        foreach ($companies as $company) {
            foreach (range(1, intdiv(self::SEED_TEMPLATES, self::SEED_COMPANIES)) as $offset) {
                $rows[] = ['user_id' => $company['user_id'], 'company_id' => $company['id'], 'uuid' => (string) Str::uuid(), 'title' => "perf-seed-{$offset}", 'width' => '20', 'height' => '20', 'size' => 'custom', 'layers' => '[]', 'created_at' => $now, 'updated_at' => $now];
                $rows = $this->flush('templates', $rows);
            }
        }
        $this->flush('templates', $rows, force: true);
        $templates = DB::table('templates')->whereIn('company_id', array_column($companies, 'id'))->get(['id', 'company_id', 'uuid']);
        $versions = [];
        foreach ($templates as $template) {
            foreach ([1, 2, 3] as $version) {
                $versions[] = ['template_id' => $template->id, 'version_number' => $version, 'layers' => '[]', 'groups' => '[]', 'width' => 20, 'height' => 20, 'published' => $version === 3, 'created_by' => null, 'created_at' => $now, 'updated_at' => $now];
                $versions = $this->flush('template_versions', $versions);
            }
        }
        $this->flush('template_versions', $versions, force: true);
        $versionIds = DB::table('template_versions')->whereIn('template_id', $templates->pluck('id'))->where('version_number', 3)->pluck('id', 'template_id');

        $sources = [];
        foreach ($templates as $template) {
            $sources[$template->company_id][] = ['template_id' => $template->id, 'uuid' => $template->uuid, 'version_id' => $versionIds[$template->id]];
        }

        return $sources;
    }

    /** 3,000 packages in mixed statuses with about 20,000 items in mixed statuses. */
    private function seedPackages(array $companies, array $sources, CarbonImmutable $now): void
    {
        $packages = [];
        $number = 0;
        foreach ($companies as $company) {
            foreach (range(1, intdiv(self::SEED_PACKAGES, self::SEED_COMPANIES)) as $offset) {
                $number++;
                $status = self::PACKAGE_STATUSES[$number % count(self::PACKAGE_STATUSES)];
                $aged = $status === 'building_aged';
                $packages[] = [
                    'uuid' => (string) Str::uuid(), 'company_id' => $company['id'], 'created_by' => $company['user_id'],
                    'operation_namespace' => 'creative_package_export_v1', 'idempotency_key' => "perf-seed-{$number}",
                    'request_fingerprint' => hash('sha256', "perf-seed-{$number}"), 'status' => $aged ? 'building' : $status,
                    'total_items' => self::ITEMS_PER_PACKAGE[$number % count(self::ITEMS_PER_PACKAGE)],
                    'manifest_version' => 1, 'started_at' => $now->subHours($number % 200),
                    'lease_expires_at' => $aged ? $now->subMinutes(10) : $now->addMinutes(5),
                    'completed_at' => in_array($status, ['ready', 'partial', 'failed'], true) ? $now->subHours($number % 200) : null,
                    'replay_expires_at' => $number % 10 === 0 ? $now->subHours($number % 48) : $now->addDays(7),
                    'created_at' => $now->subHours($number % 200), 'updated_at' => $now,
                ];
                $packages = $this->flush('creative_packages', $packages);
            }
        }
        $this->flush('creative_packages', $packages, force: true);

        $stored = DB::table('creative_packages')->where('idempotency_key', 'like', 'perf-seed-%')->orderBy('id')->get(['id', 'company_id', 'uuid', 'status', 'total_items', 'lease_expires_at']);
        $items = [];
        $number = 0;
        foreach ($stored as $package) {
            $companySources = $sources[$package->company_id];
            foreach (range(0, $package->total_items - 1) as $position) {
                $number++;
                $source = $companySources[$number % count($companySources)];
                $items[] = $this->seedItem($package, $position, $number, $source, $now);
                $items = $this->flush('creative_package_items', $items);
            }
        }
        $this->flush('creative_package_items', $items, force: true);
    }

    /** Insert the rows once they fill one chunk, or at once with `$force`. Returns the rows still pending. Keeps the peak memory at one chunk. */
    private function flush(string $table, array $rows, bool $force = false): array
    {
        if ($rows === [] || (! $force && count($rows) < self::CHUNK)) {
            return $rows;
        }
        DB::table($table)->insert($rows);

        return [];
    }

    /** @return array<string, mixed> */
    private function seedItem(object $package, int $position, int $number, array $source, CarbonImmutable $now): array
    {
        $status = match ($package->status) {
            'ready' => 'ready',
            'failed' => 'failed',
            'partial' => $position % 2 === 0 ? 'ready' : 'failed',
            default => $position % 3 === 0 ? 'rendering' : 'pending',
        };
        $retryable = $status === 'failed' ? $number % 4 === 0 : $status !== 'ready';

        return [
            'uuid' => (string) Str::uuid(), 'company_id' => $package->company_id, 'creative_package_id' => $package->id, 'position' => $position,
            'client_creative_id' => "creative-{$position}", 'label' => "Creative {$position}", 'alt_text' => 'A branded image', 'metadata' => null,
            'source_template_uuid' => $source['uuid'], 'source_template_version' => 3, 'source_template_version_id' => $source['version_id'],
            'requested_format' => 'png', 'status' => $status, 'attempts' => $status === 'pending' ? 0 : 1, 'retryable' => $retryable,
            'lease_token' => $status === 'rendering' ? hash('sha256', "perf-seed-lease-{$number}") : null, 'lease_generation' => $status === 'pending' ? 0 : 1,
            'lease_expires_at' => $status === 'rendering' ? ($number % 2 === 0 ? $now->addMinutes(4) : $now->subMinutes(1)) : null,
            'output_filename' => $status === 'ready' ? 'creative.png' : null, 'output_mime_type' => $status === 'ready' ? 'image/png' : null,
            'output_bytes' => $status === 'ready' ? 1 : null, 'output_width' => $status === 'ready' ? 20 : null, 'output_height' => $status === 'ready' ? 20 : null,
            'output_sha256' => $status === 'ready' ? str_repeat('a', 64) : null,
            'failure_code' => $status === 'failed' ? ($retryable ? 'creative_package_entitlement_required' : 'creative_package_render_failed') : null,
            'diagnostic_code' => $status === 'failed' ? 'renderer_exception' : null,
            'diagnostic_expires_at' => $status === 'failed' ? ($number % 3 === 0 ? $now->subHours(1) : $now->addDays(20)) : null,
            'started_at' => $status === 'pending' ? null : $now->subHours(1), 'completed_at' => in_array($status, ['ready', 'failed'], true) ? $now->subMinutes(30) : null,
            'created_at' => $now->subHours(1), 'updated_at' => $now,
        ];
    }
}
