<?php

namespace Tests\Feature\Api;

use App\Contracts\SnapRenderer;
use App\Models\Company;
use App\Models\IdempotencyKey;
use App\Models\Screenshot;
use App\Models\Template;
use App\Models\User;
use App\Services\SnapBatchService;
use App\Services\SnapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery;
use RuntimeException;
use Tests\Support\FakeRenderer;
use Tests\Support\ReadsOpenApiDocument;
use Tests\TestCase;

/**
 * Real responses from the controllers match the schemas in the served
 * OpenAPI document.
 *
 * A documented example cannot drift from the code without a test that sends
 * the request. Each test here sends one, then checks the raw body against the
 * schema that a generated client reads.
 */
class OpenApiResponseShapeTest extends TestCase
{
    use ReadsOpenApiDocument;
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Read the document before the first API call. A web request after a
        // bearer request in one test reuses the Sanctum guard and fails.
        $this->openApi();

        $this->app->bind(SnapRenderer::class, FakeRenderer::class);

        $company = Company::factory()->create();
        $this->user = User::factory()->create(['company_id' => $company->id]);
        $this->token = $this->user->createToken('test', ['api_requests'])->plainTextToken;
    }

    private function api(): self
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    private function template(array $attributes = []): Template
    {
        return Template::factory()->create($attributes + [
            'user_id' => $this->user->id,
            'company_id' => $this->user->company_id,
            'layers' => json_encode([
                ['key' => 'headline', 'type' => 'text', 'properties' => [['name' => 'textContent', 'value' => 'Hello']]],
                ['key' => 'frame', 'type' => 'empty', 'properties' => []],
            ]),
        ]);
    }

    private function assertDocumented(TestResponse $response, string $method, string $path, int $status, string $contentType = 'application/json'): void
    {
        $response->assertStatus($status);

        $this->assertMatchesSchema($response->getContent(), $this->responseSchema($method, $path, $status, $contentType));
    }

    // -------------------------------------------------------------- snaps

    public function test_a_sync_snap_matches_the_documented_snap()
    {
        $template = $this->template();

        $response = $this->api()->postJson('/api/v1/snaps', [
            'template_id' => $template->uuid,
            'variants' => [
                ['layer' => 'headline', 'text' => 'Hi'],
                ['layer' => 'frame', 'hidden' => true],
                ['layer' => 'code', 'barcode_content' => '5901234123457'],
            ],
        ]);

        $this->assertDocumented($response, 'POST', '/v1/snaps', 201);
        $this->assertArrayNotHasKey('data', $response->json());
        $this->assertIsInt($response->json('width'));
    }

    public function test_an_async_snap_with_no_variants_matches_the_documented_snap()
    {
        Bus::fake();
        $template = $this->template();

        $response = $this->api()->postJson('/api/v1/snaps', [
            'template_id' => $template->uuid,
            'async' => true,
            'webhook_url' => 'https://example.com/hook',
        ]);

        $this->assertDocumented($response, 'POST', '/v1/snaps', 202);
        $this->assertNull($response->json('variants'));
    }

    public function test_a_snap_with_no_media_returns_the_empty_string_that_the_document_shows()
    {
        Bus::fake();
        $template = $this->template();

        $response = $this->api()->postJson('/api/v1/snaps', [
            'template_id' => $template->uuid,
            'async' => true,
            'webhook_url' => 'https://example.com/hook',
        ]);

        $this->assertSame('', $response->json('media_url'));

        $field = $this->resolveSchema(['$ref' => '#/components/schemas/Snap'])['properties']['media_url'];
        $this->assertSame('string', $field['type']);
        $this->assertArrayNotHasKey('nullable', $field);

        $example = $this->operation('POST', '/v1/snaps')['responses']['202']['content']['application/json']['example'];
        $this->assertSame('', $example['media_url']);
    }

    public function test_the_snap_errors_match_the_documented_errors()
    {
        $template = $this->template();

        $this->assertDocumented(
            $this->api()->postJson('/api/v1/snaps', ['template_id' => '00000000-0000-0000-0000-000000000000']),
            'POST', '/v1/snaps', 422,
        );

        IdempotencyKey::create([
            'company_id' => $this->user->company_id,
            'key' => 'busy',
            'status' => IdempotencyKey::STATUS_IN_PROGRESS,
        ]);

        $this->assertDocumented(
            $this->api()->postJson('/api/v1/snaps', ['template_id' => $template->uuid, 'idempotency_key' => 'busy']),
            'POST', '/v1/snaps', 409,
        );
    }

    /**
     * Make one method of a service throw, so the controller answers 500.
     */
    private function forceException(string $service, string $method): void
    {
        config(['app.debug' => false]);

        $mock = Mockery::mock($service)->makePartial();
        $mock->shouldReceive($method)->once()->andThrow(new RuntimeException('forced'));
        $this->app->instance($service, $mock);
    }

    public function test_an_unexpected_snap_error_matches_the_documented_internal_error()
    {
        $template = $this->template();
        $this->forceException(SnapService::class, 'create');

        $response = $this->api()->postJson('/api/v1/snaps', ['template_id' => $template->uuid]);

        $this->assertDocumented($response, 'POST', '/v1/snaps', 500);
        $response->assertJsonPath('code', 'internal_error');
    }

    // ------------------------------------------------------------ batches

    public function test_a_batch_matches_the_documented_batch_on_create_read_and_conflict()
    {
        Bus::fake();
        $template = $this->template();

        $created = $this->api()->postJson('/api/v1/snaps/batches', [
            'template_id' => $template->uuid,
            'idempotency_key' => 'batch-1',
            'items' => [
                ['identifier' => 'row-1', 'variants' => [['layer' => 'headline', 'text' => 'One']]],
                ['variants' => []],
            ],
        ]);
        $this->assertDocumented($created, 'POST', '/v1/snaps/batches', 202);

        $read = $this->api()->getJson('/api/v1/snaps/batches/'.$created->json('uuid'));
        $this->assertDocumented($read, 'GET', '/v1/snaps/batches/{batchUuid}', 200);
        $this->assertCount(2, $read->json('items'));

        $this->assertDocumented(
            $this->api()->postJson('/api/v1/snaps', ['template_id' => $template->uuid, 'idempotency_key' => 'batch-1']),
            'POST', '/v1/snaps', 409,
        );

        $this->assertDocumented(
            $this->api()->postJson('/api/v1/snaps/batches', ['template_id' => $template->uuid, 'items' => []]),
            'POST', '/v1/snaps/batches', 422,
        );
    }

    /**
     * A batch item and a snap describe "no finished image" with two values.
     * The item answers null, the snap answers "". The owner kept both, so the
     * document must name both, and each field must say what the other uses.
     */
    public function test_a_batch_item_that_is_not_complete_returns_the_null_that_the_document_shows()
    {
        Bus::fake();
        $template = $this->template();

        $created = $this->api()->postJson('/api/v1/snaps/batches', [
            'template_id' => $template->uuid,
            'items' => [['identifier' => 'row-1', 'variants' => [['layer' => 'headline', 'text' => 'One']]]],
        ]);

        $read = $this->api()->getJson('/api/v1/snaps/batches/'.$created->json('uuid'));
        $read->assertJsonPath('items.0.status', 'pending');
        $this->assertNull($read->json('items.0.media_url'));

        $item = $this->resolveSchema(['$ref' => '#/components/schemas/BatchItem'])['properties']['media_url'];
        $this->assertSame('string', $item['type']);
        $this->assertTrue($item['nullable']);
        $this->assertStringContainsString('Null', $item['description']);
        $this->assertStringContainsString('empty string', $item['description']);

        $snap = $this->resolveSchema(['$ref' => '#/components/schemas/Snap'])['properties']['media_url'];
        $this->assertStringContainsString('batch item', $snap['description']);
    }

    public function test_an_unexpected_batch_error_matches_the_documented_internal_error()
    {
        $template = $this->template();
        $this->forceException(SnapBatchService::class, 'create');

        $response = $this->api()->postJson('/api/v1/snaps/batches', [
            'template_id' => $template->uuid,
            'items' => [['identifier' => 'row-1', 'variants' => [['layer' => 'headline', 'text' => 'One']]]],
        ]);

        $this->assertDocumented($response, 'POST', '/v1/snaps/batches', 500);
        $response->assertJsonPath('code', 'internal_error');
    }

    // ---------------------------------------------------------- templates

    public function test_the_template_list_and_read_match_the_documented_shapes()
    {
        $template = $this->template();

        $this->assertDocumented($this->api()->getJson('/api/v1/templates'), 'GET', '/v1/templates', 200);

        $read = $this->api()->getJson('/api/v1/templates/'.$template->uuid);
        $this->assertDocumented($read, 'GET', '/v1/templates/{templateUuid}', 200);

        // A layer with no override encodes as an empty JSON array, not `{}`.
        $this->assertStringContainsString('"available_modifications":[]', $read->getContent());
    }

    public function test_a_legacy_edit_of_a_v2_template_matches_the_documented_conflict()
    {
        $template = $this->template(['editor_schema_version' => 2]);

        $this->assertDocumented(
            $this->api()->patchJson('/api/v1/templates/'.$template->uuid, ['title' => 'New']),
            'PATCH', '/v1/templates/{templateIdentifier}', 409,
        );
    }

    // -------------------------------------------------------- screenshots

    public function test_the_screenshot_list_and_read_match_the_documented_shapes()
    {
        $screenshot = Screenshot::factory()->create([
            'company_id' => $this->user->company_id,
            'user_id' => $this->user->id,
        ]);

        $list = $this->api()->getJson('/api/v1/screenshots');
        $this->assertDocumented($list, 'GET', '/v1/screenshots', 200);
        $this->assertSame($screenshot->uuid, $list->json('data.0.uuid'));

        $read = $this->api()->getJson('/api/v1/screenshots/'.$screenshot->uuid);
        $this->assertDocumented($read, 'GET', '/v1/screenshots/{screenshotUuid}', 200);
        $this->assertSame($screenshot->uuid, $read->json('data.id'));
    }

    // -------------------------------------------------------------- media

    public function test_the_media_upload_and_list_match_the_documented_shapes()
    {
        Storage::fake('public');

        $upload = $this->api()->post('/api/v1/media', ['file' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json']);
        $this->assertDocumented($upload, 'POST', '/v1/media', 200);
        $this->assertArrayNotHasKey('data', $upload->json());

        $list = $this->api()->getJson('/api/v1/media');
        $this->assertDocumented($list, 'GET', '/v1/media', 200);
        $this->assertArrayNotHasKey('meta', $list->json());
    }

    // ------------------------------------------------------------ preview

    public function test_a_preview_returns_the_documented_html()
    {
        $template = $this->template();

        $response = $this->api()->get('/api/v1/templates/'.$template->uuid.'/preview');

        $response->assertOk();
        $this->assertStringStartsWith('text/html', $response->headers->get('Content-Type'));
        $this->assertArrayHasKey('text/html', $this->operation('GET', '/v1/templates/{templateUuid}/preview')['responses']['200']['content']);
    }
}
