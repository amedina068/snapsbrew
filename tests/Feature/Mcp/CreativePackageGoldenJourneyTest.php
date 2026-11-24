<?php

namespace Tests\Feature\Mcp;

use App\Contracts\SnapRenderer;
use App\Models\ApiLog;
use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Support\CreativePackageContract;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCreativePackages;
use Tests\Support\CallsCreativePackageMcpTools;
use Tests\Support\FakeRenderer;
use Tests\Support\InteractsWithMcp;
use Tests\Support\WithPassportKeys;
use Tests\TestCase;

/**
 * The release journey of PRD T-019 over the real `/mcp` route.
 *
 * One export names three published versions at 1:1, 4:5 and 9:16. The
 * client reads `building`, drops the connection, and polls later. The poll
 * reads `ready` with three ordered files, the exact source of each, the
 * measured dimensions, a checksum that matches the downloaded bytes, one
 * usage row per item, and no publisher field. A replay of the same key
 * renders nothing and records no new usage, ten times over. The same key
 * with another payload is a conflict.
 *
 * The queue runs on the `database` driver so the export returns before the
 * render. The renderer is a fake that paints a PNG of the requested size,
 * because the executor measures the stored bytes against the source
 * dimensions. No browser runs.
 */
class CreativePackageGoldenJourneyTest extends TestCase
{
    use BuildsCreativePackages;
    use CallsCreativePackageMcpTools;
    use InteractsWithMcp;
    use RefreshDatabase;
    use WithPassportKeys;

    /** The three ratios of the journey, in request order: 1:1, 4:5, 9:16. */
    private const RATIOS = [[1080, 1080], [1080, 1350], [1080, 1920]];

