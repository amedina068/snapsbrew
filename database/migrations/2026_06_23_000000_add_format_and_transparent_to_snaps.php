<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snaps', function (Blueprint $table) {
            // Output format for image snaps. Defaulted so existing rows render
            // exactly as before (PNG). `transparent` only applies to png/webp.
            $table->string('format', 10)->default('png')->after('type');
            $table->boolean('transparent')->default(false)->after('format');
        });
    }

    public function down(): void
    {
        Schema::table('snaps', function (Blueprint $table) {
            $table->dropColumn(['format', 'transparent']);
        });
    }
};
