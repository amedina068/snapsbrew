<?php

namespace App\Actions\Fortify;

use App\Actions\Templates\DuplicateTemplate;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\Template;
use App\Models\User;
use App\Support\Registration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(
        private DuplicateTemplate $duplicateTemplate
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

        if ($welcomeTemplate = Template::query()->whereNull('user_id')->first()) {
            $this->duplicateTemplate->execute($welcomeTemplate, $user);
        }

        return $user;
    }
}
