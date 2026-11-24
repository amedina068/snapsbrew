<?php

namespace Tests\Feature;

use App\Contracts\SnapRenderer;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\Snap;
use App\Models\Template;
use App\Models\User;
use App\Services\TemplateVersioning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Spatie\Browsershot\Browsershot;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Tests\Support\FakeRenderer;
use Tests\Support\ThrowingRenderer;
use Tests\TestCase;

/**
 * Guards SignedUrlMiddleware::resolveApiKey(). The HMAC verification ran
 * `->first(Closure)` on an Eloquent Builder — the Closure was read as the
 * $columns argument and every signed-URL request with a valid template +
 * signature 500'd before the HMAC check executed. These tests are RED on that
 * bug (500 instead of redirect / default image) and GREEN once the callback
 * filters a Collection.
 */
class SignedUrlMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A valid 20x20 PNG, base64 encoded (decodable by GD for media conversions).
     */
    private const FAKE_PNG = 'iVBORw0KGgoAAAANSUhEUgAAABQAAAAUCAIAAAAC64paAAAACXBIWXMAAA7EAAAOxAGV'
        .'Kw4bAAAAG0lEQVQ4jWP8z0A+YKJA76jmUc2jmkc1U0EzACKcASfaaIZkAAAAAElFTkSuQmCC';

    private function fakeBrowsershot(): void
    {
        $browsershot = Mockery::mock('overload:'.Browsershot::class);
        $fluent = [
            'html', 'url', 'setOption', 'setNodeBinary', 'setNpmBinary',
            'windowSize', 'waitUntilNetworkIdle', 'preventUnsuccessfulResponse',
            'setScreenshotType', 'transparentBackground',
        ];
        foreach ($fluent as $method) {
            $browsershot->shouldReceive($method)->andReturnSelf();
        }
        $browsershot->shouldReceive('base64Screenshot')->andReturn(self::FAKE_PNG);
    }

    /**
     * Build the signed-url query the way the middleware does: every param
     * except signature/identifier/api_log_uuid, sorted, "k=v", joined by "&",
     * HMAC-SHA256 keyed on the plaintext API key.
     */
    private function signedUrl(string $plainKey, array $params): string
    {
        $fields = collect($params)
            ->sortKeys()
            ->map(fn ($value, $key) => "{$key}={$value}")
            ->implode('&');

        $signature = hash_hmac('sha256', $fields, $plainKey);

        return '/signed-url/image.jpg?'.http_build_query($params + ['signature' => $signature]);
    }

    /**
     * Company + non-admin user + template + a signed_url ApiKey with a known
     * plaintext key. Returns [User, Template, plaintext key].
     */
    private function provisionSignedUrlKey(?string $invalidatedAt = null): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        $plainKey = Str::random(40);
        ApiKey::factory()->signedUrl()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'key' => $plainKey,
            'invalidated_at' => $invalidatedAt,
        ]);

        return [$user, $template, $plainKey];
    }

    public function test_valid_hmac_signature_passes_middleware_and_logs_request()
    {
        Storage::fake('public');
        $this->fakeBrowsershot();

        [$user, $template, $plainKey] = $this->provisionSignedUrlKey();

        $url = $this->signedUrl($plainKey, [
            'template_id' => $template->uuid,
            'variants' => base64_encode(json_encode(['title' => 'Hi'])),
        ]);

        $response = $this->get($url);

        $response->assertRedirect();
        // The middleware logs the request and the controller records the
        // render. settle() sets type to image and keeps feature=signed_url. The
        // row proves that the HMAC check passed and the request rendered.
        $this->assertDatabaseHas('api_logs', [
            'user_id' => $user->id,
            'feature' => 'signed_url',
            'renders' => 1,
        ]);
    }

    public function test_tampered_signature_returns_default_image()
    {
        [, $template, $plainKey] = $this->provisionSignedUrlKey();

        // Valid URL, then corrupt the signature.
        $url = $this->signedUrl($plainKey, [
            'template_id' => $template->uuid,
            'variants' => base64_encode(json_encode(['title' => 'Hi'])),
        ]);
        $url = preg_replace('/signature=[a-f0-9]+/', 'signature=deadbeef', $url);

        $response = $this->get($url);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertDatabaseMissing('api_logs', ['type' => 'signed_url']);
    }

    public function test_invalidated_key_is_not_accepted()
    {
        [, $template, $plainKey] = $this->provisionSignedUrlKey(
            invalidatedAt: now()->subMinute()
        );

        // Signature is otherwise valid; only the invalidated_at clause should reject it.
        $url = $this->signedUrl($plainKey, [
            'template_id' => $template->uuid,
            'variants' => base64_encode(json_encode(['title' => 'Hi'])),
        ]);

        $response = $this->get($url);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertDatabaseMissing('api_logs', ['type' => 'signed_url']);
    }

    public function test_malicious_but_valid_hmac_variant_is_rejected()
    {
        // S5: the HMAC proves the URL was issued by us, NOT that the variant
        // payload is safe. A signed URL whose decoded variants carry an SSRF
        // image_url must be rejected (400) before any render.
        [, $template, $plainKey] = $this->provisionSignedUrlKey();

        $url = $this->signedUrl($plainKey, [
            'template_id' => $template->uuid,
            'variants' => base64_encode(json_encode([
                ['layer' => 'bg', 'image_url' => 'http://169.254.169.254/latest/meta-data/'],
            ])),
        ]);

        $response = $this->get($url);

        $response->assertStatus(400);
        $this->assertDatabaseMissing('api_logs', ['feature' => 'signed_url']);
    }

    public function test_variant_with_css_injection_color_is_rejected()
    {
        // S5: a disallowed background_color must be rejected post-decode.
        [, $template, $plainKey] = $this->provisionSignedUrlKey();

        $url = $this->signedUrl($plainKey, [
            'template_id' => $template->uuid,
            'variants' => base64_encode(json_encode([
                ['layer' => 'bg', 'background_color' => 'red;background:url(//evil)'],
            ])),
        ]);

        $response = $this->get($url);

        $response->assertStatus(400);
    }

    public function test_signed_url_cache_busts_after_publish()
    {
        // T-707: the md5(fullUrl) dedup folds in published_version_id, so
        // publishing a new version stops serving the retracted cached render.
        Storage::fake('public');
        $this->fakeBrowsershot();

        [, $template, $plainKey] = $this->provisionSignedUrlKey();

        $url = $this->signedUrl($plainKey, [
            'template_id' => $template->uuid,
            'variants' => base64_encode(json_encode([])),
        ]);

        $this->get($url)->assertRedirect();
        $firstCount = Snap::where('template_id', $template->id)->count();

        // Publish → published_version_id changes → cache identifier changes.
        app(TemplateVersioning::class)->publish($template->fresh());

        $this->get($url)->assertRedirect();
        $secondCount = Snap::where('template_id', $template->id)->count();

        // A fresh render happened rather than the first snap being served from cache.
        $this->assertGreaterThan($firstCount, $secondCount);
    }

    public function test_failed_render_leaves_no_snap_and_records_no_render()
    {
        // A render that throws used to leave the snap row behind with no image.
        // Every later request for the same URL found that row and redirected to
        // an empty URL, which is the home page.
        Storage::fake('public');
        $this->app->instance(SnapRenderer::class, new ThrowingRenderer);

        [$user, $template, $plainKey] = $this->provisionSignedUrlKey();

        $url = $this->signedUrl($plainKey, [
            'template_id' => $template->uuid,
            'variants' => base64_encode(json_encode([])),
        ]);

        $this->get($url)->assertStatus(500);

        $this->assertSame(0, Snap::where('template_id', $template->id)->count());
        $this->assertDatabaseHas('api_logs', [
            'user_id' => $user->id,
            'feature' => 'signed_url',
            'renders' => 0,
            'status_code' => 500,
        ]);
        $this->assertDatabaseMissing('api_logs', ['user_id' => $user->id, 'renders' => 1]);
    }

    public function test_stale_snap_without_image_renders_again_instead_of_redirecting_home()
    {
        Storage::fake('public');
        $this->app->instance(SnapRenderer::class, new FakeRenderer);

        [, $template, $plainKey] = $this->provisionSignedUrlKey();

        $url = $this->signedUrl($plainKey, [
            'template_id' => $template->uuid,
            'variants' => base64_encode(json_encode([])),
        ]);

        $this->get($url)->assertRedirect();

        // Recreate the production state: a row with the identifier and no image.
        $stale = Snap::where('template_id', $template->id)->sole();
        $stale->clearMediaCollection('image');
        $this->assertSame('', $stale->fresh()->imageUrl);

        $response = $this->get($url);

        $response->assertStatus(302);
        $location = $response->headers->get('Location');
        $this->assertNotSame(rtrim(config('app.url'), '/'), rtrim($location, '/'));

        $rendered = Snap::where('template_id', $template->id)
            ->whereHas('media', fn ($query) => $query->where('collection_name', 'image'))
            ->sole();
        $this->assertSame(url($rendered->imageUrl), $location);
    }

    public function test_render_that_stores_no_image_returns_an_error_not_a_redirect_home()
    {
        Storage::fake('public');
        $this->app->instance(SnapRenderer::class, new FakeRenderer);

        // The render returns, but the image never reaches the media collection.
        Event::listen(MediaHasBeenAddedEvent::class, function (MediaHasBeenAddedEvent $event) {
            if ($event->media->collection_name === 'image') {
                $event->media->delete();
            }
        });

        [$user, $template, $plainKey] = $this->provisionSignedUrlKey();

        $url = $this->signedUrl($plainKey, [
            'template_id' => $template->uuid,
            'variants' => base64_encode(json_encode([])),
        ]);

        $response = $this->get($url);

        $response->assertStatus(500);
        $this->assertSame(0, Snap::where('template_id', $template->id)->count());
        $this->assertDatabaseHas('api_logs', ['user_id' => $user->id, 'renders' => 0, 'status_code' => 500]);
        $this->assertDatabaseMissing('api_logs', ['user_id' => $user->id, 'renders' => 1]);
    }

    public function test_missing_template_id_returns_default_image()
    {
        $response = $this->get('/signed-url/image.jpg?template_id='.Str::uuid().'&signature=abc');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertDatabaseMissing('api_logs', ['type' => 'signed_url']);
    }
}
