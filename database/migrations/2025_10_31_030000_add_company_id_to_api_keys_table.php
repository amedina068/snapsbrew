<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });

        DB::table('api_keys')
            ->whereNull('company_id')
            ->orderBy('id')
            ->chunkById(500, function ($keys) {
                foreach ($keys as $key) {
                    $companyId = DB::table('users')
                        ->where('id', $key->user_id)
                        ->value('company_id');

                    if ($companyId) {
                        DB::table('api_keys')
                            ->where('id', $key->id)
                            ->update(['company_id' => $companyId]);
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
