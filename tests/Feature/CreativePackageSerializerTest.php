<?php

namespace Tests\Feature;

use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use App\Services\CreativePackageAggregator;
use App\Support\CreativePackageContract;
use App\Support\CreativePackageManifest;
use App\Support\CreativePackageSerializer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCreativePackages;
use Tests\TestCase;

/** Export, replay and get serialize one canonical package state from the stored rows alone. */
class CreativePackageSerializerTest extends TestCase
{
    use BuildsCreativePackages;
    use RefreshDatabase;

    /** The column names the payload emits under the same name. Every other column of both tables is hidden. */
    private const PUBLIC_COLUMNS = ['status', 'attempts', 'retryable', 'label', 'caption', 'alt_text', 'placement_key', 'metadata', 'manifest_version', 'created_at', 'updated_at'];

    /** @return array<string, array{array<int, string>, string, int|null, string, bool}> */
    public static function states(): array
    {
        return [
            'building' => [['pending', 'rendering', 'ready', 'retryable'], CreativePackage::STATUS_BUILDING, CreativePackageContract::HEARTBEAT_SECONDS, 'poll', false],
            'ready' => [['ready', 'ready'], CreativePackage::STATUS_READY, null, 'download', true],
            'partial' => [['ready', 'retryable', 'failed'], CreativePackage::STATUS_PARTIAL, null, 'retry_incomplete', true],
            'failed' => [['failed', 'failed'], CreativePackage::STATUS_FAILED, null, 'inspect_failures', false],
        ];
    }

    /** @param array<int, string> $states */
    #[DataProvider('states')]
    public function test_every_package_state_serializes_the_contract_fields_and_agrees_with_the_rows(array $states, string $status, ?int $poll, string $action, bool $manifest): void
    {
        $package = $this->package($states);

        $payload = $this->serialize($package);

        $this->assertSame(CreativePackageContract::RESPONSE_FIELDS, array_keys($payload));
        $counts = $payload['counts'];
        $this->assertSame(count($states), $counts['pending'] + $counts['rendering'] + $counts['ready'] + $counts['failed']);
        $this->assertSame(
            [$package->uuid, $status, false, count($states), $poll, $package->updated_at->utc()->toIso8601ZuluString(), $action, null, $manifest],
            [$payload['package_id'], $payload['status'], $payload['replayed'], $counts['total'], $payload['poll_after_seconds'], $payload['updated_at'], $payload['next_action'], $payload['url_expires_at'], $payload['manifest'] !== null],
        );
        $this->assertSame($states, array_map(fn (array $item) => $item['status'] === 'failed' && $item['retryable'] ? 'retryable' : $item['status'], $payload['items']));
        $this->assertNoHiddenKey($payload);
    }

    public function test_items_and_manifest_keep_position_order_when_rows_are_inserted_out_of_order(): void
    {
        $package = $this->package(['ready', 'ready', 'ready'], positions: [2, 0, 1]);
        // Hand the serializer the rows in insertion order, so the relation's SQL order cannot mask its own sort.
        $package->setRelation('items', CreativePackageItem::query()->where('creative_package_id', $package->id)->orderBy('id')->get());

        $payload = $this->serialize($package);

        $this->assertSame(['creative-0', 'creative-1', 'creative-2'], array_column($payload['items'], 'creative_id'));
        $this->assertSame(['creative-0', 'creative-1', 'creative-2'], array_column($payload['manifest']['items'], 'creative_id'));
        $this->assertTrue(array_is_list($payload['items']) && array_is_list($payload['manifest']['items']));
    }

