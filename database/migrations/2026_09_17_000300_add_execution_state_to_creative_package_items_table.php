<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isMySql = Schema::getConnection()->getDriverName() === 'mysql';

        Schema::table('creative_package_items', function (Blueprint $table) use ($isMySql) {
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->boolean('retryable')->default(true);
            $leaseToken = $table->char('lease_token', 64)->nullable();
            $billingKey = $table->string('billing_key', 100)->nullable();
            if ($isMySql) {
                $leaseToken->charset('ascii')->collation('ascii_bin');
                $billingKey->charset('ascii')->collation('ascii_bin');
            }
            $table->unsignedInteger('lease_generation')->default(0);
            $table->timestamp('lease_expires_at')->nullable();
            $table->foreignId('snap_id')->nullable();
            $table->foreignId('media_id')->nullable();
            $table->string('billing_status', 16)->default('unreserved');
            $table->foreignId('usage_log_id')->nullable();
            $table->foreignId('api_log_id')->nullable();
            $table->unsignedSmallInteger('reserved_credits')->default(0);
            $table->unsignedSmallInteger('charged_credits')->default(0);
            $table->timestamp('reservation_expires_at')->nullable();
            $table->string('output_filename', 255)->nullable();
            $table->string('output_mime_type', 100)->nullable();
            $table->unsignedBigInteger('output_bytes')->nullable();
            $table->char('output_sha256', 64)->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->string('failure_details', 2048)->nullable();
            $table->string('diagnostic_code', 100)->nullable();
            $table->string('diagnostic_exception_class', 255)->nullable();
            $table->string('diagnostic_correlation_id', 100)->nullable();
            $table->char('diagnostic_message_fingerprint', 64)->nullable();
            $table->timestamp('diagnostic_expires_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->unique('lease_token', 'creative_package_items_lease_token_unique');
            $table->unique('billing_key', 'creative_package_items_billing_key_unique');
            $table->index(['status', 'retryable', 'lease_expires_at', 'id'], 'creative_package_items_reconciliation_index');
            $table->index(['usage_log_id', 'billing_status', 'reservation_expires_at'], 'creative_package_items_capacity_index');
            $table->index(['billing_status', 'reservation_expires_at', 'id'], 'creative_package_items_expired_reservation_index');
            $table->index('snap_id', 'creative_package_items_snap_index');
            $table->index('media_id', 'creative_package_items_media_index');
            $table->index('api_log_id', 'creative_package_items_api_log_index');
            $table->foreign('snap_id', 'creative_package_items_snap_foreign')->references('id')->on('snaps')->nullOnDelete();
            $table->foreign('media_id', 'creative_package_items_media_foreign')->references('id')->on('media')->nullOnDelete();
            $table->foreign('usage_log_id', 'creative_package_items_usage_log_foreign')->references('id')->on('usage_logs')->nullOnDelete();
            $table->foreign('api_log_id', 'creative_package_items_api_log_foreign')->references('id')->on('api_logs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        $isSqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::table('creative_package_items', function (Blueprint $table) use ($isSqlite) {
            if ($isSqlite) {
                $table->dropForeign(['snap_id']);
                $table->dropForeign(['media_id']);
                $table->dropForeign(['usage_log_id']);
                $table->dropForeign(['api_log_id']);
            } else {
                $table->dropForeign('creative_package_items_snap_foreign');
                $table->dropForeign('creative_package_items_media_foreign');
                $table->dropForeign('creative_package_items_usage_log_foreign');
                $table->dropForeign('creative_package_items_api_log_foreign');
            }
            $table->dropUnique('creative_package_items_lease_token_unique');
            $table->dropUnique('creative_package_items_billing_key_unique');
            $table->dropIndex('creative_package_items_reconciliation_index');
            $table->dropIndex('creative_package_items_capacity_index');
            $table->dropIndex('creative_package_items_expired_reservation_index');
            $table->dropIndex('creative_package_items_snap_index');
            $table->dropIndex('creative_package_items_media_index');
            $table->dropIndex('creative_package_items_api_log_index');
            $table->dropColumn([
                'attempts', 'retryable', 'lease_token', 'lease_generation', 'lease_expires_at', 'snap_id', 'media_id',
                'billing_key', 'billing_status', 'usage_log_id', 'api_log_id', 'reserved_credits', 'charged_credits',
                'reservation_expires_at', 'output_filename', 'output_mime_type', 'output_bytes', 'output_sha256',
                'failure_code', 'failure_details', 'diagnostic_code', 'diagnostic_exception_class',
                'diagnostic_correlation_id', 'diagnostic_message_fingerprint', 'diagnostic_expires_at', 'started_at',
                'completed_at',
            ]);
        });
    }
};
