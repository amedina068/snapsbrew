<?php

namespace App\Actions\Fortify;

use App\Actions\Templates\DuplicateTemplate;
use App\Jobs\GenerateTemplatePreview;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\Template;
use App\Models\User;
use App\Services\TemplateVersioning;
use App\Support\Registration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Throwable;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(
        private DuplicateTemplate $duplicateTemplate,
        private TemplateVersioning $versioning,
    ) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string|null>  $input
     */
    public function create(array $input): User
    {
        $invitation = null;

        if (! empty($input['invitation_token'])) {
            $invitation = CompanyInvitation::query()
                ->pending()
                ->where('token', $input['invitation_token'])
                ->first();

            if (! $invitation) {
                throw ValidationException::withMessages([
                    'email' => 'This invitation is no longer valid.',
                ]);
            }
        }

        // An invite-only instance accepts invitations only.
        if (! $invitation && ! Registration::isOpen()) {
            abort(404);
        }

        return $this->createAccount($input, $invitation);
    }

    /**
     * Validate the input and create the user. A user without an invitation
     * gets a new company. The `snapsbrew:install` command calls this method
     * directly, so it does not read the registration mode.
     *
     * @param  array<string, string|null>  $input
     */
    public function createAccount(array $input, ?CompanyInvitation $invitation = null): User
    {
        // The users table stores the email in lowercase. Lowercase it before
        // the unique rule, which compares with case on SQLite.
        if (is_string($input['email'] ?? null)) {
            $input['email'] = Str::lower(trim($input['email']));
        }

        $emailRules = [
            'required',
            'string',
            'email:strict',
            'max:255',
            Rule::unique('users'),
        ];

        if ($invitation) {
            $emailRules[] = Rule::in([$invitation->email]);
        }

        Validator::make($input, [
            'email' => $emailRules,
            'password' => $this->passwordRules(),
        ])->validate();

        $user = DB::transaction(function () use ($input, $invitation) {
            if ($invitation) {
                $company = $invitation->company;

                $user = User::create([
                    'name' => $input['name'] ?? null,
                    'email' => $input['email'],
                    'password' => Hash::make($input['password']),
                    'company_id' => $company->id,
                ]);

                $invitation->markAccepted($user);

                return $user;
            }

            $companyName = ! empty($input['name'])
                ? "{$input['name']}'s Company"
                : 'New Company';

            $company = Company::create([
                'name' => $companyName,
            ]);

            $user = User::create([
                'name' => $input['name'] ?? null,
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
                'company_id' => $company->id,
            ]);

            $user->createSignedUrlToken();

            return $user;
        });

        $this->copyWelcomeTemplate($user);

        return $user;
    }

    /**
     * Copies the first shared template into the new company. The copy gets a
     * published version and a queued preview image, as a starter template
     * does. Without them, the template list shows the copy with no preview.
     *
     * A failed publish or a failed preview does not stop the registration.
     * The template stays usable, and the error goes to the log.
     */
    private function copyWelcomeTemplate(User $user): void
    {
        $welcomeTemplate = Template::query()->whereNull('user_id')->first();

        if (! $welcomeTemplate) {
            return;
        }

        $copy = $this->duplicateTemplate->execute($welcomeTemplate, $user);

        try {
            $this->versioning->publish($copy);
        } catch (Throwable $exception) {
            report($exception);
        }

        if ($copy->hasMedia('preview')) {
            return;
        }

        try {
            GenerateTemplatePreview::dispatch($copy);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
