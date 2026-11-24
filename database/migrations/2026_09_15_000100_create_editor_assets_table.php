<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editor_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->char('sha256', 64);
            $table->string('mime_type', 32);
            $table->unsignedBigInteger('byte_size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamps();

            $table->unique(['company_id', 'sha256']);
            $table->index(['company_id', 'created_at', 'id']);
        });

        Schema::create('template_version_assets', function (Blueprint $table) {
            $table->foreignId('template_version_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('editor_asset_id')
                ->constrained()
                ->restrictOnDelete();
            $table->timestamps();

            $table->primary(['template_version_id', 'editor_asset_id']);
            $table->index('editor_asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_version_assets');
        Schema::dropIfExists('editor_assets');
    }
};
