<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Each company signs its webhooks with its own secret. The column holds the
 * secret encrypted with APP_KEY. The backfill gives every existing company a
 * secret, so the first webhook after the deploy is already signed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->text('webhook_secret')->nullable();
        });

        DB::table('companies')->whereNull('webhook_secret')->chunkById(100, function ($companies) {
            foreach ($companies as $company) {
                DB::table('companies')->where('id', $company->id)->update([
                    'webhook_secret' => Crypt::encryptString('whsec_'.Str::random(40)),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('webhook_secret');
        });
    }
};
