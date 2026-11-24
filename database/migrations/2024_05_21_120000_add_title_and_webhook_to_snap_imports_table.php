<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snap_imports', function (Blueprint $table) {
            $table->string('title')->nullable()->after('status');
            $table->string('webhook_url')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('snap_imports', function (Blueprint $table) {
            $table->dropColumn(['title', 'webhook_url']);
        });
    }
};
