<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two indexes with no reader. The reservation sum leads with usage_log_id
 * and reads the capacity index, and no query lists packages by
 * (company_id, created_at). Both were maintained on every write for nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creative_package_items', function (Blueprint $table) {
            $table->dropIndex('creative_package_items_expired_reservation_index');
        });
        Schema::table('creative_packages', function (Blueprint $table) {
            $table->dropIndex('creative_packages_company_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::table('creative_package_items', function (Blueprint $table) {
            $table->index(['billing_status', 'reservation_expires_at', 'id'], 'creative_package_items_expired_reservation_index');
        });
        Schema::table('creative_packages', function (Blueprint $table) {
            $table->index(['company_id', 'created_at', 'id'], 'creative_packages_company_lookup_index');
        });
    }
};
