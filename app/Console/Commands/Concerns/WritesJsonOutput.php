<?php

namespace App\Console\Commands\Concerns;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The agent-first output contract of the `snapsbrew:*` commands.
 *
 * With `--json`, stdout holds one JSON object and nothing else:
 *
 *   {"ok":true,"command":"snapsbrew:status","schema":1,"data":{},"errors":[]}
 *
 * Each error is `{code, message, fix}`. Human messages go to stderr. The exit
 * codes are the same in every command: 0 for success or no change, 1 when a
 * check or an action failed, 2 for bad input, and 3 for a conflict.
 *
 * Other self-hosted apps share this shape with their own command prefix.
 * Increase `schema` when a field changes meaning.
 */
trait WritesJsonOutput
{
    public const EXIT_OK = 0;

    public const EXIT_FAILED = 1;

    public const EXIT_BAD_INPUT = 2;

    public const EXIT_CONFLICT = 3;

    public const JSON_SCHEMA = 1;

    protected function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    /**
     * Writes the envelope in JSON mode and returns the exit code.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array{code: string, message: string, fix: string}>  $errors
     */
    protected function respond(int $exitCode, array $data = [], array $errors = []): int
    {
        if ($this->wantsJson()) {
            $this->output->writeln(json_encode([
                'ok' => $exitCode === self::EXIT_OK,
                'command' => $this->getName(),
                'schema' => self::JSON_SCHEMA,
                'data' => (object) $data,
                'errors' => $errors,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);
        }

        return $exitCode;
    }

    /**
     * Shows the error (stderr in JSON mode) and returns the envelope with this
     * one error.
     *
     * @param  array<string, mixed>  $data
     */
    protected function failWith(int $exitCode, string $code, string $message, string $fix, array $data = []): int
    {
        if ($this->wantsJson()) {
            $this->stderr()->writeln("ERROR {$code}: {$message}");
        } else {
            $this->components->error($message);
            if ($fix !== '') {
                $this->line("  {$fix}");
            }
        }

        return $this->respond($exitCode, $data, [self::errorItem($code, $message, $fix)]);
    }

    /**
     * @return array{code: string, message: string, fix: string}
     */
    protected static function errorItem(string $code, string $message, string $fix): array
    {
        return ['code' => $code, 'message' => $message, 'fix' => $fix];
    }

    /**
     * A message for the human. In JSON mode it goes to stderr, so stdout stays
     * one JSON object.
     */
    protected function note(string $message): void
    {
        if ($this->wantsJson()) {
            $this->stderr()->writeln($message);

            return;
        }

        $this->components->info($message);
    }

    /**
     * The stderr stream. A buffered output (Artisan::call in a test) has no
     * stderr, so the message is dropped and the buffer holds only JSON.
     */
    protected function stderr(): OutputInterface
    {
        $output = $this->output->getOutput();

        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : new NullOutput;
    }
}
