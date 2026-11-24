<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PreviewApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        RateLimiter::clear('preview');
        parent::tearDown();
    }

    private function createApiUser(array $companyAttributes = []): array
    {
        $company = Company::factory()->create(array_merge(
            [],
            $companyAttributes
        ));
        $user = User::factory()->create(['company_id' => $company->id]);
        $token = $user->createToken('test', ['api_requests']);

        return [$user, $token->plainTextToken];
    }

    private function template(User $user): Template
    {
        return Template::factory()->withLayers([
            ['key' => 'headline', 'type' => 'text', 'properties' => [
                ['name' => 'textContent', 'value' => 'Hi'],
            ]],
        ])->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'width' => '100',
            'height' => '100',
        ]);
    }

    public function test_template_preview_returns_watermarked_html()
    {
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->get("/api/v1/templates/{$template->uuid}/preview");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->assertSee('<!DOCTYPE html>', false);
        $response->assertSee('PREVIEW', false);
    }

    public function test_preview_creates_no_api_log()
    {
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->get("/api/v1/templates/{$template->uuid}/preview")
            ->assertStatus(200);

        $this->assertDatabaseCount('api_logs', 0);
    }

    public function test_preview_leaves_no_persisted_snap()
    {
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->get("/api/v1/templates/{$template->uuid}/preview")
            ->assertStatus(200);

        $this->assertDatabaseCount('snaps', 0);
    }

    public function test_snap_preview_renders_with_variants()
    {
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->get("/api/v1/snaps/preview?template_id={$template->uuid}&variants[0][layer]=headline&variants[0][text]=Custom");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->assertSee('Custom', false);
    }

    public function test_snap_preview_404_for_other_company_template()
    {
        [$user, $token] = $this->createApiUser();
        $otherTemplate = Template::factory()->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/snaps/preview?template_id={$otherTemplate->uuid}")
            ->assertStatus(404);
    }

    public function test_template_preview_404_for_other_company_template()
    {
        [$user, $token] = $this->createApiUser();
        $otherTemplate = Template::factory()->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->get("/api/v1/templates/{$otherTemplate->uuid}/preview")
            ->assertStatus(404);
    }

    public function test_preview_requires_api_requests_ability()
    {
        [$user] = $this->createApiUser();
        $token = $user->createToken('test', ['other_ability'])->plainTextToken;
        $template = $this->template($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->get("/api/v1/templates/{$template->uuid}/preview")
            ->assertStatus(403);
    }

    public function test_preview_unauthenticated_returns_401()
    {
        $template = Template::factory()->create();

        $this->getJson("/api/v1/templates/{$template->uuid}/preview")
            ->assertStatus(401);
    }

    public function test_preview_rejects_internal_image_url_in_variants()
    {
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/snaps/preview?template_id={$template->uuid}&variants[0][layer]=headline&variants[0][image_url]=http://10.0.0.1/x.png")
            ->assertStatus(422);

        $this->assertDatabaseCount('snaps', 0);
    }

    public function test_preview_throttled_after_limit()
    {
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        for ($i = 0; $i < 10; $i++) {
            $this->withHeader('Authorization', "Bearer {$token}")
                ->get("/api/v1/templates/{$template->uuid}/preview")
                ->assertStatus(200);
        }

        $this->withHeader('Authorization', "Bearer {$token}")
            ->get("/api/v1/templates/{$template->uuid}/preview")
            ->assertStatus(429);
    }
}
