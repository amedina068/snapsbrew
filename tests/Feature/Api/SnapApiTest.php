<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessSnap;
use App\Models\Company;
use App\Models\Template;
use App\Models\TemplateCollection;
use App\Models\User;
use App\Services\SnapService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SnapApiTest extends TestCase
{
    use RefreshDatabase;

    private function createApiUser(array $companyAttributes = []): array
    {
        $company = Company::factory()->create($companyAttributes);
        $user = User::factory()->create(['company_id' => $company->id]);
        $token = $user->createToken('test', ['api_requests']);

        return [$user, $token->plainTextToken];
    }

    public function test_unauthenticated_cannot_create_a_snap()
    {
        $response = $this->postJson('/api/v1/snaps', []);

        $response->assertStatus(401);
    }

    public function test_token_without_api_requests_ability_is_rejected()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $token = $user->createToken('test', ['other_ability'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/snaps', []);

        $response->assertStatus(403);
    }

    public function test_can_create_a_snap_from_a_template()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/snaps', [
                'template_id' => $template->uuid,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('snaps', [
            'template_id' => $template->id,
            'company_id' => $user->company_id,
            'is_async' => false,
        ]);
        Bus::assertDispatchedSync(ProcessSnap::class);
    }

    public function test_can_create_snap_with_format_and_transparent()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $template = Template::factory()->create(['user_id' => $user->id, 'company_id' => $user->company_id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/snaps', [
                'template_id' => $template->uuid,
                'format' => 'webp',
                'transparent' => true,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('snaps', [
            'template_id' => $template->id,
            'format' => 'webp',
            'transparent' => true,
        ]);
    }

    public function test_jpeg_with_transparent_returns_422()
    {
        [$user, $token] = $this->createApiUser();
        $template = Template::factory()->create(['user_id' => $user->id, 'company_id' => $user->company_id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/snaps', [
                'template_id' => $template->uuid,
                'format' => 'jpeg',
                'transparent' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('transparent');
    }

    public function test_invalid_format_is_rejected()
    {
        [$user, $token] = $this->createApiUser();
        $template = Template::factory()->create(['user_id' => $user->id, 'company_id' => $user->company_id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/snaps', [
                'template_id' => $template->uuid,
                'format' => 'pdf',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('format');
    }

    /**
     * An unknown template_id fails the `exists` rule. That is a validation
     * error, so the answer is 422 with the field error, not 404.
     */
    public function test_an_unknown_template_id_returns_422_with_the_field_error()
    {
        [$user, $token] = $this->createApiUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/snaps', [
                'template_id' => '00000000-0000-0000-0000-000000000000',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('template_id');
        $this->assertDatabaseCount('snaps', 0);
    }

    /**
     * The caller is authenticated, so a refused permission is 403. A 401 tells
     * a client to send other credentials, which does not help.
     */
    public function test_an_authorization_failure_returns_403_and_not_401()
    {
        [$user, $token] = $this->createApiUser();
        $template = Template::factory()->create(['user_id' => $user->id, 'company_id' => $user->company_id]);

        $this->mock(SnapService::class, function ($mock) {
            $mock->shouldReceive('create')->once()->andThrow(new AuthorizationException('This action is unauthorized.'));
        });

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/snaps', ['template_id' => $template->uuid]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('snaps', 0);
    }

    public function test_create_snap_rejects_internal_image_url_in_variants()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/snaps', [
                'template_id' => $template->uuid,
                'variants' => [
                    ['layer' => 'bg', 'image_url' => 'http://10.0.0.1/evil.png'],
                ],
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('snaps', 0);
        Bus::assertNothingDispatched();
    }

    public function test_creating_a_snap_returns_the_request_uuid_header()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/snaps', [
                'template_id' => $template->uuid,
            ]);

        $response->assertStatus(201);
        $response->assertHeader('X-Request-Uuid');
    }

    public function test_can_create_snaps_from_a_collection()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();

        $collection = TemplateCollection::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $templates = Template::factory()->count(3)->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $collection->templates()->attach($templates->pluck('id'));

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/snaps', [
                'collection_id' => $collection->uuid,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('snaps', 3);
    }

    public function test_can_create_an_async_snap_with_a_webhook_url()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/snaps', [
                'template_id' => $template->uuid,
                'async' => true,
                'webhook_url' => 'https://example.com/webhook',
            ]);

        $response->assertStatus(202);
        $this->assertDatabaseHas('snaps', [
            'template_id' => $template->id,
            'is_async' => true,
            'webhook_url' => 'https://example.com/webhook',
        ]);
        Bus::assertDispatched(ProcessSnap::class);
    }

    public function test_async_snap_requires_a_webhook_url()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/snaps', [
                'template_id' => $template->uuid,
                'async' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('webhook_url');
    }

    public function test_cannot_create_a_snap_for_another_companys_template()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();

        $otherTemplate = Template::factory()->create();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/snaps', [
                'template_id' => $otherTemplate->uuid,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('template_id');
    }

    public function test_creating_a_snap_without_template_or_collection_is_rejected()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/snaps', []);

        $response->assertStatus(422);
    }

    public function test_cannot_provide_both_template_and_collection()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);
        $collection = TemplateCollection::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/snaps', [
                'template_id' => $template->uuid,
                'collection_id' => $collection->uuid,
            ]);

        $response->assertStatus(422);
    }
}
