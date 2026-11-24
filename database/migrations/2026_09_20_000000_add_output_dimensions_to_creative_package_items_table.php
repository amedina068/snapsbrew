<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creative_package_items', function (Blueprint $table) {
            $table->unsignedInteger('output_width')->nullable()->after('output_bytes');
            $table->unsignedInteger('output_height')->nullable()->after('output_width');
        });
    }

    public function down(): void
    {
        Schema::table('creative_package_items', function (Blueprint $table) {
            $table->dropColumn(['output_width', 'output_height']);
        });
    }
};
