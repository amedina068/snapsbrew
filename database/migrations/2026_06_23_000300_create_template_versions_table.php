<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            // Stored as the same JSON string shape as templates.layers/groups so
            // renderableLayers() can return it verbatim (no cast — see model).
            $table->text('layers')->nullable();
            $table->text('groups')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->boolean('published')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['template_id', 'version_number']);
        });

        Schema::table('templates', function (Blueprint $table) {
            // Nullable: a template may have no published version yet (draft-only).
            $table->foreignId('published_version_id')->nullable()->after('groups')
                ->constrained('template_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_version_id');
        });

        Schema::dropIfExists('template_versions');
    }
};
