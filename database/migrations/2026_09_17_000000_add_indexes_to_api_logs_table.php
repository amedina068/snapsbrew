<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `api_logs` was created with no secondary index, and four later migrations
 * added none. This migration adds the first two.
 *
 * `url` carries the load. `SnapBatchController::snapshot()` runs
 * `where('url', 'snap-import:<uuid>')->sum('credits_spent')` on every
 * `GET /v1/snaps/batches/{uuid}` call. The command line interface polls that
 * endpoint every 2 to 30 seconds for the life of every batch, from every
 * concurrent session. Without an index each poll is a full scan of an
 * unbounded event table. The scan also evicts the buffer pool pages that the
 * write-heavy insert path needs.
 *
 * `uuid` is a stated scope exception. No query in this feature needs it. It
 * stays for three reasons. Every controller
 * response path runs `ApiLog::uuid(...)->first()` unindexed. This feature is
 * the traffic multiplier that makes the cost felt. The marginal cost is one
 * line, and both statements run in the same InnoDB `ALGORITHM=INPLACE,
 * LOCK=NONE` deploy. Read it as a decision, not as unrelated drift.
 *
 * The driver branch is unavoidable. `url` is a TEXT column, so MySQL rejects
 * an index over it without a key length, and SQLite rejects the key length.
 * `MySqlApiLogIndexTest` proves the MySQL branch against the real driver,
 * because continuous integration runs SQLite only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->needsPrefixLength()) {
            // ALGORITHM and LOCK are stated, not left to the default. InnoDB
            // picks this plan for `ADD INDEX` on its own, so the clause changes
            // nothing on the happy path. It makes an edge case that cannot honour
            // it fail the migration loudly in staging, instead of falling back to
            // ALGORITHM=COPY and taking a full table lock on a write-heavy table
            // for the length of the scan.
            DB::statement('ALTER TABLE api_logs '
                .'ADD INDEX api_logs_url_index (url(191)), '
                .'ADD INDEX api_logs_uuid_index (uuid), '
                .'ALGORITHM=INPLACE, LOCK=NONE');

            return;
        }

        Schema::table('api_logs', function (Blueprint $table) {
            $table->index('url');
            $table->index('uuid');
        });
    }

    public function down(): void
    {
        if ($this->needsPrefixLength()) {
            DB::statement('ALTER TABLE api_logs '
                .'DROP INDEX api_logs_url_index, '
                .'DROP INDEX api_logs_uuid_index');

            return;
        }

        Schema::table('api_logs', function (Blueprint $table) {
            $table->dropIndex('api_logs_url_index');
            $table->dropIndex('api_logs_uuid_index');
        });
    }

    private function needsPrefixLength(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
