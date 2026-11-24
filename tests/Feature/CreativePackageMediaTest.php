<?php

namespace Tests\Feature;

use App\Contracts\SnapRenderer;
use App\Models\ApiLog;
use App\Models\Company;
use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use App\Models\Snap;
use App\Models\Template;
use App\Models\User;
use App\Services\CreativePackageMediaResolver;
use App\Services\CreativePackageMediaSigner;
use App\Support\CreativePackageContract;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\BuildsCreativePackages;
use Tests\Support\ExplainsCapturedQueries;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

/** Signed file and preview URLs serve one exact object to one tenant, and expire. Nothing here renders or records usage. */
class CreativePackageMediaTest extends TestCase
{
    use BuildsCreativePackages;
    use ExplainsCapturedQueries;
    use RefreshDatabase;

    private FakeRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow($this->now());
        Storage::fake('public');
        $this->renderer = new FakeRenderer;
        $this->app->instance(SnapRenderer::class, $this->renderer);
    }

    public function test_the_file_and_preview_urls_download_the_stored_object_and_share_one_expiry(): void
    {
        $package = $this->readyPackage();
        $item = $package->items->first();
        $bytes = base64_decode(FakeRenderer::FAKE_PNG);

        $urls = $this->sign($package);

        $expiry = $this->now()->addSeconds(600);
        $this->assertSame($expiry->toIso8601ZuluString(), $urls['url_expires_at']);
        foreach (['file_url', 'preview_url'] as $role) {
            $url = $urls['items'][$item->id][$role];
            $this->assertStringContainsString("/{$package->uuid}/items/{$item->uuid}/".substr($role, 0, -4).'?', $url);
            $this->assertSame((string) $expiry->getTimestamp(), $this->queryParameters($url)['expires']);
            $response = $this->get($url);
            $response->assertOk();
            $this->assertSame($bytes, $response->streamedContent());
            $this->assertSame(['image/png', "attachment; filename=launch-day-feed-{$item->uuid}.png", 'no-store, private'], [$response->headers->get('Content-Type'), $response->headers->get('Content-Disposition'), $response->headers->get('Cache-Control')]);
        }
    }

    /**
     * The release runbook tells the operator to keep the old key in
     * `APP_PREVIOUS_KEYS` through a rotation. This is the proof that the URL
     * signer reads that list: the same URL is refused with only the new key
     * and accepted once the old key is listed.
     */
    public function test_a_file_url_signed_before_a_key_rotation_verifies_through_the_previous_keys(): void
    {
        $package = $this->readyPackage();
        $url = $this->sign($package)['items'][$package->items->first()->id]['file_url'];
        $old = config('app.key');
        $new = 'base64:'.base64_encode(random_bytes(32));

        config(['app.key' => $new, 'app.previous_keys' => []]);
        $this->get($url)->assertForbidden();

        config(['app.key' => $new, 'app.previous_keys' => [$old]]);
        $this->get($url)->assertOk();
    }

    /**
     * `config/app.php` has no `previous_keys` entry. Laravel 11 merges the
     * framework's own `config/app.php` under the application's, so the key
     * exists and reads `APP_PREVIOUS_KEYS`. A second process proves the env
     * read, because this process booted before the variable was set.
     */
    public function test_the_previous_keys_are_read_from_the_environment_through_the_framework_config(): void
    {
        $this->assertSame([], config('app.previous_keys'));

        $script = 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo json_encode(config("app.previous_keys"));';
        $process = new Process([PHP_BINARY, '-r', $script], base_path(), ['APP_PREVIOUS_KEYS' => 'base64:old-one,base64:old-two']);
        $process->mustRun();

        $this->assertSame(['base64:old-one', 'base64:old-two'], json_decode($process->getOutput(), true));
    }

    public function test_a_tampered_an_expired_a_swapped_role_and_a_swapped_item_signature_are_all_rejected(): void
    {
        $package = $this->readyPackage(items: 2);
        [$first, $second] = $package->items->all();
        $urls = $this->sign($package)['items'];
        $file = $urls[$first->id]['file_url'];

        $this->get(str_replace('signature=', 'signature=0', $file))->assertForbidden();
        $this->get(str_replace('/file?', '/preview?', $file))->assertForbidden();
        $this->get(str_replace($first->uuid, $second->uuid, $file))->assertForbidden();
        $this->get(str_replace($first->uuid, $second->uuid, $urls[$first->id]['preview_url']))->assertForbidden();
        $this->travelTo($this->now()->addSeconds(601));
        $this->get($file)->assertForbidden();
        $this->travelTo($this->now()->addSeconds(600));
        $this->get($file)->assertOk();
    }

    /** An array-shaped signature or expires crashed inside hasCorrectSignature() with a 500 (B-066). Both routes must answer 403. */
    public function test_a_non_scalar_signature_or_expires_answers_403_on_both_routes(): void
    {
        $package = $this->readyPackage();
        $urls = $this->sign($package)['items'][$package->items->first()->id];

        foreach ([$urls['file_url'], $urls['preview_url']] as $url) {
            $this->get(preg_replace('/signature=[^&]+/', 'signature[]=x&signature[]=y', $url))->assertForbidden();
            $this->get(preg_replace('/expires=[^&]+/', 'expires[]=1', $url))->assertForbidden();
        }
    }

    /** The signed middleware rejects before throttle:api runs, so its failure path is unthrottled unless throttle runs first. */
    public function test_both_routes_throttle_before_they_validate_the_signature(): void
    {
        foreach (['creative-packages.file', 'creative-packages.preview'] as $name) {
            $middleware = app('router')->getRoutes()->getByName($name)->gatherMiddleware();

            $this->assertContains('throttle:api', $middleware, $name);
            $this->assertContains('signed', $middleware, $name);
            $this->assertLessThan(array_search('signed', $middleware, true), array_search('throttle:api', $middleware, true), "{$name} must throttle before it validates the signature.");
        }
    }

    public function test_a_foreign_package_a_foreign_snap_a_non_ready_item_and_missing_media_get_no_url_and_no_bytes(): void
    {
        $package = $this->readyPackage();
        $item = $package->items->first();
        $foreign = $this->readyPackage();
        $this->assertSame(404, $this->get($this->forge($foreign, $item))->status());

        // An item under a foreign company is not a case: the composite foreign key on (company_id, creative_package_id) forbids that row.
        $cases = [
            'foreign snap' => fn () => DB::table('snaps')->where('id', $item->snap_id)->update(['company_id' => $foreign->company_id]),
            'media of another snap' => fn () => DB::table('media')->where('id', $item->media_id)->update(['model_id' => $foreign->items->first()->snap_id]),
            'non-ready' => fn () => DB::table('creative_package_items')->where('id', $item->id)->update(['status' => CreativePackageItem::STATUS_RENDERING]),
            'missing media row' => fn () => DB::table('media')->where('id', $item->media_id)->delete(),
            'missing media id' => fn () => DB::table('creative_package_items')->where('id', $item->id)->update(['media_id' => null]),
            'deleted snap' => fn () => DB::table('creative_package_items')->where('id', $item->id)->update(['snap_id' => null]),
            'media outside the image collection' => fn () => DB::table('media')->where('id', $item->media_id)->update(['collection_name' => 'html_content']),
        ];
        foreach ($cases as $case => $break) {
            $before = $this->snapshot($item->fresh());
            $break();
            $urls = $this->sign($package->fresh());
            $this->assertSame([null, ['file_url' => null, 'preview_url' => null]], [$urls['url_expires_at'], $urls['items'][$item->id]], $case);
            $this->assertSame(404, $this->get($this->forge($package, $item))->status(), $case);
            $this->assertNull(app(CreativePackageMediaResolver::class)->resolve((int) $package->company_id, $item->fresh()), $case);
            $this->restore($item, $before);
        }
        Storage::disk('public')->delete($item->fresh()->media->getPathRelativeToRoot());
        $this->assertSame(404, $this->get($this->forge($package, $item))->status());
    }

    /** @return array<string, array{int|null, int}> */
    public static function ttls(): array
    {
        return ['default' => [null, 600], 'below the ceiling' => [300, 300], 'above the ceiling' => [1200, 900]];
    }

    #[DataProvider('ttls')]
    public function test_the_ttl_honors_a_lower_value_and_clamps_to_the_ceiling(?int $configured, int $expected): void
    {
        if ($configured !== null) {
            config(['creative-packages.limits.url_ttl_seconds' => $configured]);
        }
        $package = $this->readyPackage();

        $urls = $this->sign($package);

        $expiry = $this->now()->addSeconds($expected);
        $this->assertSame($expiry->toIso8601ZuluString(), $urls['url_expires_at']);
        $this->assertSame((string) $expiry->getTimestamp(), $this->queryParameters($urls['items'][$package->items->first()->id]['file_url'])['expires']);
    }

    public function test_signing_and_downloading_render_nothing_record_no_usage_and_persist_nothing(): void
    {
        $package = $this->readyPackage();
        $item = $package->items->first();
        $before = [$package->getAttributes(), $item->getAttributes()];

        $urls = $this->sign($package);
        $this->get($urls['items'][$item->id]['file_url'])->assertOk();
        $this->get($urls['items'][$item->id]['preview_url'])->assertOk();

        $this->assertSame([0, [], []], [ApiLog::count(), $this->renderer->renderedDocuments, $this->renderer->screenshotCalls]);
        $this->assertSame($before, [$package->fresh()->getAttributes(), $item->fresh()->getAttributes()]);
        $this->assertStringNotContainsString($urls['items'][$item->id]['file_url'], json_encode([$package->fresh()->getAttributes(), $item->fresh()->getAttributes()]));
    }

    public function test_signing_again_at_the_threshold_keeps_the_object_and_moves_the_expiry(): void
    {
        $package = $this->readyPackage();
        $item = $package->items->first();
        $first = $this->sign($package);
        $later = $this->now()->addSeconds(CreativePackageMediaSigner::ttl() - 120);
        $this->travelTo($later);

        $second = app(CreativePackageMediaSigner::class)->sign($package->fresh(), $later);

        foreach (['file_url', 'preview_url'] as $role) {
            $this->assertSame(strtok($first['items'][$item->id][$role], '?'), strtok($second['items'][$item->id][$role], '?'));
            $this->assertNotSame($first['items'][$item->id][$role], $second['items'][$item->id][$role]);
        }
        $this->assertSame($later->addSeconds(CreativePackageMediaSigner::ttl())->toIso8601ZuluString(), $second['url_expires_at']);
        $this->assertLessThanOrEqual(CreativePackageContract::URL_MAX_TTL_SECONDS, CarbonImmutable::parse($second['url_expires_at'])->getTimestamp() - $later->getTimestamp());
        $response = $this->get($second['items'][$item->id]['file_url']);
        $response->assertOk();
        $this->assertSame(base64_decode(FakeRenderer::FAKE_PNG), $response->streamedContent());
        $this->assertSame([0, [], []], [ApiLog::count(), $this->renderer->renderedDocuments, $this->renderer->screenshotCalls]);
    }

    public function test_the_filename_falls_back_on_a_symbols_only_label_and_omits_a_missing_extension(): void
    {
        // A row with no extension predates the allow list of the media
        // library. The download path must still read it.
        config(['media-library.allowed_extensions' => null]);
        $package = $this->readyPackage(label: '!!!', fileName: 'render');
        $item = $package->items->first();

        $response = $this->get($this->sign($package)['items'][$item->id]['file_url']);

        $response->assertOk();
        $this->assertSame("attachment; filename=creative-{$item->uuid}", $response->headers->get('Content-Disposition'));
    }

    /** The signed route carries no tenant, so the package lookup must seek the single-column uuid index. */
    public function test_the_package_lookup_seeks_the_uuid_index_on_mysql(): void
    {
        $this->requireMysql();
        $package = $this->readyPackage();
        $url = $this->sign($package)['items'][$package->items->first()->id]['file_url'];
        $this->recordCapturedQueries();

        $lookup = $this->explainQueries(fn () => $this->get($url)->assertOk(), lookup: 'select * from `creative_packages` where `uuid` = ?')['lookup'];

        $this->assertCount(1, $lookup);
        $this->assertAccessPlan($lookup[0]['plan'][0], 'creative_packages_uuid_unique', ['const'], 1);
        $this->recordPlanEvidence('media_package_lookup', $lookup);
    }

    /** A ready package whose items each hold one completed snap with a real PNG on the public disk. */
    private function readyPackage(int $items = 1, string $label = 'Launch Day: Feed!', string $fileName = 'render.PNG'): CreativePackage
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create();
        $package = CreativePackage::factory()->create(['company_id' => $company->id, 'created_by' => $user->id, 'status' => CreativePackage::STATUS_READY, 'total_items' => $items, 'ready_items_count' => $items]);
        for ($position = 0; $position < $items; $position++) {
            $snap = Snap::factory()->completed()->create(['company_id' => $company->id, 'template_id' => Template::factory()->for($company)->for($user)->create()->id]);
            $media = $snap->addMediaFromBase64(FakeRenderer::FAKE_PNG)->usingFileName($fileName)->toMediaCollection('image');
            $bytes = base64_decode(FakeRenderer::FAKE_PNG);
            CreativePackageItem::factory()->ready()->for($package, 'package')->create([
                'company_id' => $company->id, 'position' => $position, 'label' => $label, 'snap_id' => $snap->id, 'media_id' => $media->id,
                'output_filename' => $media->file_name, 'output_bytes' => strlen($bytes), 'output_sha256' => hash('sha256', $bytes),
            ]);
        }

        return $package->fresh();
    }

    /** @return array{url_expires_at: string|null, items: array<int, array{file_url: string|null, preview_url: string|null}>} */
    private function sign(CreativePackage $package): array
    {
        return app(CreativePackageMediaSigner::class)->sign($package, $this->now());
    }

    /** A correctly signed file URL the signer refused to issue, so the route alone must refuse it. */
    private function forge(CreativePackage $package, CreativePackageItem $item): string
    {
        return URL::temporarySignedRoute('creative-packages.file', $this->now()->addMinute(), ['package' => $package->uuid, 'item' => $item->uuid]);
    }

    /** @return array<string, string> */
    private function queryParameters(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    /** @return array<string, array<string, mixed>> */
    private function snapshot(CreativePackageItem $item): array
    {
        return ['item' => $item->getAttributes(), 'snap' => $item->snap->getAttributes(), 'media' => $item->media->getAttributes()];
    }

    /** @param array<string, array<string, mixed>> $rows */
    private function restore(CreativePackageItem $item, array $rows): void
    {
        DB::table('media')->updateOrInsert(['id' => $rows['media']['id']], $rows['media']);
        DB::table('snaps')->where('id', $item->snap_id)->update($rows['snap']);
        DB::table('creative_package_items')->where('id', $item->id)->update($rows['item']);
    }
}
