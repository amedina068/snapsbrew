<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CreativePackage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RequiresMysql;
use Tests\TestCase;

class MySqlCreativePackageSchemaTest extends TestCase
{
    use DatabaseTransactions;
    use RequiresMysql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireMysql();
    }

    /**
     * The same migrate run creates creative_packages empty, so no operator applies this index by hand.
     * The migration adds it in every environment (B-073). This test is now a plain regression pin:
     * the migration no longer reads the environment, so this only proves the index lands.
     */
    public function test_the_uuid_index_migration_adds_the_index_in_production(): void
    {
        $migration = require database_path('migrations/2026_09_22_000200_add_uuid_unique_index_to_creative_packages_table.php');
        $environment = $this->app['env'];
        // The DDL below commits the test transaction, so this test creates no rows.
        DB::statement('ALTER TABLE creative_packages DROP INDEX creative_packages_uuid_unique');

        try {
            $this->app['env'] = 'production';
            $migration->up();

            $this->assertTrue(Schema::hasIndex('creative_packages', 'creative_packages_uuid_unique'));
        } finally {
            $this->app['env'] = $environment;
            if (! Schema::hasIndex('creative_packages', 'creative_packages_uuid_unique')) {
                try {
                    $migration->up();
                } catch (\Throwable) {
                    // A repair failure here must not replace the assertion failure above.
                }
            }
        }
    }

    public function test_claim_identity_is_byte_exact_and_schema_guards_are_preserved(): void
    {
        $company = Company::factory()->create();
        CreativePackage::factory()->for($company)->create(['idempotency_key' => 'Launch-1']);
        CreativePackage::factory()->for($company)->create(['idempotency_key' => 'launch-1']);

        $this->assertSame(2, CreativePackage::whereBelongsTo($company)->count());

        $columns = collect(DB::select(
            "SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH, CHARACTER_SET_NAME,
                    COLLATION_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'creative_packages'"
        ))->keyBy('COLUMN_NAME');

        foreach (['operation_namespace' => 64, 'idempotency_key' => 100] as $name => $width) {
            $this->assertSame($width, $columns[$name]->CHARACTER_MAXIMUM_LENGTH);
            $this->assertSame('ascii', $columns[$name]->CHARACTER_SET_NAME);
            $this->assertSame('ascii_bin', $columns[$name]->COLLATION_NAME);
            $this->assertSame('NO', $columns[$name]->IS_NULLABLE);
        }
        $this->assertSame('creative_package_export_v1', $columns['operation_namespace']->COLUMN_DEFAULT);
        $this->assertNull($columns['idempotency_key']->COLUMN_DEFAULT);
        $this->assertSame('NO', $columns['lease_expires_at']->IS_NULLABLE);
        $this->assertSame('NO', $columns['started_at']->IS_NULLABLE);
        $this->assertSame('NO', $columns['replay_expires_at']->IS_NULLABLE);
        $this->assertSame('YES', $columns['completed_at']->IS_NULLABLE);

        $rules = collect(DB::select(
            "SELECT CONSTRAINT_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'creative_packages'"
        ))->pluck('DELETE_RULE', 'CONSTRAINT_NAME');

        $this->assertSame('CASCADE', $rules['creative_packages_company_id_foreign']);
        $this->assertSame('SET NULL', $rules['creative_packages_created_by_foreign']);
    }
}
