<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the role of a user in the company: `owner` or `member`.
 *
 * Every existing row gets the default `member`. The backfill then makes the
 * founder of each company an `owner`. The founder is the active user with the
 * lowest id in the company. A deactivated user cannot manage the company, so
 * the backfill skips a deactivated founder. If no user of the company is
 * active, the founder is the user with the lowest id.
 *
 * The backfill reads the founder ids first and then updates them in chunks.
 * One `UPDATE ... WHERE id IN (SELECT ... FROM users)` statement fails on
 * MySQL with error 1093, because it reads and writes the same table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'company_role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('company_role', 16)->default('member')->after('company_id');
            });
        }

        $this->backfill();
    }

    public function backfill(): void
    {
        $activeFounders = DB::table('users')
            ->whereNotNull('company_id')
            ->whereNull('deactivated_at')
            ->groupBy('company_id')
            ->selectRaw('company_id, MIN(id) AS id')
            ->pluck('id', 'company_id');

        $founders = DB::table('users')
            ->whereNotNull('company_id')
            ->groupBy('company_id')
            ->selectRaw('company_id, MIN(id) AS id')
            ->pluck('id', 'company_id');

        $founderIds = $founders->replace($activeFounders)->values();

        foreach ($founderIds->chunk(500) as $chunk) {
            DB::table('users')->whereIn('id', $chunk->all())->update(['company_role' => 'owner']);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'company_role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('company_role');
            });
        }
    }
};
