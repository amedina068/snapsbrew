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
        Schema::create('snap_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->index();
            $table->foreignId('company_id')->index();
            $table->foreignId('user_id')->index();
            $table->string('uuid')->index();
            $table->string('status')->default('pending');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->string('batch_id')->nullable()->index();
            $table->string('original_filename')->nullable();
            $table->string('stored_path')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('snap_imports');
    }
};
