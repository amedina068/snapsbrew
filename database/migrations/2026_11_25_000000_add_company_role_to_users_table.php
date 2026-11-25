<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the role of a user in the company: `owner` or `member`.
 *
 * Every existing row gets the default `member`. The backfill then makes the
 * founder of each company an `owner`. The founder is the user with the lowest
 * id in the company, the same rule as the old `User::isCompanyFounder()`.
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
        $founderIds = DB::table('users')
            ->whereNotNull('company_id')
            ->groupBy('company_id')
            ->selectRaw('MIN(id) AS id')
            ->pluck('id');

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
