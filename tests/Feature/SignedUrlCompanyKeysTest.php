<?php

namespace Tests\Feature;

use App\Contracts\SnapRenderer;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

/**
 * A template belongs to the company, so any active signed URL key of the
 * company signs its URLs. TemplatePolicy and the API tokens already share
 * templates across the company.
 */
class SignedUrlCompanyKeysTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->app->instance(SnapRenderer::class, new FakeRenderer);
    }

    public function test_a_key_of_a_colleague_signs_the_template(): void
    {
        $owner = User::factory()->create();
        $colleague = User::factory()->create(['company_id' => $owner->company_id]);
        $template = Template::factory()->create(['user_id' => $owner->id, 'company_id' => $owner->company_id]);

        $key = $colleague->createSignedUrlToken();

        $this->get($this->signedUrl($key->key, $template))->assertRedirect();

        $this->assertDatabaseHas('snaps', ['template_id' => $template->id, 'user_id' => $colleague->id]);
        $this->assertDatabaseHas('api_logs', [
            'user_id' => $colleague->id,
            'personal_access_token_id' => $key->token_id,
            'feature' => 'signed_url',
            'renders' => 1,
        ]);
    }

    public function test_a_key_of_another_company_is_refused(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $template = Template::factory()->create(['user_id' => $owner->id, 'company_id' => $owner->company_id]);

        $key = $stranger->createSignedUrlToken();

        $this->assertDefaultImage($this->get($this->signedUrl($key->key, $template)));
        $this->assertDatabaseMissing('api_logs', ['type' => 'signed_url']);
    }

    public function test_a_key_of_a_deactivated_colleague_is_refused_at_once(): void
    {
        $owner = User::factory()->create();
        $colleague = User::factory()->create(['company_id' => $owner->company_id]);
        $template = Template::factory()->create(['user_id' => $owner->id, 'company_id' => $owner->company_id]);
        $key = $colleague->createSignedUrlToken();

        // Warm the key cache first, so the test proves that deactivation clears it.
        $this->get($this->signedUrl($key->key, $template, ['format' => 'webp']))->assertRedirect();

        $colleague->deactivate();

        $this->assertDefaultImage($this->get($this->signedUrl($key->key, $template, ['format' => 'jpeg'])));
    }

    public function test_a_reactivated_colleague_signs_again_at_once(): void
    {
        $owner = User::factory()->create();
        $colleague = User::factory()->create(['company_id' => $owner->company_id]);
        $template = Template::factory()->create(['user_id' => $owner->id, 'company_id' => $owner->company_id]);
        $key = $colleague->createSignedUrlToken();
        $colleague->deactivate();

        $this->assertDefaultImage($this->get($this->signedUrl($key->key, $template, ['format' => 'webp'])));

        $colleague->reactivate();

        $this->get($this->signedUrl($key->key, $template, ['format' => 'jpeg']))->assertRedirect();
    }

    private function signedUrl(string $plainKey, Template $template, array $extra = []): string
    {
        $params = ['template_id' => $template->uuid, 'variants' => base64_encode('[]')] + $extra;

        $fields = collect($params)->sortKeys()->map(fn ($value, $key) => "{$key}={$value}")->implode('&');

        return '/signed-url/image.jpg?'.http_build_query($params + ['signature' => hash_hmac('sha256', $fields, $plainKey)]);
    }

    private function assertDefaultImage($response): void
    {
        $response->assertOk()->assertHeader('Content-Type', 'image/png');
    }
}
