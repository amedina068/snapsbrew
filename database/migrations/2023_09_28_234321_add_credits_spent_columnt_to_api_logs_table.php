<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCreditsSpentColumntToApiLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('api_logs', function (Blueprint $table) {
            $table->integer('credits_spent')->default(1)->after('usage_log_id');
            $table->string('type')->nullable()->after('ip_address');
            $table->string('feature')->nullable()->after('ip_address');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('api_logs', function (Blueprint $table) {
            //
        });
    }
}
