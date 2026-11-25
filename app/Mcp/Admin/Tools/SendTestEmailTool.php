<?php

namespace App\Mcp\Admin\Tools;

use App\Mcp\Admin\AdminTool;
use App\Mcp\Admin\RedactsSecrets;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Mail;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Name('send-test-email')]
#[Title('Send a test email')]
#[Description('Sends one plain test email through the configured mailer, to the given address or to the first owner. It returns the mailer and the error when the send fails.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(true)]
class SendTestEmailTool extends AdminTool
{
    use RedactsSecrets;

    protected function run(Request $request): Response
    {
        $input = $request->validate(['to' => ['nullable', 'email:strict', 'max:255']]);

        $to = $input['to'] ?? $this->userOrOwner(null)?->email;

        if (! $to) {
            return self::userNotFound(null);
        }

        $mailer = (string) config('mail.default');

        try {
            Mail::raw(
                'This is a test email from the Snapsbrew instance at '.config('app.url').'. The mail settings work.',
                fn ($message) => $message->to($to)->subject('Snapsbrew test email'),
            );
        } catch (Throwable $exception) {
            return self::error(
                'mail_failed',
                'The mailer could not send the email: '.$this->redact(class_basename($exception).': '.(strtok($exception->getMessage(), "\n") ?: '')),
                'Make sure that MAIL_MAILER and the MAIL_* variables are correct. Then call this tool again.',
                ['mailer' => $mailer, 'to' => $to],
            );
        }

        return self::ok([
            'sent' => true,
            'to' => $to,
            'mailer' => $mailer,
            'note' => in_array($mailer, ['log', 'array'], true) ? "The {$mailer} mailer keeps the email on this server. It does not reach the inbox." : null,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'to' => $schema->string()->description('The address. The default is the email of the first owner.'),
        ];
    }
}
