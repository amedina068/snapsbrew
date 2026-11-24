<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            // JSON array of layer groups: [{ id, name, visible, collapsed, order }].
            // Nullable / text (no model cast) to match the `layers` column
            // convention — values are passed pre-encoded.
            $table->text('groups')->nullable()->after('layers');
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn('groups');
        });
    }
};
