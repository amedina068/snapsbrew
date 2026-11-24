<?php

namespace Tests\Unit\Models;

use App\Models\CreativePackageItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CreativePackageItemExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_persists_execution_defaults_casts_and_hides_diagnostics(): void
    {
        $item = CreativePackageItem::factory()->create()->fresh();

        $this->assertSame(CreativePackageItem::STATUS_PENDING, $item->status);
        $this->assertSame(0, $item->attempts);
        $this->assertSame(0, $item->lease_generation);
        $this->assertTrue($item->retryable);
        $this->assertNotContains('diagnostic_code', array_keys($item->toArray()));
        $this->assertNull($item->lease_expires_at);
        $this->assertNull($item->snap_id);
        $this->assertNull($item->media_id);
        $this->assertNull($item->api_log_id);
    }

    public function test_execution_factory_states_describe_persisted_lifecycle_facts(): void
    {
        $rendering = CreativePackageItem::factory()->rendering()->create()->fresh();
        $ready = CreativePackageItem::factory()->ready()->create()->fresh();
        $failed = CreativePackageItem::factory()->failed()->create()->fresh();

        $this->assertSame([CreativePackageItem::STATUS_RENDERING, true], [$rendering->status, $rendering->retryable]);
        $this->assertNotNull($rendering->lease_token);
        $this->assertSame([CreativePackageItem::STATUS_READY, false], [$ready->status, $ready->retryable]);
        $this->assertNotNull($ready->output_sha256);
        $this->assertSame([CreativePackageItem::STATUS_FAILED, false], [$failed->status, $failed->retryable]);
        $this->assertNotNull($failed->failure_code);
    }

    public function test_execution_schema_has_serving_indexes(): void
    {
        $indexes = collect(Schema::getIndexes('creative_package_items'))->keyBy('name');

        foreach ([
            'creative_package_items_reconciliation_index' => ['status', 'retryable', 'lease_expires_at', 'id'],
            'creative_package_items_snap_index' => ['snap_id'],
            'creative_package_items_media_index' => ['media_id'],
            'creative_package_items_api_log_index' => ['api_log_id'],
        ] as $name => $columns) {
            $this->assertSame($columns, $indexes[$name]['columns']);
        }
    }
}
