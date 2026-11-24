<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks when a company first completed a self-made (non-import,
 * non-showcase) snap. Used as the once-only gate for FirstSnapCreated so
 * the snap-completion hot path doesn't need an `exists()` + `find()` pair.
 * Existing companies with prior real snaps are backfilled so the dashboard
 * banner stays correct across the migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('first_snap_at')->nullable()->after('trial_ends_at');
        });

        // Backfill: oldest non-import, non-showcase snap per company.
        DB::table('companies')
            ->orderBy('id')
            ->chunkById(500, function ($companies) {
                foreach ($companies as $company) {
                    $firstSnap = DB::table('snaps')
                        ->where('company_id', $company->id)
                        ->whereNull('snap_import_id')
                        ->where(function ($q) {
                            $q->whereNull('metadata')
                                ->orWhere('metadata', 'not like', '%"onboarding":"showcase"%');
                        })
                        ->orderBy('created_at')
                        ->first(['created_at']);

                    if ($firstSnap) {
                        DB::table('companies')
                            ->where('id', $company->id)
                            ->update(['first_snap_at' => $firstSnap->created_at]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('first_snap_at');
        });
    }
};
