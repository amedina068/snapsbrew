<?php

namespace App\Mcp\Admin\Tools;

use App\Mail\CompanyInvitationMail;
use App\Mcp\Admin\AdminTool;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
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

#[Name('invite-user')]
#[Title('Invite a user')]
#[Description('Invites a person by email to a company. The default company is the company of the first owner. It returns the invitation link, which works for 7 days, and sends the invitation email when send_email is true (the default). A person with the link can sign up, also on an invite-only instance.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(true)]
class InviteUserTool extends AdminTool
{
    protected function run(Request $request): Response
    {
        $input = $request->validate([
            'email' => ['required', 'email:strict', 'max:255'],
            'company_id' => ['nullable', 'integer'],
            'send_email' => ['nullable', 'boolean'],
        ]);

        $owner = User::query()->where('is_owner', true)->orderBy('id')->first();
        $inviter = $this->actor($request) ?? $owner;

        if (! $inviter) {
            return self::userNotFound(null);
        }

        $company = Company::query()->find((int) ($input['company_id'] ?? $owner?->company_id));

        if (! $company) {
            return self::error('company_not_found', 'The company does not exist.', 'Call `list-companies` to read the company ids. Then call this tool again.');
        }

        $email = strtolower($input['email']);

        if (User::query()->where('company_id', $company->id)->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return self::error('already_member', "{$email} is already a member of {$company->name}.", 'Nothing to do. Call `list-users` to read the members.');
        }

        $invitation = CompanyInvitation::query()
            ->where('company_id', $company->id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->pending()
            ->first();

        $status = $invitation ? 'exists' : 'created';

        $invitation ??= CompanyInvitation::query()->create([
            'company_id' => $company->id,
            'email' => $email,
            'token' => Str::random(40),
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
        ]);

        $mail = ['sent' => false, 'error' => null];

        if ($input['send_email'] ?? true) {
            try {
                Mail::to($email)->send(new CompanyInvitationMail($invitation, $inviter));
                $mail['sent'] = true;
            } catch (Throwable $exception) {
                report($exception);
                $mail['error'] = 'The email was not sent ('.class_basename($exception).'). Give the invite_url to the person.';
            }
        }

        return self::ok([
            'status' => $status,
            'email' => $email,
            'company' => ['id' => $company->id, 'name' => $company->name],
            'invite_url' => route('register', ['invitation' => $invitation->token]),
            'expires_at' => $invitation->expires_at?->toIso8601String(),
            'email_sent' => $mail['sent'],
            'email_error' => $mail['error'],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'email' => $schema->string()->description('The email of the person to invite.')->required(),
            'company_id' => $schema->integer()->description('The company. The default is the company of the first owner.'),
            'send_email' => $schema->boolean()->description('Send the invitation email. The default is true.'),
        ];
    }
}
