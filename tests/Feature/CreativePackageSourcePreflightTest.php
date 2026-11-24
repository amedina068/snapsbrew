<?php

namespace Tests\Feature;

use App\Models\ApiLog;
use App\Models\Company;
use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use App\Models\EditorAsset;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\CreativePackageSourcePreflight;
use App\Services\CreativePackageSourcePreflightException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreativePackageSourcePreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_published_versions_in_request_order_without_writes(): void
    {
        [$company, $actor] = $this->companyAndActor();
        $first = $this->source($company, $actor, 1);
        $second = $this->source($company, $actor, 2);

        $resolved = app(CreativePackageSourcePreflight::class)->preflight($company->id, [
            $this->item('second', $second->template, 2),
            $this->item('first', $first->template, 1),
        ]);

        $this->assertSame([$second->id, $first->id], array_map(fn (TemplateVersion $version) => $version->id, $resolved));
        $this->assertSame(0, CreativePackage::count());
        $this->assertSame(0, CreativePackageItem::count());
    }

    public function test_it_reports_published_ownership_and_snapshot_failures_in_item_order(): void
    {
        [$company, $actor] = $this->companyAndActor();
        $unpublished = $this->source($company, $actor, 1, ['published' => false]);
        $legacy = $this->source($company, $actor, 2, ['layers' => json_encode(['unexpected' => []])]);
        $v2 = $this->source($company, $actor, 3, ['editor_document' => ['version' => 2]]);
        $dimensions = $this->source($company, $actor, 4, ['width' => 0]);
        $scalar = $this->source($company, $actor, 5);
        DB::table('template_versions')->where('id', $scalar->id)->update(['editor_document' => '"corrupt"']);
        [, $foreignActor] = $this->companyAndActor();
        $foreign = $this->source($foreignActor->company, $foreignActor, 1);
        Queue::fake();

        try {
            app(CreativePackageSourcePreflight::class)->preflight($company->id, [
                $this->item('unpublished', $unpublished->template, 1),
                $this->item('foreign', $foreign->template, 1),
                $this->item('legacy', $legacy->template, 2),
                $this->item('v2', $v2->template, 3),
                $this->item('dimensions', $dimensions->template, 4),
                $this->item('scalar', $scalar->template, 5),
            ]);
            $this->fail('Expected source preflight to reject the invalid source set.');
        } catch (CreativePackageSourcePreflightException $exception) {
            $this->assertSame('creative_package_source_invalid', $exception->codeName);
            $this->assertSame(['unpublished', 'foreign', 'legacy', 'v2', 'dimensions', 'scalar'], array_column($exception->details, 'client_creative_id'));
            $this->assertSame(['creative_package_source_invalid', 'creative_package_source_not_found', 'creative_package_source_invalid', 'creative_package_source_invalid', 'creative_package_source_invalid', 'creative_package_source_invalid'], array_column($exception->details, 'code'));
            $this->assertSame(['source_unpublished'], $exception->details[0]['issues']);
            $this->assertSame(['legacy_snapshot_invalid'], $exception->details[2]['issues']);
            $this->assertSame(['layers_missing'], $exception->details[3]['issues']);
            $this->assertSame(['dimensions_invalid'], $exception->details[4]['issues']);
            $this->assertSame(['editor_document_invalid'], $exception->details[5]['issues']);
        }

        $this->assertNoClaimWrites();
        Queue::assertNothingPushed();
    }

    public function test_it_rejects_only_missing_or_foreign_templates_without_reading_versions(): void
    {
        [$company] = $this->companyAndActor();
        [, $foreignActor] = $this->companyAndActor();
        $foreign = $this->source($foreignActor->company, $foreignActor, 1);
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            app(CreativePackageSourcePreflight::class)->preflight($company->id, [
                ['client_creative_id' => 'missing', 'template_id' => (string) Str::uuid(), 'template_version' => 1],
                $this->item('foreign', $foreign->template, 1),
            ]);
            $this->fail('Expected unknown template sources to be rejected.');
        } catch (CreativePackageSourcePreflightException $exception) {
            $this->assertSame('creative_package_source_not_found', $exception->codeName);
            $this->assertSame(['missing', 'foreign'], array_column($exception->details, 'client_creative_id'));
            $this->assertSame(['creative_package_source_not_found', 'creative_package_source_not_found'], array_column($exception->details, 'code'));
        } finally {
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
        }

        $this->assertCount(0, array_filter($queries, fn (array $query) => str_contains($query['query'], 'template_versions')));
        $this->assertNoClaimWrites();
    }

    public function test_it_rejects_missing_foreign_or_unlinked_pinned_assets(): void
    {
        [$company, $actor] = $this->companyAndActor();
        $missing = $this->source($company, $actor, 1, ['editor_document' => $this->imageDocument((string) Str::uuid())]);
        [$foreignCompany] = $this->companyAndActor();
        $foreignAsset = EditorAsset::query()->create($this->assetAttributes($foreignCompany));
        $foreign = $this->source($company, $actor, 2, ['editor_document' => $this->imageDocument($foreignAsset->uuid)]);
        $unlinkedAsset = EditorAsset::query()->create($this->assetAttributes($company));
        $unlinked = $this->source($company, $actor, 3, ['editor_document' => $this->imageDocument($unlinkedAsset->uuid)]);

        try {
            app(CreativePackageSourcePreflight::class)->preflight($company->id, [
                $this->item('missing', $missing->template, 1),
                $this->item('foreign', $foreign->template, 2),
                $this->item('unlinked', $unlinked->template, 3),
            ]);
            $this->fail('Expected unresolvable assets to fail preflight.');
        } catch (CreativePackageSourcePreflightException $exception) {
            $this->assertSame(['editor_asset_not_found', 'editor_asset_not_found', 'editor_asset_unlinked'], array_map(fn (array $detail) => $detail['issues'][0], $exception->details));
        }
    }

    public function test_it_resolves_a_valid_linked_v2_asset(): void
    {
        [$company, $actor] = $this->companyAndActor();
        $asset = EditorAsset::query()->create($this->assetAttributes($company));
        $source = $this->source($company, $actor, 1, ['editor_document' => $this->imageDocument($asset->uuid)]);
        $source->editorAssets()->attach($asset);

        $resolved = app(CreativePackageSourcePreflight::class)->preflight($company->id, [
            $this->item('image', $source->template, 1),
        ]);

        $this->assertSame([$source->id], array_map(fn (TemplateVersion $version) => $version->id, $resolved));
        $this->assertNoClaimWrites();
    }

    public function test_ten_items_use_the_same_query_count_as_one_item(): void
    {
        [$company, $actor] = $this->companyAndActor();
        $first = $this->source($company, $actor, 1);
        $oneQueryCount = $this->preflightQueryCount($company, [$this->item('creative-1', $first->template, 1)]);
        $items = [$this->item('creative-1', $first->template, 1)];
        foreach (range(1, 10) as $number) {
            if ($number === 1) {
                continue;
            }
            $source = $this->source($company, $actor, $number);
            $items[] = $this->item("creative-{$number}", $source->template, $number);
        }

        $tenQueryCount = $this->preflightQueryCount($company, $items);

        $this->assertSame($oneQueryCount, $tenQueryCount);
        $this->assertLessThanOrEqual(4, $tenQueryCount);
    }

    /** @return array{Company, User} */
    private function companyAndActor(): array
    {
        $company = Company::factory()->create();

        return [$company, User::factory()->for($company)->create()];
    }

    private function source(Company $company, User $actor, int $version, array $attributes = []): TemplateVersion
    {
        $template = Template::factory()->for($company)->for($actor)->create();

        return TemplateVersion::factory()->for($template)->create(array_replace([
            'version_number' => $version,
            'created_by' => $actor->id,
            'published' => true,
        ], $attributes));
    }

    /** @return array<string, mixed> */
    private function item(string $clientId, Template $template, int $version): array
    {
        return ['client_creative_id' => $clientId, 'template_id' => $template->uuid, 'template_version' => $version];
    }

    /** @return array<string, mixed> */
    private function imageDocument(string $assetId): array
    {
        return ['version' => 2, 'layers' => [['type' => 'image', 'source' => ['asset_id' => $assetId]]]];
    }

    /** @return array<string, mixed> */
    private function assetAttributes(Company $company): array
    {
        return ['company_id' => $company->id, 'uuid' => (string) Str::uuid(), 'sha256' => hash('sha256', (string) Str::uuid()), 'mime_type' => 'image/png', 'byte_size' => 1, 'width' => 1, 'height' => 1];
    }

    /** @param array<int, array{client_creative_id: string, template_id: string, template_version: int}> $items */
    private function preflightQueryCount(Company $company, array $items): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(CreativePackageSourcePreflight::class)->preflight($company->id, $items);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function assertNoClaimWrites(): void
    {
        $this->assertSame(0, CreativePackage::count());
        $this->assertSame(0, CreativePackageItem::count());
        $this->assertSame(0, ApiLog::count());
    }
}
