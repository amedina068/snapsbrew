<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * This migration is intentionally additive. Production deployments may
     * apply its exact operations through the online-DDL runbook first; each
     * operation is then detected here so `artisan migrate` can safely record
     * the migration without attempting duplicate DDL.
     */
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            if (! Schema::hasColumn('templates', 'editor_document')) {
                $table->json('editor_document')->nullable();
            }
            if (! Schema::hasColumn('templates', 'editor_schema_version')) {
                $table->unsignedSmallInteger('editor_schema_version')->nullable();
            }
            if (! Schema::hasColumn('templates', 'editor_revision')) {
                $table->unsignedBigInteger('editor_revision')->nullable();
            }
            if (! $this->hasIndex('templates', 'templates_company_id_uuid_unique')) {
                $table->unique(['company_id', 'uuid']);
            }
        });

        Schema::table('template_versions', function (Blueprint $table) {
            if (! Schema::hasColumn('template_versions', 'editor_document')) {
                $table->json('editor_document')->nullable();
            }
            if (! Schema::hasColumn('template_versions', 'editor_schema_version')) {
                $table->unsignedSmallInteger('editor_schema_version')->nullable();
            }
            if (! Schema::hasColumn('template_versions', 'editor_revision')) {
                $table->unsignedBigInteger('editor_revision')->nullable();
            }
            if (! $this->hasIndex('template_versions', 'template_versions_template_id_version_number_unique')) {
                $table->unique(['template_id', 'version_number']);
            }
            if (! $this->hasIndex('template_versions', 'template_versions_template_id_id_unique')) {
                $table->unique(['template_id', 'id']);
            }
            // The original non-unique index also supports the template_id FK.
            // Add its unique replacement before removing it so MySQL never
            // leaves that foreign key without a supporting index.
            if ($this->hasIndex('template_versions', 'template_versions_template_id_version_number_index')) {
                $table->dropIndex(['template_id', 'version_number']);
            }
        });

        Schema::table('snaps', function (Blueprint $table) {
            if (! Schema::hasColumn('snaps', 'source_template_version_id')) {
                $table->foreignId('source_template_version_id')->nullable()->index();
            }
            if (! $this->hasIndex('snaps', 'snaps_template_id_source_template_version_id_index')) {
                $table->index(['template_id', 'source_template_version_id']);
            }
            if (! $this->hasForeignKey('snaps', 'snaps_template_id_source_template_version_id_foreign')) {
                $table->foreign(['template_id', 'source_template_version_id'])
                    ->references(['template_id', 'id'])
                    ->on('template_versions')
                    ->restrictOnUpdate()
                    ->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('snaps', function (Blueprint $table) {
            $table->dropForeign(['template_id', 'source_template_version_id']);
            $table->dropIndex(['template_id', 'source_template_version_id']);
            $table->dropIndex(['source_template_version_id']);
            $table->dropColumn('source_template_version_id');
        });

        Schema::table('template_versions', function (Blueprint $table) {
            $table->index(['template_id', 'version_number']);
            $table->dropUnique(['template_id', 'id']);
            $table->dropUnique(['template_id', 'version_number']);
            $table->dropColumn([
                'editor_document',
                'editor_schema_version',
                'editor_revision',
            ]);
        });

        Schema::table('templates', function (Blueprint $table) {
            // company_id is an existing FK; restore a supporting index before
            // removing the composite unique index that MySQL may be using.
            $table->index('company_id');
            $table->dropUnique(['company_id', 'uuid']);
            $table->dropColumn([
                'editor_document',
                'editor_schema_version',
                'editor_revision',
            ]);
        });
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

    private function hasForeignKey(string $table, string $foreignKey): bool
    {
        return match (DB::getDriverName()) {
            'mysql' => DB::table('information_schema.table_constraints')
                ->where('constraint_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('constraint_name', $foreignKey)
                ->where('constraint_type', 'FOREIGN KEY')
                ->exists(),
            'sqlite' => collect(DB::select("PRAGMA foreign_key_list('{$table}')"))
                ->contains(fn ($row) => $row->from === 'source_template_version_id'),
            default => false,
        };
    }
};
