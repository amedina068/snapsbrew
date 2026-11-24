<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bus::batch() reads and writes this table through DatabaseBatchRepository.
 *
 * The table was never created. `config/queue.php` declares no `batching`
 * section, so Laravel falls back to the table name `job_batches` on the default
 * connection, and every bulk import failed against a table that did not exist.
 * The column shape is the framework default and must stay that way, because the
 * repository writes it directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('job_batches')) {
            return;
        }

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_batches');
    }
};
