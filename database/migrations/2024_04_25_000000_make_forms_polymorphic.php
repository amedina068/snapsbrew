<?php

use App\Models\Template;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->nullableMorphs('formable');
        });

        DB::table('forms')
            ->select('id', 'template_id')
            ->orderBy('id')
            ->chunkById(100, function ($forms) {
                foreach ($forms as $form) {
                    if (! $form->template_id) {
                        continue;
                    }

                    DB::table('forms')
                        ->where('id', $form->id)
                        ->update([
                            'formable_id' => $form->template_id,
                            'formable_type' => Template::class,
                        ]);
                }
            });

        Schema::table('forms', function (Blueprint $table) {
            if (Schema::hasColumn('forms', 'template_id')) {
                // Drop the index before the column: Laravel 11's SQLite grammar
                // no longer removes dependent indexes automatically, so a bare
                // dropColumn leaves forms_template_id_index dangling and errors.
                if (Schema::hasIndex('forms', ['template_id'])) {
                    $table->dropIndex(['template_id']);
                }
                $table->dropColumn('template_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->foreignId('template_id')->nullable()->index()->after('formable_type');
        });

        DB::table('forms')
            ->select('id', 'formable_id', 'formable_type')
            ->orderBy('id')
            ->chunkById(100, function ($forms) {
                foreach ($forms as $form) {
                    if ($form->formable_type !== Template::class) {
                        continue;
                    }

                    DB::table('forms')
                        ->where('id', $form->id)
                        ->update([
                            'template_id' => $form->formable_id,
                        ]);
                }
            });

        Schema::table('forms', function (Blueprint $table) {
            $table->dropMorphs('formable');
        });
    }
};
