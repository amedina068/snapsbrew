<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier 15 renamed the subscriptions `name` column to `type`.
 *
 * On an already-migrated (prod) database the published Cashier 15
 * `2019_05_03_000002_create_subscriptions_table` migration is skipped — its
 * basename was already recorded when Cashier 14 auto-loaded the vendor copy —
 * so the table still has the old `name` column and Cashier 15 runtime queries
 * (which read `type`) would break. This renames it.
 *
 * On a fresh database the published 2019 migration already creates `type`, so
 * the guard makes this a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('subscriptions', 'name') && ! Schema::hasColumn('subscriptions', 'type')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->renameColumn('name', 'type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('subscriptions', 'type') && ! Schema::hasColumn('subscriptions', 'name')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->renameColumn('type', 'name');
            });
        }
    }
};
