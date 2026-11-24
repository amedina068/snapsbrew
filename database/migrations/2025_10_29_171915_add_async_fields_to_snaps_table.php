<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('snaps', function (Blueprint $table) {
            $table->boolean('is_async')->default(false)->after('type');
            $table->string('status')->default('completed')->after('is_async');
            $table->string('webhook_url', 2048)->nullable()->after('status');
            $table->text('last_error')->nullable()->after('webhook_url');
            $table->timestamp('completed_at')->nullable()->after('last_error');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('snaps', function (Blueprint $table) {
            $table->dropColumn([
                'is_async',
                'status',
                'webhook_url',
                'last_error',
                'completed_at',
            ]);
        });
    }
};
