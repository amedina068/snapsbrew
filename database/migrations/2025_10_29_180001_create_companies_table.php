<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });

        Schema::table('templates', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->nullable()
                ->after('user_id')
                ->constrained()
                ->cascadeOnDelete();
        });

        Schema::table('template_collections', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->nullable()
                ->after('user_id')
                ->constrained()
                ->cascadeOnDelete();
        });

        Schema::table('snaps', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->nullable()
                ->after('user_id')
                ->constrained()
                ->cascadeOnDelete();
        });

        Schema::table('forms', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->nullable()
                ->after('user_id')
                ->constrained()
                ->cascadeOnDelete();
        });

        DB::transaction(function () {
            $users = DB::table('users')
                ->select('id', 'name')
                ->get();

            foreach ($users as $user) {
                $companyId = DB::table('companies')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'name' => $user->name
                        ? "{$user->name}'s Company"
                        : 'Company '.$user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['company_id' => $companyId]);
            }

            $this->backfillCompanyFromUser('templates');
            $this->backfillCompanyFromUser('template_collections');
            $this->backfillCompanyFromUser('snaps');
            $this->backfillCompanyFromUser('forms');
        });
    }

    private function backfillCompanyFromUser(string $table): void
    {
        DB::table($table)
            ->whereNotNull('user_id')
            ->whereNull('company_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    $companyId = DB::table('users')
                        ->where('id', $row->user_id)
                        ->value('company_id');

                    if ($companyId) {
                        DB::table($table)
                            ->where('id', $row->id)
                            ->update(['company_id' => $companyId]);
                    }
                }
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
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });

        Schema::table('forms', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });

        Schema::table('template_collections', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });

        Schema::table('templates', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });

        Schema::dropIfExists('companies');
    }
};
