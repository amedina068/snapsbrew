<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const INDEX = 'subscriptions_company_created_at_index';

    /**
     * Every environment adds the index with online DDL, so the Cashier
     * entitlement read deploys without a blocking DDL. An index that already
     * exists, for example from the runbook, is verified before it is recorded.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE INDEX IF NOT EXISTS '.self::INDEX.' ON subscriptions (company_id, created_at)');

            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Subscription entitlement index supports MySQL and SQLite only.');
        }

        if ($this->hasCompatibleIndex()) {
            return;
        }

        if ($this->indexExists()) {
            throw new RuntimeException('Existing subscriptions entitlement index is incompatible.');
        }

        $this->onlineMysqlDdl(
            'ALTER TABLE subscriptions ADD INDEX '.self::INDEX.' (company_id, created_at), '
            .'ALGORITHM=INPLACE, LOCK=NONE'
        );
    }

    /** Hot entitlement indexes are removed only by a reviewed forward migration. */
    public function down(): void {}

    private function hasCompatibleIndex(): bool
    {
        $index = $this->indexRows();

        return $index->pluck('column_name')->all() === ['company_id', 'created_at']
            && $index->every(fn ($row) => (int) $row->non_unique === 1
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
            ->where('table_name', 'subscriptions')
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
