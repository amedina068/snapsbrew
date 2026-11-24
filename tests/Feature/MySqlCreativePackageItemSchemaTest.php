<?php

namespace Tests\Feature;

use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use App\Support\CreativePackageContract;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ExplainsCapturedQueries;
use Tests\TestCase;

class MySqlCreativePackageItemSchemaTest extends TestCase
{
    use DatabaseTransactions;
    use ExplainsCapturedQueries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireMysql();
    }

    public function test_physical_schema_guards_byte_identity_and_ordered_access(): void
    {
        $package = CreativePackage::factory()->create();
        foreach (['Launch-A', 'launch-a'] as $position => $clientId) {
            CreativePackageItem::factory()->for($package, 'package')->create([
                'company_id' => $package->company_id, 'client_creative_id' => $clientId, 'position' => $position,
            ]);
        }

        $columns = collect(DB::select(
            "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_SET_NAME, COLLATION_NAME
             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'creative_package_items'"
        ))->keyBy('COLUMN_NAME');
        $expected = [
            'id' => ['bigint unsigned', 'NO', null], 'uuid' => ['char(36)', 'NO', null],
            'company_id' => ['bigint unsigned', 'NO', null], 'creative_package_id' => ['bigint unsigned', 'NO', null],
            'position' => ['smallint unsigned', 'NO', null], 'client_creative_id' => ['varchar(100)', 'NO', null],
            'client_family_id' => ['varchar(100)', 'YES', null], 'label' => ['varchar(200)', 'NO', null],
            'placement_key' => ['varchar(150)', 'YES', null], 'caption' => ['varchar(2200)', 'YES', null],
            'alt_text' => ['varchar(1000)', 'NO', null], 'metadata' => ['json', 'YES', null],
            'source_template_uuid' => ['char(36)', 'NO', null], 'source_template_version' => ['int unsigned', 'NO', null],
            'source_editor_document_version' => ['smallint unsigned', 'YES', null],
            'source_template_version_id' => ['bigint unsigned', 'YES', null],
            'requested_format' => ['varchar(8)', 'NO', null], 'status' => ['varchar(16)', 'NO', 'pending'],
        ];
        foreach ($expected as $name => $definition) {
            $this->assertSame($definition, [
                $columns[$name]->COLUMN_TYPE, $columns[$name]->IS_NULLABLE, $columns[$name]->COLUMN_DEFAULT,
            ], $name);
        }
        $this->assertSame(['ascii', 'ascii_bin'], [
            $columns['client_creative_id']->CHARACTER_SET_NAME, $columns['client_creative_id']->COLLATION_NAME,
        ]);

        $keys = collect(DB::select(
            "SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_COLUMN_NAME, ORDINAL_POSITION
             FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA = DATABASE()
             AND TABLE_NAME = 'creative_package_items' AND REFERENCED_TABLE_NAME IS NOT NULL"
        ))->groupBy('CONSTRAINT_NAME');
        $tenant = $keys['creative_package_items_package_tenant_foreign']->sortBy('ORDINAL_POSITION');
        $this->assertSame(['company_id', 'creative_package_id'], $tenant->pluck('COLUMN_NAME')->all());
        $this->assertSame(['company_id', 'id'], $tenant->pluck('REFERENCED_COLUMN_NAME')->all());
        $rules = collect(DB::select(
            "SELECT CONSTRAINT_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'creative_package_items'"
        ))->pluck('DELETE_RULE', 'CONSTRAINT_NAME');
        $this->assertSame('CASCADE', $rules['creative_package_items_package_tenant_foreign']);
        $this->assertSame('SET NULL', $rules['creative_package_items_source_version_foreign']);

        $indexes = collect(DB::select(
            "SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list
             FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'creative_package_items' GROUP BY INDEX_NAME, NON_UNIQUE"
        ))->mapWithKeys(fn ($row) => [$row->INDEX_NAME => [(int) $row->NON_UNIQUE, $row->columns_list]]);
        $requiredIndexes = collect([
            'creative_package_items_company_uuid_unique' => [0, 'company_id,uuid'],
            'creative_package_items_package_position_unique' => [0, 'company_id,creative_package_id,position'],
            'creative_package_items_package_client_unique' => [0, 'company_id,creative_package_id,client_creative_id'],
            'creative_package_items_source_version_index' => [1, 'source_template_version_id'],
            'creative_package_items_ordered_relation_index' => [1, 'creative_package_id,position,id'],
        ])->sortKeys();
        $this->assertSame($requiredIndexes->all(), $indexes->only($requiredIndexes->keys())->sortKeys()->all());
        $ordered = $this->explainSql(
            'SELECT id FROM creative_package_items FORCE INDEX (creative_package_items_ordered_relation_index)
             WHERE creative_package_id = ? ORDER BY position, id', [$package->id]
        );
        // The type and the key prove the seek. The row estimate of a ref lookup reads InnoDB statistics that
        // lag behind the rollbacks of earlier classes (two rows read as 14 after CreativePackageMediaTest), so
        // this assertion states no bound.
        $this->assertAccessPlan($ordered['plan'][0], 'creative_package_items_ordered_relation_index', ['ref'], null);
    }

    public function test_execution_schema_guards_exact_lease_identity_and_serving_indexes(): void
    {
        foreach ([str_repeat('A', 64), str_repeat('a', 64)] as $leaseToken) {
            CreativePackageItem::factory()->create(['lease_token' => $leaseToken]);
        }
        $this->assertQueryFails(fn () => CreativePackageItem::factory()->create(['lease_token' => str_repeat('A', 64)]));

        $columns = collect(DB::select(
            "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_SET_NAME, COLLATION_NAME
             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'creative_package_items'"
        ))->keyBy('COLUMN_NAME');
        $expected = [
            'attempts' => ['tinyint unsigned', 'NO', '0'], 'retryable' => ['tinyint(1)', 'NO', '1'],
            'lease_token' => ['char(64)', 'YES', null], 'lease_generation' => ['int unsigned', 'NO', '0'],
            'lease_expires_at' => ['timestamp', 'YES', null], 'snap_id' => ['bigint unsigned', 'YES', null],
            'media_id' => ['bigint unsigned', 'YES', null], 'api_log_id' => ['bigint unsigned', 'YES', null],
            'output_filename' => ['varchar(255)', 'YES', null], 'output_mime_type' => ['varchar(100)', 'YES', null],
            'output_bytes' => ['bigint unsigned', 'YES', null], 'output_width' => ['int unsigned', 'YES', null],
            'output_height' => ['int unsigned', 'YES', null], 'output_sha256' => ['char(64)', 'YES', null],
            'failure_code' => ['varchar(100)', 'YES', null], 'failure_details' => ['varchar(2048)', 'YES', null],
            'diagnostic_code' => ['varchar(100)', 'YES', null], 'diagnostic_exception_class' => ['varchar(255)', 'YES', null],
            'diagnostic_correlation_id' => ['varchar(100)', 'YES', null], 'diagnostic_message_fingerprint' => ['char(64)', 'YES', null],
            'diagnostic_expires_at' => ['timestamp', 'YES', null], 'started_at' => ['timestamp', 'YES', null],
            'completed_at' => ['timestamp', 'YES', null],
        ];
        foreach ($expected as $name => $definition) {
            $this->assertSame($definition, [
                $columns[$name]->COLUMN_TYPE, $columns[$name]->IS_NULLABLE, $columns[$name]->COLUMN_DEFAULT,
            ], $name);
        }
        // The diagnostic of one item is capped by the physical widths of its columns, read from the schema.
        $widths = $columns->filter(fn ($column, $name) => str_starts_with($name, 'diagnostic_'))
            ->map(fn ($column) => preg_match('/\((\d+)\)/', $column->COLUMN_TYPE, $width) === 1 ? (int) $width[1] : 19);
        $this->assertCount(5, $widths);
        $this->assertLessThanOrEqual(CreativePackageContract::DIAGNOSTIC_BYTES, $widths->sum());
        $this->assertSame(CreativePackageContract::DIAGNOSTIC_CLASS_BYTES, $widths['diagnostic_exception_class']);
        $this->assertSame(['ascii', 'ascii_bin'], [
            $columns['lease_token']->CHARACTER_SET_NAME, $columns['lease_token']->COLLATION_NAME,
        ]);

        $keys = collect(DB::select(
            "SELECT CONSTRAINT_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'creative_package_items'"
        ))->pluck('DELETE_RULE', 'CONSTRAINT_NAME');
        foreach (['snap', 'media', 'api_log'] as $relation) {
            $this->assertSame('SET NULL', $keys["creative_package_items_{$relation}_foreign"]);
        }

        $indexes = collect(DB::select(
            "SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list
             FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'creative_package_items' GROUP BY INDEX_NAME, NON_UNIQUE"
        ))->mapWithKeys(fn ($row) => [$row->INDEX_NAME => [(int) $row->NON_UNIQUE, $row->columns_list]]);
        $expectedIndexes = [
            'creative_package_items_lease_token_unique' => [0, 'lease_token'],
            'creative_package_items_reconciliation_index' => [1, 'status,retryable,lease_expires_at,id'],
            'creative_package_items_snap_index' => [1, 'snap_id'],
            'creative_package_items_media_index' => [1, 'media_id'],
            'creative_package_items_api_log_index' => [1, 'api_log_id'],
        ];
        ksort($expectedIndexes);
        $this->assertSame($expectedIndexes, $indexes->only(array_keys($expectedIndexes))->sortKeys()->all());
    }

    /**
     * The billing removal has no down(), so the older execution migration
     * cannot round trip any more. Each drop of the removal checks that its
     * target exists, so a second run on the current schema changes nothing.
     */
    public function test_the_billing_removal_migration_runs_again_without_a_change(): void
    {
        $migration = require database_path('migrations/2026_11_16_000000_remove_billing.php');
        $indexes = $this->indexSnapshot();
        $columns = Schema::getColumnListing('creative_package_items');

        $migration->up();

        $this->assertSame($indexes, $this->indexSnapshot());
        $this->assertSame($columns, Schema::getColumnListing('creative_package_items'));
    }

    /** Every index of creative_package_items as [name, sequence, column, non_unique], in index order. */
    private function indexSnapshot(): array
    {
        return collect(DB::select('SHOW INDEX FROM creative_package_items'))
            ->map(fn (object $row) => [$row->Key_name, (int) $row->Seq_in_index, $row->Column_name, (int) $row->Non_unique])
            ->sort()->values()->all();
    }

    private function assertQueryFails(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a database constraint violation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
