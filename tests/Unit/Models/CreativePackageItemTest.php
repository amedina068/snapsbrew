<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use App\Models\Template;
use App\Models\TemplateVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CreativePackageItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_persists_core_snapshots_casts_and_relations(): void
    {
        $default = CreativePackageItem::factory()->create();
        $this->assertSame($default->company_id, $default->sourceVersion->template->company_id);

        [$package, $version] = $this->packageAndVersion();
        $item = CreativePackageItem::factory()->for($package, 'package')->for($version, 'sourceVersion')->create([
            'company_id' => $package->company_id,
            'position' => 7,
            'client_creative_id' => 'creative-sentinel',
            'client_family_id' => 'family-sentinel',
            'label' => 'Label sentinel',
            'placement_key' => 'instagram.feed.organic',
            'caption' => 'Caption sentinel',
            'alt_text' => 'Alt sentinel',
            'source_template_uuid' => $version->template->uuid,
            'source_template_version' => $version->version_number,
            'source_editor_document_version' => 2,
            'metadata' => ['campaign' => 'fall'],
            'requested_format' => 'webp',
        ])->fresh();

        $this->assertSame([
            7, 'creative-sentinel', 'family-sentinel', 'Label sentinel', 'instagram.feed.organic',
            'Caption sentinel', 'Alt sentinel', ['campaign' => 'fall'], $version->template->uuid,
            $version->version_number, 2, $version->id, 'webp', CreativePackageItem::STATUS_PENDING,
        ], array_values($item->only([
            'position', 'client_creative_id', 'client_family_id', 'label', 'placement_key', 'caption', 'alt_text',
            'metadata', 'source_template_uuid', 'source_template_version', 'source_editor_document_version',
            'source_template_version_id', 'requested_format', 'status',
        ])));
        $this->assertTrue($item->company->is($package->company));
        $this->assertTrue($item->package->is($package));
        $this->assertTrue($item->sourceVersion->is($version));
        $this->assertNotNull($item->uuid);
        $this->assertNotContains('uuid', $item->getFillable());
    }

    public function test_package_items_are_stably_ordered_by_position(): void
    {
        [$package, $version] = $this->packageAndVersion();
        foreach ([2, 0, 1] as $position) {
            $this->item($package, $version, ['position' => $position]);
        }

        $this->assertSame([0, 1, 2], $package->items->pluck('position')->all());
    }

    public function test_tenant_uuid_position_and_client_id_constraints_are_enforced(): void
    {
        [$package, $version] = $this->packageAndVersion();
        $first = $this->item($package, $version, ['position' => 0, 'client_creative_id' => 'Launch-A']);
        $other = CreativePackageItem::factory()->create();
        $other->forceFill(['uuid' => $first->uuid])->save();
        $this->item($package, $version, ['position' => 1, 'client_creative_id' => 'launch-a']);

        $duplicateUuid = $this->item($package, $version, ['position' => 2, 'client_creative_id' => 'uuid-check']);
        $this->assertQueryFails(fn () => $duplicateUuid->forceFill(['uuid' => $first->uuid])->save());
        $this->assertQueryFails(fn () => $this->item($package, $version, ['position' => 0]));
        $this->assertQueryFails(fn () => $this->item($package, $version, ['client_creative_id' => 'Launch-A']));
    }

    public function test_cross_company_package_child_is_rejected(): void
    {
        [$package, $version] = $this->packageAndVersion();

        $this->assertQueryFails(fn () => CreativePackageItem::factory()->for($package, 'package')->for($version, 'sourceVersion')->create([
            'company_id' => Company::factory(),
        ]));
    }

    public function test_source_deletion_keeps_snapshots_while_package_deletion_cascades(): void
    {
        [$package, $version] = $this->packageAndVersion();
        $item = $this->item($package, $version);

        $snapshot = $item->only(['source_template_uuid', 'source_template_version', 'source_editor_document_version']);
        $version->delete();
        $this->assertNull($item->fresh()->source_template_version_id);
        $this->assertSame($snapshot, $item->fresh()->only(array_keys($snapshot)));
        $package->delete();

        $this->assertDatabaseMissing('creative_package_items', ['id' => $item->id]);
    }

    public function test_schema_has_core_item_indexes(): void
    {
        $indexes = collect(Schema::getIndexes('creative_package_items'))->keyBy('name');

        foreach ([
            'creative_package_items_company_uuid_unique' => ['company_id', 'uuid'],
            'creative_package_items_package_position_unique' => ['company_id', 'creative_package_id', 'position'],
            'creative_package_items_package_client_unique' => ['company_id', 'creative_package_id', 'client_creative_id'],
            'creative_package_items_source_version_index' => ['source_template_version_id'],
            'creative_package_items_ordered_relation_index' => ['creative_package_id', 'position', 'id'],
        ] as $name => $columns) {
            $this->assertSame($columns, $indexes[$name]['columns']);
        }
    }

    private function packageAndVersion(): array
    {
        $package = CreativePackage::factory()->create();
        $template = Template::factory()->for($package->company)->create();
        $version = TemplateVersion::factory()->for($template)->create(['editor_schema_version' => 2]);

        return [$package, $version];
    }

    private function item(CreativePackage $package, TemplateVersion $version, array $attributes = []): CreativePackageItem
    {
        return CreativePackageItem::factory()->for($package, 'package')->for($version, 'sourceVersion')->create($attributes + [
            'company_id' => $package->company_id,
            'source_template_uuid' => $version->template->uuid,
            'source_template_version' => $version->version_number,
        ]);
    }

    private function assertQueryFails(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a database constraint violation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