    public function test_a_ready_manifest_carries_the_stored_facts_the_null_caption_and_the_evaluated_checks(): void
    {
        $package = $this->package(['ready']);
        $item = $package->items->first();
        $item->update(['caption' => null, 'output_bytes' => 4321, 'output_width' => 640, 'output_height' => 480, 'output_sha256' => str_repeat('b', 64), 'metadata' => ['campaign' => 'launch']]);

        $manifest = $this->serialize($package)['manifest'];

        $this->assertSame(['manifest_version' => 1, 'package_id' => $package->uuid, 'status' => 'ready', 'created_at' => $package->created_at->utc()->toIso8601ZuluString()], array_diff_key($manifest, ['items' => 1]));
        $this->assertSame([
            'creative_id' => 'creative-0', 'family_id' => 'family-0', 'label' => 'Creative 0', 'placement_key' => 'instagram.feed.organic',
            'status' => 'ready', 'failure' => null, 'width' => 640, 'height' => 480, 'mime_type' => 'image/png', 'byte_size' => 4321, 'sha256' => str_repeat('b', 64),
            'file_url' => null, 'preview_url' => null,
            'editable_source' => ['template_id' => $item->source_template_uuid, 'template_version' => $item->source_template_version, 'editor_document_version' => $item->source_editor_document_version],
            'caption' => null, 'alt_text' => $item->alt_text, 'metadata' => ['campaign' => 'launch'],
            'validation' => ['status' => 'passed', 'passed' => [...CreativePackageContract::VALIDATION_CLAIM_CHECKS, ...CreativePackageContract::VALIDATION_OUTPUT_CHECKS], 'failed' => [], 'not_evaluated' => CreativePackageContract::VALIDATION_NOT_EVALUATED],
        ], $manifest['items'][0]);
    }

    /** The dimensions are item facts, so the manifest reads them after the source version row is gone. */
    public function test_a_ready_manifest_keeps_the_rendered_dimensions_after_the_source_version_is_deleted(): void
    {
        $package = $this->package(['ready']);
        $item = $package->items->first();
        $source = $item->sourceVersion;
        $source->delete();

        $record = $this->serialize($package->fresh())['manifest']['items'][0];

        $this->assertNull($item->fresh()->source_template_version_id);
        $this->assertSame([1080, 1080, 'passed'], [$record['width'], $record['height'], $record['validation']['status']]);
        $this->assertSame(['template_id' => $source->template->uuid, 'template_version' => $source->version_number, 'editor_document_version' => $item->source_editor_document_version], $record['editable_source']);
    }

    public function test_a_partial_manifest_carries_a_safe_failure_record_beside_the_ready_item(): void
    {
        $package = $this->package(['ready', 'retryable']);
        $hostile = 'HOSTILE /var/www/secret.key';
        $package->items[1]->update(['attempts' => 3, 'failure_details' => $hostile, 'diagnostic_exception_class' => 'RuntimeException']);

        $payload = $this->serialize($package);

        $this->assertStringNotContainsString($hostile, json_encode($payload));
        $failure = ['code' => 'creative_package_entitlement_required', 'message' => 'The creator of this package can no longer create snaps for the company, so this item did not render.', 'retryable' => true];
        $this->assertSame(['creative_id' => 'creative-1', 'family_id' => null, 'label' => 'Creative 1', 'placement_key' => null, 'status' => 'failed', 'attempts' => 3, 'retryable' => true, 'failure' => $failure], array_diff_key($payload['items'][1], ['editable_source' => 1]));
        $record = $payload['manifest']['items'][1];
        $this->assertSame([null, null, null, null, null, null, null, $failure], [$record['width'], $record['height'], $record['mime_type'], $record['byte_size'], $record['sha256'], $record['file_url'], $record['preview_url'], $record['failure']]);
        $this->assertSame(['status' => 'failed', 'passed' => CreativePackageContract::VALIDATION_CLAIM_CHECKS, 'failed' => [[
            'severity' => 'error', 'code' => 'creative_package_entitlement_required', 'path' => 'items[1]', 'message' => 'The creator of this package can no longer create snaps for the company, so this item did not render.',
            'fix' => 'Ask the human for an account that belongs to the company. Then export the package again with the same idempotency_key.',
        ]], 'not_evaluated' => CreativePackageContract::VALIDATION_NOT_EVALUATED], $record['validation']);
        $this->assertNull($payload['items'][0]['failure']);

        $package->items[1]->update(['failure_code' => 'creative_package_attempts_exhausted']);
        $failed = $this->serialize($package->fresh())['manifest']['items'][1]['validation']['failed'][0];
        $this->assertSame(['The item used every automatic render attempt.', 'Export the package again with the same idempotency_key to start a new attempt cycle.'], [$failed['message'], $failed['fix']]);
    }

