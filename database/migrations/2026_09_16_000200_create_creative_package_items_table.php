<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isMySql = Schema::getConnection()->getDriverName() === 'mysql';

        Schema::create('creative_package_items', function (Blueprint $table) use ($isMySql) {
            $table->id();
            $table->uuid('uuid');
            $table->foreignId('company_id');
            $table->foreignId('creative_package_id');
            $table->unsignedSmallInteger('position');
            $clientId = $table->string('client_creative_id', 100);
            if ($isMySql) {
                $clientId->charset('ascii')->collation('ascii_bin');
            }
            $table->string('client_family_id', 100)->nullable();
            $table->string('label', 200);
            $table->string('placement_key', 150)->nullable();
            $table->string('caption', 2200)->nullable();
            $table->string('alt_text', 1000);
            $table->json('metadata')->nullable();
            $table->uuid('source_template_uuid');
            $table->unsignedInteger('source_template_version');
            $table->unsignedSmallInteger('source_editor_document_version')->nullable();
            $table->foreignId('source_template_version_id')->nullable();
            $table->string('requested_format', 8);
            $table->string('status', 16)->default('pending');
            $table->timestamps();

            $table->unique(['company_id', 'uuid'], 'creative_package_items_company_uuid_unique');
            $table->unique(
                ['company_id', 'creative_package_id', 'position'],
                'creative_package_items_package_position_unique'
            );
            $table->unique(
                ['company_id', 'creative_package_id', 'client_creative_id'],
                'creative_package_items_package_client_unique'
            );
            $table->index('source_template_version_id', 'creative_package_items_source_version_index');
            $table->index(
                ['creative_package_id', 'position', 'id'],
                'creative_package_items_ordered_relation_index'
            );
            $table->foreign(['company_id', 'creative_package_id'], 'creative_package_items_package_tenant_foreign')
                ->references(['company_id', 'id'])->on('creative_packages')
                ->restrictOnUpdate()->cascadeOnDelete();
            $table->foreign('source_template_version_id', 'creative_package_items_source_version_foreign')
                ->references('id')->on('template_versions')
                ->restrictOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_package_items');
    }
};
