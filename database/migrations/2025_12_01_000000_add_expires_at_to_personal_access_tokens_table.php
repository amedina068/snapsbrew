<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sanctum 3 reads/writes personal_access_tokens.expires_at. The original
     * table (2019_12_14) predates that column, so add it nullable. Existing
     * rows keep expires_at = null, and config/sanctum.php keeps expiration =>
     * null, so no current API token is retroactively expired.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('personal_access_tokens', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('last_used_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            if (Schema::hasColumn('personal_access_tokens', 'expires_at')) {
                $table->dropColumn('expires_at');
            }
        });
    }
};
