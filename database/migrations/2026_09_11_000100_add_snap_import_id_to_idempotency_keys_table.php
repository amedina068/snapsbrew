<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A claim can now point at a batch as well as at one snap.
 *
 * The batch API accepts the same `idempotency_key` the single create accepts. A
 * repeated key must return the batch the first call made, so the claim needs a
 * second nullable target. There is no polymorphic `subject_type`: two callers
 * exist and no third one is named, and exactly one of the two columns is set on
 * a completed claim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            // No foreign key, for the reason the `snap_id` column already
            // carries: the row is pruned after 24 hours and the batch outlives
            // its key.
            $table->unsignedBigInteger('snap_import_id')->nullable()->after('snap_id');
        });
    }

    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->dropColumn('snap_import_id');
        });
    }
};
