<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Signed-URL requests carry a large base64 `variants` param, so the
     * full URL routinely exceeds VARCHAR(255) and the ApiLog insert throws
     * SQLSTATE[22001] "Data too long for column 'url'".
     */
    public function up(): void
    {
        Schema::table('api_logs', function (Blueprint $table) {
            $table->text('url')->change();
        });
    }

    public function down(): void
    {
        Schema::table('api_logs', function (Blueprint $table) {
            $table->string('url')->change();
        });
    }
};
