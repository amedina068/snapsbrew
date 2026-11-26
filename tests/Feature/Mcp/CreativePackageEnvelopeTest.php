<?php

namespace Tests\Feature\Mcp;

use App\Http\Middleware\EnsureMcpAccess;
use App\Models\Company;
use App\Models\User;
use App\Support\CreativePackageContract;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Support\WithPassportKeys;
use Tests\TestCase;

class CreativePackageEnvelopeTest extends TestCase
{
    use RefreshDatabase;
    use WithPassportKeys;

    private const MAX_MCP_REQUEST_BYTES = 1024 * 1024;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWithPassportKeys();
    }

    public function test_target_body_accepts_the_exact_limit_and_rejects_one_byte_more(): void
    {
        $token = $this->token();

        // The body decodes, so the tool answers, and its missing fields are a
        // tool error rather than a transport error.
        $accepted = $this->postRaw($this->sizedTargetBody(CreativePackageContract::MAX_REQUEST_BYTES), $token);
        $accepted->assertOk()->assertJsonPath('result.isError', true);
        $this->assertSame('creative_package_invalid_request', json_decode($accepted->json('result.content.0.text'), true)['error']['code']);

        $rejected = $this->postRaw($this->sizedTargetBody(CreativePackageContract::MAX_REQUEST_BYTES + 1), $token);
        $rejected->assertOk()
            ->assertJsonPath('id', 'size-boundary')
            ->assertJsonPath('error.code', -32600)
            ->assertJsonPath('error.message', 'Invalid creative package request.');
    }

    public function test_metadata_must_be_a_json_object_even_when_empty(): void
    {
        $token = $this->token();
        $payload = $this->targetPayload();
        $payload['params']['arguments']['items'][0]['metadata'] = (object) [];

        $this->postRaw(json_encode($payload, JSON_THROW_ON_ERROR), $token)
            ->assertOk()
            ->assertJsonPath('result.isError', true);

        $payload['params']['arguments']['items'][0]['metadata'] = [];
        $this->postRaw(json_encode($payload, JSON_THROW_ON_ERROR), $token)
            ->assertOk()
            ->assertJsonPath('id', 41)
            ->assertJsonPath('error.code', -32600)
            ->assertJsonPath('error.message', 'Invalid creative package request.');
    }

    public function test_metadata_shape_is_checked_on_every_item(): void
    {
        $payload = $this->targetPayload();
        $payload['params']['arguments']['items'][] = ['metadata' => []];

        $this->postRaw(json_encode($payload, JSON_THROW_ON_ERROR), $this->token())
            ->assertOk()
            ->assertJsonPath('id', 41)
            ->assertJsonPath('error.code', -32600);
    }

    public function test_discernible_target_rejects_invalid_protocol_version_and_id_generically(): void
    {
        $token = $this->token();
        $payload = $this->targetPayload();
        $payload['jsonrpc'] = '1.0';

        $this->postRaw(json_encode($payload, JSON_THROW_ON_ERROR), $token)
            ->assertExactJson($this->invalidResponse(41));

        $payload = $this->targetPayload();
        $payload['id'] = true;
        $this->postRaw(json_encode($payload, JSON_THROW_ON_ERROR), $token)
            ->assertExactJson($this->invalidResponse());
    }

    public function test_null_id_reaches_the_native_transport(): void
    {
        $payload = $this->targetPayload();
        $payload['id'] = null;

        // The envelope check lets a null id through. The native transport
        // rejects it, because an MCP request id must be a string or a number.
        $this->postRaw(json_encode($payload, JSON_THROW_ON_ERROR), $this->token())
            ->assertStatus(400)
            ->assertJsonPath('error.code', -32600)
            ->assertJsonPath('error.message', 'Invalid Request: The [id] member must be a string, number.');
    }

    public function test_rejection_does_not_leak_internal_validation_details(): void
    {
        $payload = $this->targetPayload();
        $payload['params']['arguments']['items'][0]['metadata'] = 'not-an-object';

        $response = $this->postRaw(json_encode($payload, JSON_THROW_ON_ERROR), $this->token());

        $response->assertOk()->assertExactJson([
            'jsonrpc' => '2.0',
            'id' => 41,
            'error' => ['code' => -32600, 'message' => 'Invalid creative package request.'],
        ]);
    }

    public function test_non_target_calls_keep_the_existing_transport_behavior(): void
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => 42,
            'method' => 'tools/list',
            'params' => ['padding' => ''],
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $payload['params']['padding'] = str_repeat('x', CreativePackageContract::MAX_REQUEST_BYTES + 1 - strlen($body));

        $this->postRaw(json_encode($payload, JSON_THROW_ON_ERROR), $this->token())
            ->assertOk()
            ->assertJsonPath('id', 42)
            ->assertJsonStructure(['result' => ['tools']]);
    }

    public function test_outer_mcp_body_limit_accepts_exactly_one_mib_and_rejects_one_byte_more(): void
    {
        $token = $this->token();

        $this->postRaw($this->sizedListBody(self::MAX_MCP_REQUEST_BYTES), $token)
            ->assertOk()
            ->assertJsonStructure(['result' => ['tools']]);

        $this->postRaw($this->sizedListBody(self::MAX_MCP_REQUEST_BYTES + 1), $token)
            ->assertOk()
            ->assertExactJson($this->invalidResponseWithNullId());
    }

    public function test_outer_limit_uses_actual_bytes_when_content_length_is_spoofed(): void
    {
        $body = $this->sizedListBody(self::MAX_MCP_REQUEST_BYTES + 1);

        $this->postRaw($body, $this->token(), ['CONTENT_LENGTH' => '1'])
            ->assertOk()
            ->assertExactJson($this->invalidResponseWithNullId());
    }

    public function test_outer_limit_rejects_an_oversized_declared_content_length_before_decode(): void
    {
        $body = json_encode($this->targetPayload(), JSON_THROW_ON_ERROR);

        $this->postRaw($body, $this->token(), ['CONTENT_LENGTH' => (string) (self::MAX_MCP_REQUEST_BYTES + 1)])
            ->assertOk()
            ->assertExactJson($this->invalidResponseWithNullId());
    }

    public function test_unsupported_json_rpc_batches_still_reach_the_native_transport(): void
    {
        $body = json_encode([$this->targetPayload()], JSON_THROW_ON_ERROR);

        $this->postRaw($body, $this->token())
            ->assertStatus(400)
            ->assertJsonPath('error.code', -32600)
            ->assertJsonPath('error.message', 'Invalid Request: The request must be a JSON object.');
    }

    public function test_authentication_runs_before_target_validation(): void
    {
        $payload = $this->targetPayload();
        $payload['params']['arguments']['items'][0]['metadata'] = [];

        $this->postRaw(json_encode($payload, JSON_THROW_ON_ERROR))
            ->assertStatus(401);
    }

    public function test_oversized_mcp_bodies_consume_the_mcp_rate_limit(): void
    {
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(1)->by($request->user()->id));
        $body = $this->sizedListBody(self::MAX_MCP_REQUEST_BYTES + 1);
        $token = $this->token();

        $this->postRaw($body, $token)->assertOk()->assertHeader('X-RateLimit-Remaining', '0');
        $this->postRaw($body, $token)->assertStatus(429);
    }

    private function token(): string
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        return $user->createToken('test', [EnsureMcpAccess::SANCTUM_ABILITY])->plainTextToken;
    }

    private function postRaw(string $body, ?string $token = null, array $server = []): TestResponse
    {
        $headers = [...$server, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($token !== null) {
            $headers['HTTP_AUTHORIZATION'] = "Bearer {$token}";
        }

        return $this->call('POST', '/mcp', [], [], [], $headers, $body);
    }

    /** @return array<string, mixed> */
    private function targetPayload(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 41,
            'method' => 'tools/call',
            'params' => [
                'name' => 'export-creative-package',
                'arguments' => ['items' => [['metadata' => (object) []]]],
            ],
        ];
    }

    private function sizedTargetBody(int $bytes): string
    {
        $payload = $this->targetPayload();
        $payload['id'] = 'size-boundary';
        $payload['params']['padding'] = '';
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $payload['params']['padding'] = str_repeat('x', $bytes - strlen($body));
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertSame($bytes, strlen($body));

        return $body;
    }

    private function sizedListBody(int $bytes): string
    {
        $payload = ['jsonrpc' => '2.0', 'id' => 42, 'method' => 'tools/list', 'params' => ['padding' => '']];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $payload['params']['padding'] = str_repeat('x', $bytes - strlen($body));
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertSame($bytes, strlen($body));

        return $body;
    }

    /** @return array<string, mixed> */
    private function invalidResponse(int|string|null $id = null): array
    {
        return [
            'jsonrpc' => '2.0',
            ...$id === null ? [] : ['id' => $id],
            'error' => ['code' => -32600, 'message' => 'Invalid creative package request.'],
        ];
    }

    /** @return array<string, mixed> */
    private function invalidResponseWithNullId(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32600, 'message' => 'Invalid creative package request.'],
        ];
    }
}
