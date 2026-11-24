<?php

namespace Tests\Feature;

use App\Contracts\SnapRenderer;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\Snap;
use App\Models\Template;
use App\Models\User;
use App\Services\TemplateVersioning;
use App\Support\SignedUrlKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

/**
 * A revoked signed URL key keeps the images that it already rendered, but it
 * cannot render a new image. The snap cache key hashes the full URL with its
 * signature, so a cache hit is always a URL that a valid key signed.
 */
class SignedUrlRevocationTest extends TestCase
{
    use RefreshDatabase;

    private FakeRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->renderer = new FakeRenderer;
        $this->app->instance(SnapRenderer::class, $this->renderer);
    }

    /**
     * @return array{0: User, 1: Template, 2: ApiKey, 3: string}
     */
    private function provision(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        $apiKey = $user->createSignedUrlToken();

        return [$user, $template, $apiKey, $apiKey->key];
    }

    private function signedUrl(string $plainKey, array $params): string
    {
        $fields = collect($params)
            ->sortKeys()
            ->map(fn ($value, $key) => "{$key}={$value}")
            ->implode('&');

        return '/signed-url/image.jpg?'.http_build_query($params + [
            'signature' => hash_hmac('sha256', $fields, $plainKey),
        ]);
    }

    private function params(Template $template): array
    {
        return [
            'template_id' => $template->uuid,
            'variants' => base64_encode(json_encode([])),
        ];
    }

    private function assertDefaultImage($response): void
    {
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
    }

    /**
     * Run one request and return the SQL of every query it ran.
     */
    private function queriesOf(string $url): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->get($url)->assertRedirect();

        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $queries;
    }

    private function keyQueries(array $queries): array
    {
        return array_values(array_filter(
            $queries,
            fn ($sql) => str_contains($sql, 'api_keys') || str_contains($sql, 'personal_access_tokens'),
        ));
    }

    public function test_a_valid_signature_gets_the_cached_redirect_with_no_new_render()
    {
        [, $template, , $plainKey] = $this->provision();
        $url = $this->signedUrl($plainKey, $this->params($template));

        $first = $this->get($url)->assertRedirect();
        $this->assertCount(1, $this->renderer->renderedDocuments);

        $second = $this->get($url)->assertRedirect();

        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        $this->assertCount(1, $this->renderer->renderedDocuments);
        $this->assertSame(1, Snap::where('template_id', $template->id)->count());
        $this->assertSame(1, DB::table('api_logs')->where('feature', 'signed_url')->count());
    }

    public function test_a_revoked_key_keeps_its_cached_snap()
    {
        [, $template, $apiKey, $plainKey] = $this->provision();
        $url = $this->signedUrl($plainKey, $this->params($template));

        $first = $this->get($url)->assertRedirect();

        $apiKey->update(['invalidated_at' => now()]);

        $second = $this->get($url)->assertRedirect();

        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        $this->assertCount(1, $this->renderer->renderedDocuments);
    }

    public function test_a_revoked_key_cannot_render_a_new_image()
    {
        [, $template, $apiKey, $plainKey] = $this->provision();
        $this->get($this->signedUrl($plainKey, $this->params($template)))->assertRedirect();

        $apiKey->update(['invalidated_at' => now()]);

        $this->assertDefaultImage($this->get($this->signedUrl($plainKey, $this->params($template) + ['format' => 'webp'])));
        $this->assertCount(1, $this->renderer->renderedDocuments);
    }

    public function test_a_key_revoked_from_the_account_page_cannot_render_a_new_image()
    {
        [$user, $template, $apiKey, $plainKey] = $this->provision();
        $url = $this->signedUrl($plainKey, $this->params($template));

        $this->get($url)->assertRedirect();

        $this->actingAs($user)->delete("/signed-url-keys/{$apiKey->id}")->assertRedirect();

        $this->get($url)->assertRedirect();
        $this->assertDefaultImage($this->get($this->signedUrl($plainKey, $this->params($template) + ['format' => 'webp'])));
        $this->assertCount(1, $this->renderer->renderedDocuments);
    }

    public function test_a_deleted_access_token_cannot_render_a_new_image()
    {
        // The token page deletes the token with a query, not with a model, so
        // no model event fires. The key cache must forget the key all the same.
        [$user, $template, $apiKey, $plainKey] = $this->provision();
        $url = $this->signedUrl($plainKey, $this->params($template));

        $this->get($url)->assertRedirect();

        $this->actingAs($user)->delete("/tokens/{$apiKey->token_id}")->assertRedirect();

        $this->get($url)->assertRedirect();
        $this->assertDefaultImage($this->get($this->signedUrl($plainKey, $this->params($template) + ['format' => 'webp'])));
        $this->assertCount(1, $this->renderer->renderedDocuments);
    }

    public function test_a_publish_stops_the_cached_snap_of_a_revoked_key()
    {
        [, $template, $apiKey, $plainKey] = $this->provision();
        $url = $this->signedUrl($plainKey, $this->params($template));

        $this->get($url)->assertRedirect();

        $apiKey->update(['invalidated_at' => now()]);
        app(TemplateVersioning::class)->publish($template->fresh());
        $rendersAfterPublish = count($this->renderer->renderedDocuments);

        $this->assertDefaultImage($this->get($url));
        $this->assertCount($rendersAfterPublish, $this->renderer->renderedDocuments);
    }

    public function test_a_deactivated_owner_gets_the_default_image_even_when_a_cached_snap_exists()
    {
        [$user, $template, , $plainKey] = $this->provision();
        $url = $this->signedUrl($plainKey, $this->params($template));

        $this->get($url)->assertRedirect();

        $user->forceFill(['deactivated_at' => now()])->save();

        $this->assertDefaultImage($this->get($url));
    }

    public function test_a_wrong_signature_gets_the_default_image()
    {
        [, $template, , $plainKey] = $this->provision();
        $url = $this->signedUrl($plainKey, $this->params($template));

        $this->get($url)->assertRedirect();

        $tampered = preg_replace('/signature=[a-f0-9]+/', 'signature='.str_repeat('0', 64), $url);

        $this->assertDefaultImage($this->get($tampered));
        $this->assertCount(1, $this->renderer->renderedDocuments);
    }

    public function test_a_missing_signature_gets_the_default_image()
    {
        [, $template, , $plainKey] = $this->provision();
        $params = $this->params($template);

        $this->get($this->signedUrl($plainKey, $params))->assertRedirect();

        $this->assertDefaultImage($this->get('/signed-url/image.jpg?'.http_build_query($params)));
        $this->assertCount(1, $this->renderer->renderedDocuments);
    }

    public function test_a_signature_that_is_not_a_string_gets_the_default_image()
    {
        [, $template] = $this->provision();

        $url = '/signed-url/image.jpg?'.http_build_query($this->params($template) + ['signature' => ['a', 'b']]);

        $this->assertDefaultImage($this->get($url));
    }

    public function test_a_new_key_works_at_once_after_the_key_cache_is_warm()
    {
        [$user, $template, , $plainKey] = $this->provision();
        $this->get($this->signedUrl($plainKey, $this->params($template)))->assertRedirect();

        $newKey = $user->createSignedUrlToken();

        $this->get($this->signedUrl($newKey->key, $this->params($template) + ['format' => 'webp']))
            ->assertRedirect();
    }

    public function test_a_cached_hit_reads_no_key_table_when_the_key_cache_is_warm()
    {
        [, $template, , $plainKey] = $this->provision();
        $url = $this->signedUrl($plainKey, $this->params($template));

        $this->get($url)->assertRedirect();

        $queries = $this->queriesOf($url);

        $this->assertSame([], $this->keyQueries($queries), implode("\n", $queries));

        // A cached hit runs four queries: the template, the owner check, the
        // snap and its media. It does not check the signature.
        $this->assertCount(4, $queries, implode("\n", $queries));
    }

    public function test_a_cold_key_cache_costs_two_queries_one_time()
    {
        [, $template, , $plainKey] = $this->provision();

        $this->get($this->signedUrl($plainKey, $this->params($template)))->assertRedirect();
        $this->app['cache']->store()->flush();

        // A new URL misses the snap cache, so the middleware checks the key.
        $webp = $this->signedUrl($plainKey, $this->params($template) + ['format' => 'webp']);
        $jpeg = $this->signedUrl($plainKey, $this->params($template) + ['format' => 'jpeg']);

        $this->assertCount(2, $this->keyQueries($this->queriesOf($webp)));
        $this->assertCount(0, $this->keyQueries($this->queriesOf($jpeg)));
    }

    public function test_the_key_cache_holds_no_plain_key()
    {
        [$user, $template, , $plainKey] = $this->provision();
        $this->get($this->signedUrl($plainKey, $this->params($template)))->assertRedirect();

        $cached = serialize($this->app['cache']->store()->get(SignedUrlKeys::cacheKey($user->company_id)));

        $this->assertStringContainsString('token_id', $cached);
        $this->assertStringNotContainsString($plainKey, $cached);
    }
}
