<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\RequiresMysql;
use Tests\TestCase;

/**
 * A prefix index over a TEXT column has no portable expression, so the
 * migration branches on the driver. The branch that continuous integration
 * never executes is the branch that reaches production, so prove it against
 * the real driver. Run this lane after `migrate:fresh` on MySQL.
 */
class MySqlApiLogIndexTest extends TestCase
{
    use RequiresMysql;

    public function test_it_indexes_the_api_logs_url_column_with_a_prefix_length(): void
    {
        $this->requireMysql();

        $index = $this->indexColumn('api_logs', 'api_logs_url_index');

        $this->assertNotNull($index, 'api_logs_url_index is absent on MySQL.');
        $this->assertSame('url', $index->COLUMN_NAME);
        $this->assertSame(
            191,
            (int) $index->SUB_PART,
            'MySQL rejects an index over a TEXT column without a key length.'
        );
    }

    public function test_it_indexes_the_api_logs_uuid_column(): void
    {
        $this->requireMysql();

        $index = $this->indexColumn('api_logs', 'api_logs_uuid_index');

        $this->assertNotNull($index, 'api_logs_uuid_index is absent on MySQL.');
        $this->assertSame('uuid', $index->COLUMN_NAME);
    }

    private function indexColumn(string $table, string $index): ?object
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->first();
    }
}
