<?php

namespace Tests\Feature\Api;

use App\Contracts\SnapRenderer;
use App\Models\Company;
use App\Models\Screenshot;
use App\Models\Template;
use App\Models\User;
use App\Support\ApiDocs\MarkdownGenerator;
use App\Support\ApiDocs\OpenApiGenerator;
use App\Support\ApiDocs\PostmanGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

/**
 * One envelope shape across the endpoints this change touched.
 *
 * Every success body that carries a resource is `{"data": ...}`. A list adds
 * `meta` and a write adds `message`. A delete is the one exemption, because
 * the row is gone and there is no resource to return.
 *
 * Every assertion below reads the whole decoded body. `assertJsonFragment`
 * searches at every depth, so it passes on the flat body and on the wrapped
 * one, which makes it blind to the defect this change repairs.
 *
 * `GET /v1/templates/{templateUuid}` and
 * `POST /v1/templates/{templateUuid}/publish` hold their whole-body
 * assertions in `TemplateApiTest`, `TemplateShowStateApiTest` and
 * `TemplatePublishApiTest`, beside the other tests of those endpoints.
 */
class ApiEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A template write regenerates the preview image. Keep the test
        // hermetic: no real Chrome and no real disk.
        Storage::fake('public');
        $this->app->bind(SnapRenderer::class, FakeRenderer::class);
    }

    /** @return array{0: User, 1: string} */
    private function createApiUser(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        return [$user, $user->createToken('test', ['api_requests'])->plainTextToken];
    }

    public function test_the_auth_read_wraps_the_account_in_data()
    {
        [$user, $token] = $this->createApiUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth');

        $response->assertStatus(200);
        $this->assertSame([
            'data' => [
                'email' => $user->email,
                'name' => $user->name,
                'company' => $user->company->name,
            ],
        ], $response->json());
    }

    public function test_the_screenshot_read_wraps_the_screenshot_in_data()
    {
        [$user, $token] = $this->createApiUser();

        $screenshot = Screenshot::factory()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/screenshots/{$screenshot->uuid}");

        $response->assertStatus(200);
        $this->assertSame([
            'data' => [
                'id' => $screenshot->uuid,
                'url' => $screenshot->url,
                'image_url' => $screenshot->imageUrl,
                'width' => $screenshot->width,
                'height' => $screenshot->height,
                'created_at' => $screenshot->created_at->toJSON(),
            ],
        ], $response->json());
    }

    public function test_the_template_update_returns_the_resolved_canvas_under_data()
    {
        [$user, $token] = $this->createApiUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'size' => 'open_graph',
            'width' => 1200,
            'height' => 630,
            'layers' => json_encode([]),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/templates/{$template->uuid}", [
                'title' => 'Renamed',
                'size' => 'instagram_story',
            ]);

        $response->assertStatus(200);

        // The write resolves the canvas, so `size`, `width` and `height` are
        // state the caller cannot compute from the request body.
        $this->assertSame([
            'data' => [
                'uuid' => $template->uuid,
                'title' => 'Renamed',
                'size' => 'instagram_story',
                'width' => 1080,
                'height' => 1920,
                'created_at' => $template->created_at->toJSON(),
            ],
            'message' => 'Template updated successfully',
        ], $response->json());
    }

    public function test_the_template_delete_carries_no_data_key()
    {
        [$user, $token] = $this->createApiUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/templates/{$template->uuid}");

        $response->assertStatus(200);

        // The exemption, asserted. The template is gone, so a `data` key here
        // would describe a resource that no longer exists.
        $this->assertSame(['message' => 'Template deleted successfully'], $response->json());
    }

    /**
     * The generated OpenAPI document must describe the shape the code answers.
     *
     * The assertion reads the generator, not the `ApiSpec` array, because the
     * generator builds the artifact a client downloads. A documented example
     * that keeps the old depth sends every generated client to the wrong key.
     */
    public function test_the_generated_openapi_documents_the_data_envelope_on_every_touched_endpoint()
    {
        $openapi = (new OpenApiGenerator)->toArray();

        $wrapped = [
            ['GET', '/v1/auth'],
            ['GET', '/v1/screenshots/{screenshotUuid}'],
            ['POST', '/v1/screenshots'],
            ['GET', '/v1/templates/{templateUuid}'],
            ['PATCH', '/v1/templates/{templateIdentifier}'],
            ['POST', '/v1/templates/{templateUuid}/publish'],
        ];

        foreach ($wrapped as [$method, $path]) {
            $example = $this->successExample($openapi, $method, $path);

            $this->assertArrayHasKey(
                'data',
                $example,
                "The documented 200 body of {$method} {$path} carries no `data` key."
            );
        }

        $deleted = $this->successExample($openapi, 'DELETE', '/v1/templates/{templateIdentifier}');

        $this->assertSame(
            ['message' => 'Template deleted successfully'],
            $deleted,
            'A delete returns no resource, so its documented body holds `message` alone.'
        );
    }

    /**
     * The two write bodies carry `message` beside `data`.
     */
    public function test_the_generated_openapi_documents_a_message_beside_the_data_of_a_write()
    {
        $openapi = (new OpenApiGenerator)->toArray();

        foreach ([['POST', '/v1/templates'], ['PATCH', '/v1/templates/{templateIdentifier}'], ['POST', '/v1/templates/{templateUuid}/publish']] as [$method, $path]) {
            $example = $this->successExample($openapi, $method, $path);

            $this->assertArrayHasKey(
                'message',
                $example,
                "The documented 200 body of {$method} {$path} carries no `message` key."
            );
        }
    }

    /**
     * The publish body documents `uuid`, keeps `id` and says `id` is old.
     */
    public function test_the_generated_openapi_documents_uuid_beside_the_deprecated_id_on_publish()
    {
        $openapi = (new OpenApiGenerator)->toArray();

        $operation = $openapi['paths']['/v1/templates/{templateUuid}/publish']['post'];
        $example = $operation['responses']['200']['content']['application/json']['example'];

        $this->assertArrayHasKey('uuid', $example['data']);
        $this->assertArrayHasKey('id', $example['data']);
        $this->assertSame($example['data']['uuid'], $example['data']['id']);

        $this->assertStringContainsString(
            '`data.id` is deprecated',
            $operation['description'],
            'The publish operation must say which of the two keys to read.'
        );
    }

    /**
     * @param  array<string, mixed>  $openapi
     * @return array<string, mixed>
     */
    private function successExample(array $openapi, string $method, string $path): array
    {
        $this->assertArrayHasKey($path, $openapi['paths'], "The OpenAPI document holds no path {$path}.");

        $operation = $openapi['paths'][$path][strtolower($method)] ?? null;

        $this->assertNotNull($operation, "The OpenAPI document holds no {$method} {$path}.");

        return $operation['responses']['200']['content']['application/json']['example'];
    }

    /**
     * A note of ApiSpec reaches every generated surface.
     *
     * `CLAUDE.md` promises that every documentation surface updates from
     * `ApiSpec` on its own. `PostmanGenerator` read the prose of an endpoint
     * and nothing else, so no `notes` entry ever reached the collection. The
     * gap was invisible until one note carried a breaking change.
     *
     * The note this test reads is the deprecation of `data.id` on the publish
     * body, which a client must see before it writes against that field.
     */
    public function test_a_note_reaches_the_openapi_the_postman_and_the_markdown(): void
    {
        $marker = '`data.id` is deprecated';

        $openApi = app(OpenApiGenerator::class)->toYaml();
        $postman = json_encode(app(PostmanGenerator::class)->toArray());
        $markdown = app(MarkdownGenerator::class)->group('templates');

        $this->assertStringContainsString($marker, $openApi, 'the OpenAPI document lost the note');
        $this->assertStringContainsString($marker, $postman, 'the Postman collection lost the note');
        $this->assertStringContainsString($marker, $markdown, 'the Markdown lost the note');
    }
}