    public function test_the_signer_output_fills_the_shared_expiry_and_the_ready_manifest_urls_only(): void
    {
        $package = $this->package(['ready', 'failed']);
        [$ready, $failed] = $package->items->all();
        $urls = ['url_expires_at' => '2026-09-19T12:10:00Z', 'items' => [
            $ready->id => ['file_url' => 'https://example.test/file?signature=a', 'preview_url' => 'https://example.test/preview?signature=b'],
            $failed->id => ['file_url' => 'https://example.test/never', 'preview_url' => 'https://example.test/never'],
        ]];

        $payload = app(CreativePackageSerializer::class)->serialize($package, false, $urls);

        $this->assertSame('2026-09-19T12:10:00Z', $payload['url_expires_at']);
        $this->assertSame([['https://example.test/file?signature=a', 'https://example.test/preview?signature=b'], [null, null]], array_map(fn (array $item) => [$item['file_url'], $item['preview_url']], $payload['manifest']['items']));
        $this->assertSame(CreativePackageContract::RESPONSE_FIELDS, array_keys($payload));
        $this->assertArrayNotHasKey('file_url', $payload['items'][0]);
    }

    public function test_a_building_package_serializes_no_expiry_and_no_manifest_even_with_signer_output(): void
    {
        $package = $this->package(['ready', 'pending']);
        $urls = ['url_expires_at' => '2026-09-19T12:10:00Z', 'items' => [$package->items[0]->id => ['file_url' => 'https://example.test/file?signature=a', 'preview_url' => 'https://example.test/preview?signature=b']]];

        $payload = app(CreativePackageSerializer::class)->serialize($package, false, $urls);

        $this->assertSame(['building', null, null], [$payload['status'], $payload['url_expires_at'], $payload['manifest']]);
    }

    public function test_an_item_that_never_ran_reports_every_check_as_not_evaluated(): void
    {
        $package = $this->package(['pending']);

        $validation = CreativePackageManifest::build($package, $package->items)['items'][0]['validation'];

        $this->assertSame(['status' => 'not_evaluated', 'passed' => [], 'failed' => [], 'not_evaluated' => [...CreativePackageContract::VALIDATION_CLAIM_CHECKS, ...CreativePackageContract::VALIDATION_OUTPUT_CHECKS, ...CreativePackageContract::VALIDATION_NOT_EVALUATED]], $validation);
    }

    public function test_a_reserved_metadata_key_is_dropped_and_no_internal_field_is_serialized(): void
    {
        $package = $this->package(['ready', 'failed']);
        DB::table('creative_package_items')->where('id', $package->items[0]->id)->update(['metadata' => json_encode(['campaign' => 'launch', 'publisher' => 'postiz'])]);

        $payload = $this->serialize($package->fresh());

        $this->assertSame(['campaign' => 'launch'], $payload['manifest']['items'][0]['metadata']);
        $this->assertNoHiddenKey($payload);
    }

    public function test_empty_metadata_encodes_as_a_json_object(): void
    {
        $package = $this->package(['ready', 'ready', 'ready', 'ready']);
        $stored = [null, json_encode([]), json_encode(['publisher' => 'postiz', 'schedule' => 'now']), json_encode(['campaign' => 'launch'])];
        foreach ($package->items as $position => $item) {
            DB::table('creative_package_items')->where('id', $item->id)->update(['metadata' => $stored[$position]]);
        }

        $json = json_encode($this->serialize($package->fresh()));

        $this->assertSame([3, 1], [substr_count($json, '"metadata":{}'), substr_count($json, '"metadata":{"campaign":"launch"}')]);
    }

