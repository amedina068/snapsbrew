<?php

namespace Tests\Feature\Api;

use App\Exceptions\EditorOperationRejected;
use App\Http\Middleware\EnsureMcpAccess;
use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use App\Services\SnapBatchService;
use App\Services\SnapService;
use App\Services\TemplateDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * An unexpected exception must not reach the caller with its message. The
 * message can hold SQL, file paths, host names or the output of the renderer.
 * Each test forces an exception with a secret message at one endpoint, and
 * asserts that the response does not hold the secret.
 */
class ExceptionMessageLeakTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'SQLSTATE[HY000] /var/www/secret-host.internal:3306 password=hunter2';

    protected function setUp(): void
    {
        parent::setUp();

        // Production runs with debug off. With debug on, the framework shows
        // every message on purpose.
        config(['app.debug' => false]);
    }

    private function owner(array $ability = ['api_requests']): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'layers' => json_encode([[
                'key' => 'headline',
                'type' => 'text',
                'properties' => [['name' => 'textContent', 'value' => 'X']],
            ]]),
            'editor_document' => ['version' => 2, 'layers' => [
                ['key' => 'hero', 'type' => 'image', 'properties' => []],
            ]],
            'editor_revision' => 0,
            'editor_schema_version' => 2,
        ]);

        return [$user, $template, $user->createToken('test', $ability)->plainTextToken];
    }

    private function forceException(string $service, string $method, \Throwable $exception): void
    {
        $mock = Mockery::mock($service)->makePartial();
        $mock->shouldReceive($method)->once()->andThrow($exception);
        $this->app->instance($service, $mock);
    }

    private function assertNoLeak(TestResponse $response): void
    {
        $body = $response->getContent();

        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('secret-host', $body);
        $this->assertStringNotContainsString('hunter2', $body);
    }

    private function callTool(string $token, string $name, array $arguments): TestResponse
    {
        return $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    public function test_post_snaps_does_not_leak_the_message_of_an_unexpected_exception()
    {
        [, $template, $token] = $this->owner();
        $this->forceException(SnapService::class, 'create', new RuntimeException(self::SECRET));
        Log::spy();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/snaps', ['template_id' => $template->uuid]);

        $response->assertStatus(500)
            ->assertJsonPath('code', 'internal_error')
            ->assertJsonStructure(['message', 'code']);
        $this->assertNoLeak($response);
        $this->assertNotNull($response->headers->get('X-Request-Uuid'));

        // The log keeps the full exception, with the context to find it.
        Log::shouldHaveReceived('error')->withArgs(function ($message, $context = []) use ($template) {
            return ($context['exception'] ?? null) instanceof RuntimeException
                && ($context['template_id'] ?? null) === $template->uuid
                && isset($context['user_id'], $context['api_log_uuid']);
        })->once();
    }

    public function test_post_snaps_keeps_the_validation_contract()
    {
        [, , $token] = $this->owner();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/snaps', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('template_id');
    }

    public function test_post_snap_batches_does_not_leak_the_message_of_an_unexpected_exception()
    {
        [, $template, $token] = $this->owner();
        $this->forceException(SnapBatchService::class, 'create', new RuntimeException(self::SECRET));
        Log::spy();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/snaps/batches', [
                'template_id' => $template->uuid,
                'items' => [['identifier' => 'row-1', 'variants' => [['layer' => 'headline', 'text' => 'Hi']]]],
            ]);

        // The same generic body as POST /v1/snaps.
        $response->assertStatus(500)
            ->assertJsonPath('code', 'internal_error')
            ->assertJsonStructure(['message', 'code']);
        $this->assertSame(['message', 'code'], array_keys($response->json()));
        $this->assertNoLeak($response);
        $this->assertNotNull($response->headers->get('X-Request-Uuid'));

        // The log keeps the full exception, with the context to find it.
        Log::shouldHaveReceived('error')->withArgs(function ($message, $context = []) use ($template) {
            return ($context['exception'] ?? null) instanceof RuntimeException
                && ($context['template_id'] ?? null) === $template->uuid
                && isset($context['user_id'], $context['api_log_uuid']);
        })->once();
    }

    public function test_patch_document_does_not_leak_the_message_of_an_unexpected_invalid_argument()
    {
        // The route turns the codes of the operation applier into a 422. An
        // InvalidArgumentException from anywhere else is not one of those
        // codes, and its message must not become the `code` of the response.
        [, $template, $token] = $this->owner();
        $this->forceException(TemplateDocumentService::class, 'replace', new InvalidArgumentException(self::SECRET));

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/templates/{$template->uuid}/document", [
                'revision' => 0,
                'operations' => [
                    ['operation' => 'set_layer_property', 'layer_key' => 'hero', 'property' => 'imageFit', 'value' => 'cover'],
                ],
            ]);

        $response->assertStatus(500);
        $this->assertNoLeak($response);
    }

    public function test_patch_document_keeps_the_operation_code_contract()
    {
        [, $template, $token] = $this->owner();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/templates/{$template->uuid}/document", [
                'revision' => 0,
                'operations' => [
                    ['operation' => 'set_layer_property', 'layer_key' => 'missing', 'property' => 'imageFit', 'value' => 'cover'],
                ],
            ])
            ->assertStatus(422)
            ->assertExactJson([
                'code' => 'editor_operation_layer_not_found',
                'message' => EditorOperationRejected::messageFor('editor_operation_layer_not_found'),
            ]);
    }

    public function test_the_apply_editor_operations_tool_does_not_leak_the_message_of_an_unexpected_invalid_argument()
    {
        [, $template, $token] = $this->owner([EnsureMcpAccess::SANCTUM_ABILITY]);
        $this->forceException(TemplateDocumentService::class, 'replace', new InvalidArgumentException(self::SECRET));

        $response = $this->callTool($token, 'apply-editor-operations', [
            'template_id' => $template->uuid,
            'revision' => 0,
            'operations' => [
                ['operation' => 'set_layer_property', 'layer_key' => 'hero', 'property' => 'imageFit', 'value' => 'cover'],
            ],
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('result.isError'));
        $this->assertNoLeak($response);
    }

    public function test_the_create_snap_tool_does_not_leak_the_message_of_an_unexpected_exception()
    {
        [, $template, $token] = $this->owner([EnsureMcpAccess::SANCTUM_ABILITY]);
        $this->forceException(SnapService::class, 'create', new RuntimeException(self::SECRET));

        $response = $this->callTool($token, 'create-snap', ['template_id' => $template->uuid]);

        $response->assertOk();
        $this->assertTrue($response->json('result.isError'));
        $this->assertNoLeak($response);
    }
}
