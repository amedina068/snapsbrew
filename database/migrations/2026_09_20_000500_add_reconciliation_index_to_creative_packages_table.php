<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The reconciler scans building packages by (status, lease_expires_at, id) with a limit. No index started with status. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creative_packages', function (Blueprint $table) {
            $table->index(['status', 'lease_expires_at', 'id'], 'creative_packages_reconciliation_index');
        });
    }

    public function down(): void
    {
        Schema::table('creative_packages', function (Blueprint $table) {
            $table->dropIndex('creative_packages_reconciliation_index');
        });
    }
};
