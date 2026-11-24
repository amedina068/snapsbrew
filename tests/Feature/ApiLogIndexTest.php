<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `GET /v1/snaps/batches/{uuid}` sums `renders` over `api_logs.url`
 * on every poll, and the command line interface polls it in a loop. Without
 * these two indexes each poll is a full scan of an unbounded event table.
 *
 * This test runs on the driver of the current connection. Continuous
 * integration runs SQLite, so this is the check that guards every pull
 * request. `MySqlApiLogIndexTest` proves the same result on MySQL, where
 * the `url` index needs a prefix length.
 */
class ApiLogIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_indexes_the_api_logs_url_column(): void
    {
        $this->assertTrue(
            $this->hasIndex('api_logs', 'api_logs_url_index'),
            'api_logs.url has no index. The batch status poll full-scans the table.'
        );
    }

    public function test_it_indexes_the_api_logs_uuid_column(): void
    {
        $this->assertTrue(
            $this->hasIndex('api_logs', 'api_logs_uuid_index'),
            'api_logs.uuid has no index. Every ApiLog::uuid(...)->first() full-scans the table.'
        );
    }

    private function hasIndex(string $table, string $index): bool
    {
        return match (DB::getDriverName()) {
            'mysql' => DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists(),
            'sqlite' => collect(DB::select("PRAGMA index_list('{$table}')"))
                ->contains(fn ($row) => $row->name === $index),
            default => false,
        };
    }
}