    public function test_export_replay_and_lookup_serialize_the_same_state(): void
    {
        $this->recordDispatches();
        $actor = $this->member();
        $request = $this->request('key-1', [$this->source($actor, 2), $this->source($actor, 1)]);
        $serializer = app(CreativePackageSerializer::class);

        $export = $this->claim($actor, $request);
        $replay = $this->claim($actor, $request);
        $lookup = CreativePackage::query()->where('company_id', $actor->company_id)->where('uuid', $export->package->uuid)->firstOrFail();
        $payloads = [$serializer->serialize($export->package, $export->replayed), $serializer->serialize($replay->package, $replay->replayed), $serializer->serialize($lookup, false)];

        $this->assertSame([false, true, false], array_column($payloads, 'replayed'));
        $this->assertSame([2, 'building', 'poll'], [$payloads[0]['counts']['total'], $payloads[0]['status'], $payloads[0]['next_action']]);
        $wire = array_map(fn (array $payload) => json_encode(array_diff_key($payload, ['replayed' => 1])), $payloads);
        $this->assertSame([$wire[0], $wire[0]], [$wire[1], $wire[2]]);
    }

    /**
     * A package whose cached counts the real aggregator computed from these item rows.
     *
     * @param  array<int, string>  $states
     * @param  array<int, int>|null  $positions  insertion order of the positions, default ascending
     */
    private function package(array $states, ?array $positions = null): CreativePackage
    {
        CarbonImmutable::setTestNow($this->now());
        $first = $this->oneItem();
        $package = $first->package;
        $package->update(['total_items' => count($states)]);
        $first->delete();
        foreach ($positions ?? array_keys($states) as $position) {
            $factory = CreativePackageItem::factory()->for($package, 'package')->state([
                'company_id' => $package->company_id, 'position' => $position, 'client_creative_id' => "creative-{$position}", 'label' => "Creative {$position}",
            ] + ($position === 0 ? ['client_family_id' => 'family-0', 'placement_key' => 'instagram.feed.organic', 'caption' => 'Launch day'] : []));
            $factory = match ($states[$position]) {
                'pending' => $factory,
                'rendering' => $factory->rendering(),
                'ready' => $factory->ready(),
                'failed' => $factory->failed(),
                'retryable' => $factory->failed()->state(['retryable' => true, 'failure_code' => 'creative_package_entitlement_required']),
            };
            $factory->create();
        }
        app(CreativePackageAggregator::class)->recount($package);

        return $package->fresh();
    }

    /** @return array<string, mixed> */
    private function serialize(CreativePackage $package): array
    {
        return app(CreativePackageSerializer::class)->serialize($package, false);
    }

    /**
     * Every column of both tables that the payload does not emit by name, plus the reserved publisher keys.
     * The `metadata` subtree is customer data and is skipped, so a customer key named `id` cannot fail the walk.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertNoHiddenKey(array $payload, string $path = ''): void
    {
        $columns = [...Schema::getColumnListing('creative_package_items'), ...Schema::getColumnListing('creative_packages')];
        $hidden = [...array_diff($columns, self::PUBLIC_COLUMNS), ...CreativePackageContract::RESERVED_METADATA_FIELDS];
        $this->assertContains('failure_details', $hidden);
        foreach ($payload as $key => $value) {
            $this->assertNotContains($key, $hidden, "Hidden key `{$key}` at `{$path}`.");
            if (is_array($value) && $key !== 'metadata') {
                $this->assertNoHiddenKey($value, "{$path}.{$key}");
            }
        }
    }
}
