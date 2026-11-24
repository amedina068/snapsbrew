<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'api_logs_billing_key_unique';

    private const USAGE_INDEX = 'api_logs_period_usage_covering';

    /**
     * Every environment adds the column and the indexes with online DDL. An
     * object that already exists, for example from the runbook, is verified
     * before this migration records it.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->upSqlite();

            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Billing ledger DDL supports MySQL and SQLite only.');
        }

        if (! Schema::hasColumn('api_logs', 'billing_key')) {
            $this->onlineMysqlDdl(
                'ALTER TABLE api_logs ADD COLUMN billing_key VARCHAR(191) NULL, '
                .'ALGORITHM=INPLACE, LOCK=NONE'
            );
        }
        $this->assertMysqlColumn();

        $this->addMysqlIndex(
            self::UNIQUE_INDEX,
            'UNIQUE INDEX '.self::UNIQUE_INDEX.' (billing_key)',
            ['billing_key'],
            true,
        );
        $this->addMysqlIndex(
            self::USAGE_INDEX,
            'INDEX '.self::USAGE_INDEX.' (usage_log_id, method, status_code, credits_spent)',
            ['usage_log_id', 'method', 'status_code', 'credits_spent'],
            false,
        );
    }

    /** Hot-ledger indexes are removed only by a separately reviewed forward migration. */
    public function down(): void {}

    private function upSqlite(): void
    {
        if (! Schema::hasColumn('api_logs', 'billing_key')) {
            Schema::table('api_logs', function (Blueprint $table): void {
                $table->string('billing_key', 191)->nullable();
            });
        }

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.self::UNIQUE_INDEX
            .' ON api_logs (billing_key)');
        DB::statement('CREATE INDEX IF NOT EXISTS '.self::USAGE_INDEX
            .' ON api_logs (usage_log_id, method, status_code, credits_spent)');
    }

    /** @param list<string> $columns */
    private function addMysqlIndex(
        string $name,
        string $definition,
        array $columns,
        bool $unique,
    ): void {
        $supportsVisibility = DB::table('information_schema.columns')
            ->where('table_schema', 'information_schema')
            ->where('table_name', 'STATISTICS')
            ->where('column_name', 'IS_VISIBLE')
            ->exists();
        $fields = [
            'COLUMN_NAME as column_name',
            'NON_UNIQUE as non_unique',
            'SUB_PART as sub_part',
            'COLLATION as collation',
            'INDEX_TYPE as index_type',
        ];
        if ($supportsVisibility) {
            $fields[] = 'IS_VISIBLE as is_visible';
        }
        $existing = DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'api_logs')
            ->where('index_name', $name)
            ->orderBy('seq_in_index')
            ->get($fields);

        if ($existing->isNotEmpty()) {
            $compatible = $existing->pluck('column_name')->all() === $columns
                && $existing->every(fn ($row) => (int) $row->non_unique === ($unique ? 0 : 1)
                    && $row->sub_part === null
                    && $row->collation === 'A'
                    && $row->index_type === 'BTREE'
                    && (! $supportsVisibility || $row->is_visible === 'YES')
                );
            if (! $compatible) {
                throw new RuntimeException("Existing index {$name} is incompatible.");
            }

            return;
        }

        $this->onlineMysqlDdl(
            "ALTER TABLE api_logs ADD {$definition}, ALGORITHM=INPLACE, LOCK=NONE"
        );
    }

    private function assertMysqlColumn(): void
    {
        $tableCollation = DB::table('information_schema.tables')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'api_logs')
            ->value('TABLE_COLLATION');
        $tableCharset = DB::table('information_schema.collations')
            ->where('COLLATION_NAME', $tableCollation)
            ->value('CHARACTER_SET_NAME');
        $column = DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'api_logs')
            ->where('column_name', 'billing_key')
            ->first([
                'DATA_TYPE as data_type',
                'CHARACTER_MAXIMUM_LENGTH as character_maximum_length',
                'IS_NULLABLE as is_nullable',
                'COLUMN_DEFAULT as column_default',
                'CHARACTER_SET_NAME as character_set_name',
                'COLLATION_NAME as collation_name',
                'EXTRA as extra',
                'GENERATION_EXPRESSION as generation_expression',
            ]);

        if (! $column
            || $column->data_type !== 'varchar'
            || (int) $column->character_maximum_length !== 191
            || $column->is_nullable !== 'YES'
            || $column->column_default !== null
            || $column->character_set_name !== $tableCharset
            || $column->collation_name !== $tableCollation
            || trim((string) $column->extra) !== ''
            || trim((string) $column->generation_expression) !== '') {
            throw new RuntimeException('Existing api_logs.billing_key is incompatible.');
        }
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
