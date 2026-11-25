<?php

namespace App\Mcp\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * The base of every tool of the instance-admin MCP server.
 *
 * `handle()` writes one audit entry for each call: an `activity_log` row in the
 * `instance-admin` log and one `instance_admin.tool_call` log line. The entry
 * holds the tool, the transport, the caller and the arguments, with secret
 * values removed. Then it returns the result of `run()`.
 *
 * A destructive tool calls `needsConfirmation()` first. Without `confirm: true`
 * it returns a preview and changes nothing.
 */
abstract class AdminTool extends Tool
{
    /** Argument keys whose values the audit entry never stores. */
    private const SECRET_KEYS = '/(password|secret|value|plain)/i';

    private bool $isPreview = false;

    abstract protected function run(Request $request): Response;

    public function handle(Request $request): Response
    {
        try {
            $response = $this->run($request);
            $outcome = $response->isError() ? 'error' : ($this->isPreview ? 'preview' : 'ok');
        } catch (ValidationException $exception) {
            $response = self::error('invalid_arguments', collect($exception->errors())->flatten()->implode(' '), 'Correct the arguments and call the tool again.');
            $outcome = 'invalid';
        } catch (Throwable $exception) {
            $this->audit($request, 'exception');

            throw $exception;
        }

        $this->audit($request, $outcome);

        return $response;
    }

    /**
     * Returns the preview response when the call has no `confirm: true`, and
     * null when the tool can act.
     *
     * @param  array<string, mixed>  $preview
     */
    protected function needsConfirmation(Request $request, array $preview): ?Response
    {
        if ($request->get('confirm') === true) {
            return null;
        }

        $this->isPreview = true;

        return self::ok([
            'preview' => $preview,
            'confirm_required' => true,
            'next' => 'Nothing changed. Show this preview to the human. If the human agrees, call `'.$this->name().'` again with the same arguments and `confirm: true`.',
        ]);
    }

    /**
     * The caller over HTTP. Over stdio no user exists, and the caller is the
     * person with shell access to the server.
     */
    protected function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * The user with this email, or the first owner when the email is empty.
     */
    protected function userOrOwner(?string $email): ?User
    {
        return $email
            ? User::query()->where('email', $email)->first()
            : User::query()->where('is_owner', true)->orderBy('id')->first();
    }

    protected static function userNotFound(?string $email): Response
    {
        return $email
            ? self::error('user_not_found', "No user has the email `{$email}`.", 'Call `list-users` to read the emails. Then call this tool again.', ['email' => $email])
            : self::error('no_owner', 'This instance has no owner.', 'Run `php artisan snapsbrew:install` on the server. An MCP tool cannot create the owner.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function ok(array $data): Response
    {
        return Response::text((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The same error shape as `App\Mcp\ToolError`.
     *
     * @param  array<string, mixed>  $details
     */
    public static function error(string $code, string $message, string $fix, array $details = []): Response
    {
        $error = ['code' => $code, 'message' => $message, 'fix' => $fix, 'retryable' => false];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return Response::error((string) json_encode(['error' => $error], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function audit(Request $request, string $outcome): void
    {
        $actor = $this->actor($request);
        $properties = [
            'tool' => $this->name(),
            'transport' => $actor ? 'http' : 'stdio',
            'outcome' => $outcome,
            'arguments' => $this->redact($request->all()) ?: new \stdClass,
        ];

        try {
            $activity = activity('instance-admin')->event($this->name())->withProperties($properties);

            if ($actor) {
                $activity->causedBy($actor);
            }

            $activity->log("instance-admin {$this->name()}");
        } catch (Throwable $exception) {
            // The tool result reaches the caller even when the audit row
            // cannot be written. The log line below still records the call.
            report($exception);
        }

        Log::info('instance_admin.tool_call', $properties + ['user_id' => $actor?->id]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function redact(array $arguments): array
    {
        foreach ($arguments as $key => $value) {
            if (preg_match(self::SECRET_KEYS, (string) $key)) {
                $arguments[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $arguments[$key] = $this->redact($value);
            }
        }

        return $arguments;
    }
}
