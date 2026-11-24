<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('kind', 32);
            $table->string('name', 100);
            $table->json('value');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'kind', 'name', 'version']);
            $table->index(['company_id', 'kind', 'retired_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_tokens');
    }
};
