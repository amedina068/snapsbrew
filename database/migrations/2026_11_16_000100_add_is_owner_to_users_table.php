<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The owner runs the instance: only the owner can open Horizon. The `admin`
 * role stays a separate product role. The users that hold `admin` today keep
 * their access, because they become owners here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_owner')->default(false);
        });

        DB::table('users')->where('role', 'admin')->update(['is_owner' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_owner');
        });
    }
};
