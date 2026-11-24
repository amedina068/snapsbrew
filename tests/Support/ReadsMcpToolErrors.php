<?php

namespace Tests\Support;

use Laravel\Mcp\Server\Testing\TestResponse;
use ReflectionClass;

/**
 * Reads the JSON error body out of an in-process MCP tool response.
 *
 * `SnapsbrewServer::actingAs()->tool()` answers with the package's
 * `TestResponse`, which holds the raw response in a protected property with no
 * accessor. Two test classes needed the same reflection, and a second copy is
 * the point at which one of them stops matching the transport.
 *
 * @return array<string, mixed> The decoded `error` object (code, message, fix, ...).
 */
trait ReadsMcpToolErrors
{
    private function errorFrom(TestResponse $response, ?string $tool = null): array
    {
        $label = $tool ?? 'The tool';

        $property = (new ReflectionClass(TestResponse::class))->getProperty('response');
        $property->setAccessible(true);

        $result = $property->getValue($response)->toArray();

        $this->assertTrue(data_get($result, 'result.isError', false), "{$label} did not answer with an error.");

        $text = collect($result['result']['content'] ?? [])
            ->pluck('text')
            ->first(fn ($text) => is_string($text) && str_contains($text, '"error"'));

        $this->assertIsString($text, "{$label} returned no JSON error body.");

        return json_decode($text, true)['error'];
    }
}
