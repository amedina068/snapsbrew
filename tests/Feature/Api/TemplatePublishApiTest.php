<?php

namespace Tests\Feature\Api;

use App\Contracts\SnapRenderer;
use App\Models\Company;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Support\ApiDocs\OpenApiGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

/**
 * POST /v1/templates/{templateUuid}/publish.
 *
 * Templates are company-shared. A stranger gets 404 from the company-scoped
 * fetch, and a company peer who does not own the template gets 200.
 */
class TemplatePublishApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Publish renders a version preview image. Keep the test hermetic: no
        // real Chrome and no real disk.
        Storage::fake('public');
        $this->app->bind(SnapRenderer::class, FakeRenderer::class);
    }

    /** @return array{0: User, 1: string} */
    private function createApiUser(?Company $company = null): array
    {
        $company = $company ?: Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $token = $user->createToken('test', ['api_requests']);

        return [$user, $token->plainTextToken];
    }

    private function template(User $user): Template
    {
        return Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'layers' => '[{"key":"a","properties":[]}]',
        ]);
    }

    private function publish(string $token, Template $template)
    {
        return $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/templates/{$template->uuid}/publish");
    }

    public function test_publish_returns_the_new_version_number_of_a_dirty_draft()
    {
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $this->publish($token, $template)->assertStatus(200);

        $template->fresh()->forceFill(['layers' => '[{"key":"b","properties":[]}]'])->save();

        $response = $this->publish($token, $template);

        $response->assertStatus(200);
        $response->assertExactJson([
            'data' => [
                // `id` is deprecated and repeats `uuid`. Both keys are read
                // here, because dropping either one is a break.
                'id' => $template->uuid,
                'uuid' => $template->uuid,
                'published_version' => 2,
            ],
            'message' => 'Template published. New snaps and previews now render this version.',
        ]);
        $this->assertDatabaseHas('template_versions', [
            'template_id' => $template->id,
            'version_number' => 2,
            'published' => true,
        ]);
    }

    public function test_a_stranger_cannot_publish_a_template_of_another_company()
    {
        [$owner] = $this->createApiUser();
        $template = $this->template($owner);

        [, $strangerToken] = $this->createApiUser();

        $this->publish($strangerToken, $template)->assertStatus(404);

        // Scoped to this template. A migration seeds onboarding templates, and
        // each one already carries a backfilled version row.
        $this->assertDatabaseMissing('template_versions', ['template_id' => $template->id]);
    }

    public function test_a_company_peer_who_does_not_own_the_template_can_publish_it()
    {
        [$owner] = $this->createApiUser();
        $template = $this->template($owner);

        [$peer, $peerToken] = $this->createApiUser($owner->company);
        $this->assertNotSame($owner->id, $peer->id);

        $response = $this->publish($peerToken, $template);

        $response->assertStatus(200);
        $response->assertJsonPath('data.published_version', 1);
    }

    /**
     * `ApiSpec` generates the OpenAPI schema. A route that is absent from the
     * schema is a route no customer can find. The assertion reads the generator,
     * not the `ApiSpec` array, because the generator is the surface a client
     * downloads.
     */
    public function test_the_publish_route_reaches_the_generated_openapi_schema()
    {
        $openapi = (new OpenApiGenerator)->toArray();

        $this->assertArrayHasKey('/v1/templates/{templateUuid}/publish', $openapi['paths']);
        $this->assertArrayHasKey('post', $openapi['paths']['/v1/templates/{templateUuid}/publish']);
    }

    public function test_a_repeat_publish_returns_the_same_version_and_writes_no_second_row()
    {
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $first = $this->publish($token, $template);
        $first->assertStatus(200);
        $rowsAfterFirst = TemplateVersion::where('template_id', $template->id)->count();

        $second = $this->publish($token, $template);

        $second->assertStatus(200);
        $this->assertSame(
            $first->json('data.published_version'),
            $second->json('data.published_version')
        );
        $this->assertSame(1, $rowsAfterFirst);
        $this->assertSame(1, TemplateVersion::where('template_id', $template->id)->count());
    }
}
