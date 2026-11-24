<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // 191 is the index-safe utf8mb4 length. The request rule carries the
            // same max, so an over-length key fails as 422 and never as a
            // QueryException from a strict-mode insert.
            $table->string('key', 191);
            $table->string('status', 20)->default('in_progress');

            // No foreign key. The row is pruned after 24 hours, and a snap
            // outlives its key by design. `snaps` is the busiest table in the
            // product and must not take an existence check on every insert.
            $table->unsignedBigInteger('snap_id')->nullable();
            $table->timestamps();

            // company_id first. Both columns are equality lookups, and the scope
            // is what stops one company reading another company's snap.
            $table->unique(['company_id', 'key']);

            // Serves the hourly prune. The table only ever holds about one day
            // of keyed requests, so this stays a cheap range seek.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
