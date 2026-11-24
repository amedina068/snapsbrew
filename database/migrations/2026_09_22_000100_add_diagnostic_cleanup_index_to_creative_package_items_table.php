<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The diagnostic prune seeks items by (diagnostic_expires_at, id) with a limit. No index started with that column. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creative_package_items', function (Blueprint $table) {
            $table->index(['diagnostic_expires_at', 'id'], 'creative_package_items_diagnostic_cleanup_index');
        });
    }

    public function down(): void
    {
        Schema::table('creative_package_items', function (Blueprint $table) {
            $table->dropIndex('creative_package_items_diagnostic_cleanup_index');
        });
    }
};
