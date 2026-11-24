<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('onboarding_completed_at')->nullable()->after('trial_ends_at');
        });

        // Existing users predate the onboarding flow — treat them as completed
        // so they are never bounced into the wizard.
        DB::table('users')->update(['onboarding_completed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('onboarding_completed_at');
        });
    }
};
