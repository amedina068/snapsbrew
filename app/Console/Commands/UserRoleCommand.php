<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesJsonOutput;
use App\Exceptions\LastActiveOwnerException;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyOwners;
use Illuminate\Console\Command;

/**
 * Sets the company role of a user: `owner` or `member`.
 *
 * The command uses the same rule as `PATCH /account/users/{id}/role`. Each
 * company keeps at least one active owner. It is also the fix for a company
 * with no active owner, which `snapsbrew:doctor` reports.
 */
class UserRoleCommand extends Command
{
    use WritesJsonOutput;

    protected $signature = 'snapsbrew:user:role
        {email : The email of the user}
        {role : The new company role: owner or member}
        {--json : Write one JSON object to stdout}';

    protected $description = 'Set the company role of a user (owner or member).';

    public function handle(CompanyOwners $owners): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $role = strtolower(trim((string) $this->argument('role')));

        if (! in_array($role, User::COMPANY_ROLES, true)) {
            return $this->failWith(self::EXIT_BAD_INPUT, 'invalid_role', "The role must be owner or member, not \"{$this->argument('role')}\".", 'Run the command again with owner or member.');
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            return $this->failWith(self::EXIT_BAD_INPUT, 'user_not_found', "No user has the email {$this->argument('email')}.", 'Run php artisan snapsbrew:user:list to find the email.');
        }

        $company = $user->company_id === null ? null : Company::query()->find($user->company_id);

        if ($company === null) {
            return $this->failWith(self::EXIT_BAD_INPUT, 'no_company', "{$user->email} has no company, so the user has no company role.", 'Choose a user who belongs to a company.');
        }

        $previous = (string) $user->company_role;

        try {
            $changed = $owners->changeRole($user, $role);
        } catch (LastActiveOwnerException $exception) {
            return $this->failWith(
                self::EXIT_CONFLICT,
                'last_active_owner',
                "{$user->email} is the last active owner of {$company->name}. ".$exception->getMessage(),
                'Make another active user an owner first: php artisan snapsbrew:user:role <email> owner. Then run this command again.',
                $this->data($user, $company, $previous, false),
            );
        }

        $label = $role === User::COMPANY_ROLE_OWNER ? 'an owner' : 'a member';

        if ($changed) {
            $this->note("{$user->email} is now {$label} of {$company->name}.");
        } else {
            $this->note("{$user->email} is already {$label} of {$company->name}. Nothing changed.");
        }

        if ($role === User::COMPANY_ROLE_OWNER && $user->isDeactivated() && ! $this->wantsJson()) {
            $this->components->warn('This user is deactivated. A deactivated owner cannot manage the company.');
        }

        return $this->respond(self::EXIT_OK, $this->data($user, $company, $previous, $changed));
    }

    /**
     * @return array<string, mixed>
     */
    private function data(User $user, Company $company, string $previous, bool $changed): array
    {
        return [
            'user' => ['id' => $user->id, 'email' => $user->email, 'deactivated' => $user->isDeactivated()],
            'company' => ['id' => $company->id, 'name' => $company->name],
            'company_role' => $user->company_role,
            'previous_role' => $previous,
            'changed' => $changed,
        ];
    }
}
