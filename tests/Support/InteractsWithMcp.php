<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

/**
 * One way to call an MCP tool over the real `/mcp` route.
 *
 * Three test classes built the same JSON-RPC envelope by hand and decoded the
 * same `result.content.0.text` string. A fourth copy is the point at which one
 * of them stops matching the transport.
 *
 * The helper also closes the gap every copy shared: a JSON-RPC error rides an
 * HTTP 200, so `assertOk()` passes on it and the caller then reads a null
 * `result`. The envelope is checked here, before the result reaches the test.
 */
trait InteractsWithMcp
{
    /**
     * Call one tool and return the raw response.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function callMcpTool(string $token, string $name, array $arguments = []): TestResponse
    {
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);

        $response->assertOk();

        $error = $response->json('error');

        $this->assertNull(
            $error,
            "The call to [{$name}] answered with a JSON-RPC error: ".json_encode($error)
        );

        return $response;
    }

    /**
     * The tool result, decoded.
     *
     * A tool answers with one JSON object inside `result.content.0.text`. A
     * failure the tool reports itself rides there too, under an `error` key,
     * and it is not a JSON-RPC error.
     *
     * @return array<string, mixed>
     */
    protected function mcpToolPayload(TestResponse $response): array
    {
        return json_decode($response->json('result.content.0.text'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The error object a tool reports inside its own result.
     *
     * @return array<string, mixed>
     */
    protected function mcpToolError(TestResponse $response): array
    {
        return $this->mcpToolPayload($response)['error'];
    }
}
