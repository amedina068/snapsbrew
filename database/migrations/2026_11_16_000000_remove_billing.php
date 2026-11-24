<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the Stripe billing, the trial and the credit balance.
 *
 * The usage log stays: `api_logs` keeps one row per request, and
 * `credits_spent` becomes `renders`, the number of images the request
 * rendered. The billing periods (`usage_logs`), the Cashier tables and the
 * reservation columns of the package items are deleted with their data.
 *
 * Each drop checks that its index, key or column exists, so the migration
 * runs on a database that a partial earlier deploy left behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dropIndexes('api_logs', ['api_logs_billing_key_unique', 'api_logs_period_usage_covering']);
        $this->dropColumns('api_logs', ['usage_log_id', 'billing_key']);

        if (Schema::hasColumn('api_logs', 'credits_spent')) {
            Schema::table('api_logs', function (Blueprint $table) {
                $table->renameColumn('credits_spent', 'renders');
            });
        }

        $this->dropForeignKeys('creative_package_items', ['usage_log_id']);
        $this->dropIndexes('creative_package_items', [
            'creative_package_items_billing_key_unique',
            'creative_package_items_capacity_index',
            'creative_package_items_expired_reservation_index',
        ]);
        $this->dropColumns('creative_package_items', [
            'billing_key', 'billing_status', 'usage_log_id', 'reserved_credits', 'charged_credits', 'reservation_expires_at',
        ]);
        $this->dropColumns('creative_packages', ['credits_charged']);

        foreach (['companies', 'users'] as $table) {
            $this->dropIndexes($table, ["{$table}_stripe_id_index"]);
            $this->dropColumns($table, ['stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at']);
        }

        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('usage_logs');
    }

    /**
     * The billing data is deleted, so no down() can bring it back. Restore a
     * database backup to undo this migration.
     */
    public function down(): void {}

    /** @param array<int, string> $names */
    private function dropIndexes(string $table, array $names): void
    {
        foreach ($names as $name) {
            if (Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
            }
        }
    }

    /** @param array<int, string> $columns */
    private function dropForeignKeys(string $table, array $columns): void
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (array_intersect($foreignKey['columns'], $columns) !== []) {
                // SQLite drops a foreign key by its columns only. MySQL needs
                // the real name, which differs from the derived one here.
                $key = Schema::getConnection()->getDriverName() === 'sqlite' ? $foreignKey['columns'] : $foreignKey['name'];
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($key));
            }
        }
    }

    /** @param array<int, string> $columns */
    private function dropColumns(string $table, array $columns): void
    {
        $existing = array_values(array_filter($columns, fn (string $column) => Schema::hasColumn($table, $column)));

        if ($existing !== []) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($existing));
        }
    }
};