    private FakeRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWithPassportKeys();
        CarbonImmutable::setTestNow($this->now());
        Storage::fake('public');
        $this->renderer = new class extends FakeRenderer
        {
            /** A PNG of the requested size, so the output check reads the source dimensions. */
            public function screenshotBase64(string $html, int $width, int $height, string $format = 'png', bool $transparent = false): string
            {
                parent::screenshotBase64($html, $width, $height, $format, $transparent);
                $image = imagecreatetruecolor($width, $height);
                ob_start();
                imagepng($image);

                return base64_encode((string) ob_get_clean());
            }
        };
        $this->app->instance(SnapRenderer::class, $this->renderer);
        config(['queue.default' => 'database']);
    }

    public function test_three_ratios_export_once_read_ready_after_a_disconnect_and_replay_without_a_second_render(): void
    {
        $actor = $this->member();
        $token = $this->token($actor);
        $sources = array_map(fn (array $size) => $this->sourceOfSize($actor, ...$size), self::RATIOS);
        $request = $this->request('golden', $sources);

        // 1. The export answers before any render.
        $export = $this->mcpToolPayload($this->tool($token, 'export-creative-package', $request));

        $this->assertSame(CreativePackageContract::RESPONSE_FIELDS, array_keys($export));
        $this->assertMatchesRegularExpression(CreativePackageContract::UUID_PATTERN, $export['package_id']);
        $this->assertSame(['building', false, 'poll', null], [$export['status'], $export['replayed'], $export['next_action'], $export['manifest']]);
        $this->assertSame(['total' => 3, 'pending' => 3, 'rendering' => 0, 'ready' => 0, 'failed' => 0], $export['counts']);
        $this->assertSame([0, 0], [count($this->renderer->screenshotCalls), ApiLog::count()]);

        // 2. The client disconnects. Nothing else reaches the server until the queue has run.
        $this->work();

        // 3. A later poll reads the finished package.
        $response = $this->tool($token, 'get-creative-package', ['package_id' => $export['package_id']]);
        $get = $this->mcpToolPayload($response);

        $this->assertSame(CreativePackageContract::RESPONSE_FIELDS, array_keys($get));
        $this->assertSame([$export['package_id'], 'ready', false, 'download'], [$get['package_id'], $get['status'], $get['replayed'], $get['next_action']]);
        $this->assertSame(['total' => 3, 'pending' => 0, 'rendering' => 0, 'ready' => 3, 'failed' => 0], $get['counts']);
        $this->assertSame($this->now()->addSeconds(CreativePackageContract::URL_TTL_SECONDS)->toIso8601ZuluString(), $get['url_expires_at']);

        $items = $get['manifest']['items'];
        $this->assertSame(['creative-0', 'creative-1', 'creative-2'], array_column($items, 'creative_id'));
        $this->assertSame(['ready', 'ready', 'ready'], array_column($items, 'status'));
        $this->assertSame(self::RATIOS, array_map(fn (array $item) => [$item['width'], $item['height']], $items));
        $this->assertSame(array_fill(0, 3, 'image/png'), array_column($items, 'mime_type'));
        $this->assertSame(array_fill(0, 3, 'passed'), array_column(array_column($items, 'validation'), 'status'));
        foreach ($items as $index => $item) {
            $source = $sources[$index];
            $this->assertSame(['template_id' => $source->template->uuid, 'template_version' => $source->version_number, 'editor_document_version' => null], $item['editable_source'], "item {$index}");
            $bytes = $this->download($item['file_url']);
            $this->assertSame($item['sha256'], hash('sha256', $bytes), "item {$index}");
            $this->assertSame($item['byte_size'], strlen($bytes), "item {$index}");
            $this->assertSame(self::RATIOS[$index], array_slice(getimagesizefromstring($bytes), 0, 2), "item {$index}");
            // The preview is a second signed URL to an image. Today it serves the same object.
            $this->assertSame(self::RATIOS[$index], array_slice(getimagesizefromstring($this->download($item['preview_url'])), 0, 2), "item {$index} preview");
        }

        // The request fields of item 0 come back on the manifest untouched.
        $first = $request['items'][0];
        $this->assertSame([$first['metadata'], $first['caption'], $first['placement_key']], [$items[0]['metadata'], $items[0]['caption'], $items[0]['placement_key']]);

        // The rows behind the manifest name the same sources.
        $this->assertSame(array_map(fn (TemplateVersion $source) => $source->id, $sources), CreativePackageItem::query()->orderBy('position')->pluck('source_template_version_id')->all());

        // No browser ran: the fake renderer was asked one time per item, at the source size.
        $this->assertSame(self::RATIOS, array_map(fn (array $call) => [$call['width'], $call['height']], $this->renderer->screenshotCalls));

        // No publisher field on any surface of the response.
        $text = $response->json('result.content.0.text');
        foreach (CreativePackageContract::RESERVED_METADATA_FIELDS as $field) {
            $this->assertStringNotContainsString("\"{$field}\"", $text);
        }

        // One usage row per item, linked from the item, one render each.
        $this->assertOneUsageRowPerItem();

        // 4. A replay of the same key returns the same package and does no new work.
        $renders = count($this->renderer->screenshotCalls);
        $replay = $this->mcpToolPayload($this->tool($token, 'export-creative-package', $request));
        $this->assertSame(CreativePackageContract::RESPONSE_FIELDS, array_keys($replay));
        $this->assertSame([$export['package_id'], 'ready', true], [$replay['package_id'], $replay['status'], $replay['replayed']]);
        $this->assertSame(['replayed' => false] + $replay, ['replayed' => false] + $get);
        $this->assertSame([3, 3], [$renders, count($this->renderer->screenshotCalls)]);
        $this->assertOneUsageRowPerItem();

        // 5. Ten replays still leave one render and one usage row per item.
        foreach (range(1, 10) as $replayNumber) {
            $again = $this->mcpToolPayload($this->tool($token, 'export-creative-package', $request));
            $this->assertSame([$export['package_id'], true], [$again['package_id'], $again['replayed']], "replay {$replayNumber}");
        }
        $this->work();
        $this->assertSame(3, count($this->renderer->screenshotCalls));
        $this->assertOneUsageRowPerItem();
        $this->assertSame(1, CreativePackage::count());

        // 6. The same key with another payload is a conflict, and it leaves the package alone.
        $conflict = $request;
        $conflict['items'][2]['label'] = 'Another label';
        $error = $this->mcpToolError($this->tool($token, 'export-creative-package', $conflict));
        $this->assertSame('creative_package_idempotency_conflict', $error['code']);
        $this->assertSame(['golden', $export['package_id']], array_values($error['details']));
        $this->assertSame([1, 3], [CreativePackage::count(), count($this->renderer->screenshotCalls)]);
        $this->assertOneUsageRowPerItem();
    }

    // --------------------------------------------------------------- helpers

    /** Exactly one usage row per item, one render each, linked from the item. */
    private function assertOneUsageRowPerItem(): void
    {
        $logs = CreativePackageItem::query()->orderBy('position')->pluck('api_log_id')->all();
        $this->assertCount(3, array_unique(array_filter($logs)));
        $this->assertSame($logs, ApiLog::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame([3, 3], [ApiLog::count(), (int) ApiLog::query()->sum('renders')]);
    }

    /** The bytes the signed file URL serves. */
    private function download(string $url): string
    {
        $response = $this->get($url);
        $response->assertOk();

        return $response->streamedContent();
    }

    private function sourceOfSize(User $actor, int $width, int $height): TemplateVersion
    {
        $template = Template::factory()->for($actor->company)->for($actor)->create();

        return TemplateVersion::factory()->for($template)->create(['version_number' => 1, 'created_by' => $actor->id, 'published' => true, 'width' => $width, 'height' => $height]);
    }
}
