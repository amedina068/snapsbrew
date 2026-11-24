<?php

namespace Tests\Feature\Mcp;

use App\Http\Middleware\EnsureMcpAccess;
use App\Models\CreativePackage;
use App\Models\User;
use App\Support\CreativePackageContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsCreativePackages;
use Tests\TestCase;

/**
 * One invalid request has two shapes, and this file pins the rule that
 * chooses between them. `ValidateCreativePackageEnvelope` and
 * `ToolError::creativePackage` both carry the rule in prose.
 *
 * A body the transport cannot accept answers as JSON-RPC `-32600` with
 * `Invalid creative package request.` and never reaches a tool. A body that
 * decodes reaches `export-creative-package`, and every failure of its
 * content answers as the tool error `creative_package_invalid_request` with
 * the failing field paths in `details.fields`.
 */
class CreativePackageRequestShapesTest extends TestCase
{
    use BuildsCreativePackages;
    use RefreshDatabase;

    public function test_an_oversized_body_answers_at_the_transport(): void
    {
        $actor = $this->member();
        $payload = $this->envelope($this->request('big', [$this->source($actor, 1)]));
        $payload['params']['padding'] = '';
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $payload['params']['padding'] = str_repeat('x', 257 * 1024 - strlen($body));
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertSame(257 * 1024, strlen($body));

        $this->send($body, $actor)->assertOk()->assertExactJson($this->transportError());

        $this->send(json_encode($payload, JSON_THROW_ON_ERROR), $actor, ['CONTENT_LENGTH' => (string) (1024 * 1024 + 1)])
            ->assertOk()->assertJsonPath('error.code', -32600);
        $this->assertSame(0, CreativePackage::count());
    }

    public function test_an_envelope_the_transport_cannot_decode_answers_at_the_transport(): void
    {
        $actor = $this->member();
        $request = $this->request('shape', [$this->source($actor, 1)]);

        $version = $this->envelope($request);
        $version['jsonrpc'] = '1.0';
        $this->send(json_encode($version, JSON_THROW_ON_ERROR), $actor)->assertExactJson($this->transportError());

        $metadata = $this->envelope($request);
        $metadata['params']['arguments']['items'][0]['metadata'] = ['a list'];
        $this->send(json_encode($metadata, JSON_THROW_ON_ERROR), $actor)->assertExactJson($this->transportError());
        $this->assertSame(0, CreativePackage::count());
    }

    public function test_a_body_that_does_not_parse_never_reaches_the_package_check(): void
    {
        $actor = $this->member();
        $body = json_encode($this->envelope($this->request('cut', [$this->source($actor, 1)])), JSON_THROW_ON_ERROR);

        $this->send(substr($body, 0, -10), $actor)->assertOk()->assertJsonPath('error.code', -32700);
        $this->assertSame(0, CreativePackage::count());
    }

    public function test_every_failure_of_a_decoded_request_answers_as_the_tool_error_with_its_fields(): void
    {
        $actor = $this->member();
        $valid = $this->request('decoded', [$this->source($actor, 1)]);

        $missing = $valid;
        unset($missing['items'][0]['alt_text']);
        $badUuid = $valid;
        $badUuid['items'][0]['template_id'] = 'not-a-uuid';
        $tooMany = $valid;
        $tooMany['items'] = array_map(fn (int $index) => ['client_creative_id' => "c{$index}"] + $valid['items'][0], range(0, CreativePackageContract::MAX_ITEMS));
        $tooDeep = $valid;
        $tooDeep['items'][0]['metadata'] = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => 1]]]]]]];

        $cases = [
            'a missing field' => [$missing, ['items.0.alt_text']],
            'a bad uuid' => [$badUuid, ['items.0.template_id']],
            'too many items' => [$tooMany, ['items']],
            'metadata too deep' => [$tooDeep, ['items.0.metadata']],
        ];

        foreach ($cases as $case => [$arguments, $fields]) {
            $response = $this->send(json_encode($this->envelope($arguments), JSON_THROW_ON_ERROR), $actor);
            $response->assertOk()->assertJsonPath('id', 7)->assertJsonPath('result.isError', true);
            $this->assertNull($response->json('error'), $case);
            $error = json_decode($response->json('result.content.0.text'), true)['error'];
            $this->assertSame(['creative_package_invalid_request', false, $fields, ['fields']], [$error['code'], $error['retryable'], $error['details']['fields'], array_keys($error['details'])], $case);
        }
        $this->assertSame(0, CreativePackage::count());
    }

    /** @return array<string, mixed> */
    private function envelope(array $arguments): array
    {
        return ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'export-creative-package', 'arguments' => $arguments]];
    }

    /** @return array<string, mixed> */
    private function transportError(): array
    {
        return ['jsonrpc' => '2.0', 'id' => 7, 'error' => ['code' => -32600, 'message' => 'Invalid creative package request.']];
    }

    private function send(string $body, User $actor, array $server = []): TestResponse
    {
        $token = $actor->createToken('test', [EnsureMcpAccess::SANCTUM_ABILITY])->plainTextToken;
        $headers = [...$server, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$token}"];

        return $this->call('POST', '/mcp', [], [], [], $headers, $body);
    }
}
