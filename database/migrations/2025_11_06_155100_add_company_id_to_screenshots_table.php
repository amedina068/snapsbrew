<?php

use App\Models\Screenshot;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCompanyIdToScreenshotsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('screenshots', function (Blueprint $table) {
            $table->foreignId('company_id')->nullablet()->after('id');
        });

        Screenshot::query()->cursor()->each(function ($screenshot) {
            $user = User::find($screenshot->user_id);
            $screenshot->update([
                'company_id' => $user->company_id,
            ]);
        });

        Schema::table('screenshots', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable(false)->change();
        });

    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('screenshots', function (Blueprint $table) {
            $table->dropColumn('company_id');
        });
    }
}
