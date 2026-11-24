<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait RequiresMysql
{
    /** Skip a MySQL-only test on another driver. It fails instead when REQUIRE_MYSQL_LEDGER_GATE is set. */
    private function requireMysql(): void
    {
        if (DB::getDriverName() === 'mysql') {
            return;
        }
        if (filter_var(env('REQUIRE_MYSQL_LEDGER_GATE'), FILTER_VALIDATE_BOOL)) {
            $this->fail('The MySQL-only test is not running on MySQL.');
        }
        $this->markTestSkipped('The MySQL-only test requires MySQL.');
    }
}
