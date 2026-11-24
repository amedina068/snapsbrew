<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A snap can render from an inline design: a `template_versions` row with no
 * parent template. A null `template_id` on a version means "an inline design,
 * rendered by one snap". The table keeps its name; a second source table would
 * make `snaps.source_template_version_id` polymorphic and fork the one
 * relation `ProcessSnap` reads.
 *
 * The composite unique indexes on `template_versions` accept repeated
 * `(NULL, 1)` rows, because MySQL and SQLite treat every NULL as distinct.
 *
 * The composite foreign key `snaps(template_id, source_template_version_id)`
 * is MATCH SIMPLE: it skips a row whose `template_id` is NULL, so it enforces
 * nothing for an inline snap. The single-column foreign key added here
 * enforces for every snap, because `source_template_version_id` is set on
 * every real render.
 *
 * Deploy notes (MySQL 8, `snaps` is the hot table):
 * - `MODIFY COLUMN ... NULL` runs INPLACE with LOCK=NONE. The table is rebuilt
 *   and concurrent DML continues. Run it off-peak. A repeat is a harmless
 *   second rebuild.
 * - `ADD FOREIGN KEY` runs INPLACE only with `foreign_key_checks=0`; with
 *   checks on it is a COPY. Decide which before the deploy.
 * - Orphan pre-check, must return 0 before this runs:
 *   SELECT COUNT(*) FROM snaps s
 *     LEFT JOIN template_versions tv ON s.source_template_version_id = tv.id
 *    WHERE s.source_template_version_id IS NOT NULL AND tv.id IS NULL
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('template_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('template_id')->nullable()->change();
        });

        Schema::table('snaps', function (Blueprint $table) {
            $table->unsignedBigInteger('template_id')->nullable()->change();

            // A DBA can apply the FK first as online DDL; Artisan then runs
            // this migration only to record it. The nullable change repeats
            // without error, the FK does not, so only the FK is guarded.
            if (! $this->hasForeignKey('snaps', 'snaps_source_template_version_id_foreign')) {
                $table->foreign('source_template_version_id')
                    ->references('id')
                    ->on('template_versions')
                    ->restrictOnUpdate()
                    ->restrictOnDelete();
            }
        });
    }

    /**
     * Fails with a NOT NULL violation once an inline snap exists. That is the
     * right outcome: a rollback that drops or rewrites rendered snaps is a data
     * decision, not a migration's.
     */
    public function down(): void
    {
        Schema::table('snaps', function (Blueprint $table) {
            $table->dropForeign(['source_template_version_id']);
            $table->unsignedBigInteger('template_id')->nullable(false)->change();
        });

        Schema::table('template_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('template_id')->nullable(false)->change();
        });
    }

    private function hasForeignKey(string $table, string $foreignKey): bool
    {
        return match (DB::getDriverName()) {
            'mysql' => DB::table('information_schema.table_constraints')
                ->where('constraint_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('constraint_name', $foreignKey)
                ->where('constraint_type', 'FOREIGN KEY')
                ->exists(),
            // SQLite names no constraint. The composite FK also lists this
            // column, so match a key that holds this one column and no other.
            'sqlite' => collect(DB::select("PRAGMA foreign_key_list('{$table}')"))
                ->groupBy('id')
                ->contains(fn ($columns) => $columns->count() === 1
                    && $columns->first()->from === 'source_template_version_id'
                    && $columns->first()->table === 'template_versions'),
            default => false,
        };
    }
};
