<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isMySql = Schema::getConnection()->getDriverName() === 'mysql';

        Schema::create('creative_packages', function (Blueprint $table) use ($isMySql) {
            $table->id();
            $table->uuid('uuid');
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $namespace = $table->string('operation_namespace', 64);
            $key = $table->string('idempotency_key', 100);
            if ($isMySql) {
                $namespace->charset('ascii')->collation('ascii_bin');
                $key->charset('ascii')->collation('ascii_bin');
            }
            $namespace->default('creative_package_export_v1');
            $table->char('request_fingerprint', 64);
            $table->string('status', 16)->default('building');
            $table->unsignedSmallInteger('total_items')->default(0);
            $table->unsignedSmallInteger('pending_items_count')->default(0);
            $table->unsignedSmallInteger('rendering_items_count')->default(0);
            $table->unsignedSmallInteger('ready_items_count')->default(0);
            $table->unsignedSmallInteger('failed_items_count')->default(0);
            $table->unsignedSmallInteger('credits_charged')->default(0);
            $table->unsignedSmallInteger('manifest_version')->default(1);
            $table->string('failure_code', 100)->nullable();
            $table->string('failure_details', 2048)->nullable();
            $table->timestamp('lease_expires_at');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('replay_expires_at');
            $table->timestamps();

            $table->unique(['company_id', 'uuid'], 'creative_packages_company_uuid_unique');
            $table->unique(['company_id', 'id'], 'creative_packages_company_id_unique');
            $table->unique(
                ['company_id', 'operation_namespace', 'idempotency_key'],
                'creative_packages_company_claim_unique'
            );
            $table->index(
                ['company_id', 'status', 'lease_expires_at'],
                'creative_packages_active_limit_index'
            );
            $table->index(['company_id', 'created_at', 'id'], 'creative_packages_company_lookup_index');
            $table->index(['replay_expires_at', 'id'], 'creative_packages_replay_cleanup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_packages');
    }
};
