<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The signed media route looks a package up by uuid alone, because a signed
 * URL carries no tenant. The only uuid index led with company_id, so that
 * read was a full scan of a table that grows one row per export.
 */
return new class extends Migration
{
    private const INDEX = 'creative_packages_uuid_unique';

    /**
     * The same migrate run creates the table empty, so no runbook step applies
     * this index by hand. The migration adds it in every environment.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX.' ON creative_packages (uuid)');

            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Creative package uuid index supports MySQL and SQLite only.');
        }

        if ($this->hasCompatibleIndex()) {
            return;
        }

        if ($this->indexExists()) {
            throw new RuntimeException('Existing creative_packages uuid index is incompatible.');
        }

        $this->onlineMysqlDdl(
            'ALTER TABLE creative_packages ADD UNIQUE INDEX '.self::INDEX.' (uuid), '
            .'ALGORITHM=INPLACE, LOCK=NONE'
        );
    }

    /** Hot serving indexes are removed only by a reviewed forward migration. */
    public function down(): void {}

    private function hasCompatibleIndex(): bool
    {
        $index = $this->indexRows();

        return $index->pluck('column_name')->all() === ['uuid']
            && $index->every(fn ($row) => (int) $row->non_unique === 0
                && $row->sub_part === null
                && $row->collation === 'A'
                && $row->index_type === 'BTREE'
                && (! $this->supportsVisibility() || $row->is_visible === 'YES'));
    }

    private function indexExists(): bool
    {
        return $this->indexRows()->isNotEmpty();
    }

    private function indexRows(): Collection
    {
        $fields = [
            'COLUMN_NAME as column_name',
            'NON_UNIQUE as non_unique',
            'SUB_PART as sub_part',
            'COLLATION as collation',
            'INDEX_TYPE as index_type',
        ];
        if ($this->supportsVisibility()) {
            $fields[] = 'IS_VISIBLE as is_visible';
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'creative_packages')
            ->where('index_name', self::INDEX)
            ->orderBy('seq_in_index')
            ->get($fields);
    }

    private function supportsVisibility(): bool
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', 'information_schema')
            ->where('table_name', 'STATISTICS')
            ->where('column_name', 'IS_VISIBLE')
            ->exists();
    }

    private function onlineMysqlDdl(string $sql): void
    {
        $original = (int) DB::scalar('SELECT @@SESSION.lock_wait_timeout');
        DB::statement('SET SESSION lock_wait_timeout = 5');

        try {
            DB::statement($sql);
        } finally {
            DB::statement("SET SESSION lock_wait_timeout = {$original}");
        }
    }
};
