<?php

namespace Tests\Feature\Api;

use App\Contracts\SnapRenderer;
use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use App\Support\ApiDocs\OpenApiGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

/**
 * GET /v1/templates/{templateUuid} reports the publish state.
 *
 * The MCP tool `get-template-details` returns `published_version` and
 * `has_unpublished_edits`. This endpoint is the REST twin of that tool and it
 * reports the same two fields. The three states are: never published,
 * published with a clean draft, and published with a dirty draft.
 */
class TemplateShowStateApiTest extends TestCase
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
    private function createApiUser(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        // The show route carries `logApiRequest`, which writes against the
        // usage record of the company.
        $token = $user->createToken('test', ['api_requests']);

        return [$user, $token->plainTextToken];
    }

    /**
     * The canvas size is fixed, because one test resizes it and reads the
     * result.
     */
    private function template(User $user): Template
    {
        return Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'title' => 'Launch card',
            // The factory picks a random preset, and `Template::booted()`
            // returns early on a dirty `size`, so the preset is named here.
            'size' => 'open_graph',
            'width' => 1200,
            'height' => 630,
            'layers' => '[{"key":"a","properties":[]}]',
            // `groups` and `editor_document` are not null, because
            // `hasUnpublishedEdits()` compares both. With a null on each side a
            // clean read passes even when the column is never loaded.
            'groups' => '[{"id":"g1","name":"Header"}]',
            'editor_document' => ['schema' => 1, 'nodes' => []],
        ]);
    }

    private function show(string $token, Template $template)
    {
        return $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/templates/{$template->uuid}");
    }

    private function publish(string $token, Template $template)
    {
        return $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/templates/{$template->uuid}/publish");
    }

    public function test_show_reports_no_published_version_for_a_draft_that_was_never_published()
    {
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $response = $this->show($token, $template);

        $response->assertStatus(200);

        // Exact, because `has_unpublished_edits` reads three more columns and
        // none of them belongs in the body.
        $response->assertExactJson([
            'data' => [
                'uuid' => $template->uuid,
                'title' => 'Launch card',
                'size' => 'open_graph',
                'width' => 1200,
                'height' => 630,
                'layers' => [['layer' => 'a', 'available_modifications' => []]],
                'published_version' => null,
                'has_unpublished_edits' => false,
                'created_at' => $template->created_at->toJSON(),
            ],
        ]);
    }

    public function test_show_reports_the_published_version_and_a_clean_draft_after_a_publish()
    {
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $this->publish($token, $template)->assertStatus(200);

        $response = $this->show($token, $template);

        $response->assertStatus(200);
        $response->assertJsonPath('data.published_version', 1);
        $response->assertJsonPath('data.has_unpublished_edits', false);
    }

    public function test_show_reports_unpublished_edits_after_the_draft_layers_change()
    {
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $this->publish($token, $template)->assertStatus(200);

        $template->fresh()->forceFill(['layers' => '[{"key":"b","properties":[]}]'])->save();

        $response = $this->show($token, $template);

        $response->assertStatus(200);
        $response->assertJsonPath('data.published_version', 1);
        $response->assertJsonPath('data.has_unpublished_edits', true);
        // The endpoint returns the draft layers. `has_unpublished_edits` is
        // what tells the caller that these layers do not render yet.
        $response->assertJsonPath('data.layers.0.layer', 'b');
    }

    /**
     * A resize alone is an unpublished edit.
     *
     * `templates.width` is a string column and `template_versions.width` is an
     * integer column, so a comparison with no cast reports every published
     * template as dirty. The clean read below fails on that bug and the dirty
     * read below fails when the width comparison is dropped.
     */
    public function test_show_reports_unpublished_edits_after_a_resize_alone()
    {
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $this->publish($token, $template)->assertStatus(200);

        $this->show($token, $template)->assertJsonPath('data.has_unpublished_edits', false);

        $template->fresh()->forceFill(['width' => 800])->save();

        $response = $this->show($token, $template);

        $response->assertStatus(200);
        $response->assertJsonPath('data.published_version', 1);
        $response->assertJsonPath('data.has_unpublished_edits', true);
    }

    /**
     * The documented type of `width` and `height` must be the type the endpoint
     * returns.
     *
     * `templates.width` and `templates.height` are string columns with no model
     * cast. `TemplateController::show()` casts both to integers, because
     * `store()` already returned integers and one resource must answer one type
     * per field. This test reads both the response and the generated schema, so
     * the two cannot drift apart again.
     */
    public function test_show_returns_the_canvas_size_as_the_type_the_schema_documents()
    {
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $body = $this->show($token, $template)->assertStatus(200)->json();

        $this->assertIsInt($body['data']['width']);
        $this->assertIsInt($body['data']['height']);

        $properties = (new OpenApiGenerator)->toArray()['components']['schemas']['Template']['properties'];

        $this->assertSame('integer', $properties['width']['type']);
        $this->assertSame('integer', $properties['height']['type']);
    }

    /**
     * `ApiSpec` generates the OpenAPI schema. A field that is absent from the
     * schema is a field no customer can find. The assertion reads the
     * generator, not the `ApiSpec` array, because the generator is the surface
     * a client downloads.
     */
    public function test_the_two_publish_state_fields_reach_the_generated_openapi_schema()
    {
        $openapi = (new OpenApiGenerator)->toArray();

        $example = $openapi['paths']['/v1/templates/{templateUuid}']['get']['responses']['200']['content']['application/json']['example'];

        $this->assertArrayHasKey('published_version', $example['data']);
        $this->assertArrayHasKey('has_unpublished_edits', $example['data']);
    }
}
