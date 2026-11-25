<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;

/**
 * Runs a `snapsbrew:*` command with `--json` and checks the envelope of the
 * agent-first contract: stdout is one JSON object with `ok`, `command`,
 * `schema`, `data` and `errors`, and nothing else.
 */
trait RunsJsonCommands
{
    /**
     * @param  array<string, mixed>  $parameters
     * @return array{0: int, 1: array<string, mixed>, 2: string}
     */
    protected function runJson(string $command, array $parameters = []): array
    {
        $exitCode = Artisan::call($command, $parameters + ['--json' => true, '--no-interaction' => true]);
        $raw = Artisan::output();

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "stdout of {$command} is not one JSON object:\n{$raw}");
        $this->assertSame(['ok', 'command', 'schema', 'data', 'errors'], array_keys($decoded));
        $this->assertSame($command, $decoded['command']);
        $this->assertSame(1, $decoded['schema']);
        $this->assertSame($exitCode === 0, $decoded['ok']);
        $this->assertIsArray($decoded['errors']);
        $this->assertTrue(array_is_list($decoded['errors']));
        $this->assertStringStartsWith('{', ltrim($raw));
        // The data is always an object, also when it is empty.
        $this->assertStringContainsString('"data": {', $raw);

        foreach ($decoded['errors'] as $error) {
            $this->assertSame(['code', 'message', 'fix'], array_keys($error));
        }

        return [$exitCode, $decoded, $raw];
    }
}
