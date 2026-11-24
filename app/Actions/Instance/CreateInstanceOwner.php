<?php

namespace App\Actions\Instance;

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use App\Support\InstanceSetup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Creates the owner account of a self-hosted instance.
 *
 * The `snapsbrew:install` command and the web setup page both use this action.
 * The owner gets a new company and a verified email.
 */
class CreateInstanceOwner
{
    public function __construct(private CreateNewUser $creator) {}

    /**
     * @param  array{name: string, email: string, password: string, password_confirmation?: string, company?: string|null}  $input
     */
    public function create(array $input): User
    {
        return DB::transaction(fn () => $this->createOwner($input));
    }

    /**
     * Create the owner only when the instance has no user yet. Two requests
     * can pass the first check at the same time. The lock and the second
     * check inside the transaction make sure that only one owner gets created.
     * Returns null when a user already exists.
     *
     * @param  array{name: string, email: string, password: string, password_confirmation?: string, company?: string|null}  $input
     */
    public function createFirstOwner(array $input): ?User
    {
        return Cache::lock('snapsbrew:instance-setup', 30)->block(10, function () use ($input) {
            return DB::transaction(function () use ($input) {
                if (! InstanceSetup::isNeeded()) {
                    return null;
                }

                return $this->createOwner($input);
            });
        });
    }

    /**
     * @param  array{name: string, email: string, password: string, password_confirmation?: string, company?: string|null}  $input
     */
    private function createOwner(array $input): User
    {
        $user = $this->creator->createAccount([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'password_confirmation' => $input['password_confirmation'] ?? $input['password'],
        ]);

        $user->company->update(['name' => ($input['company'] ?? null) ?: "{$input['name']}'s Company"]);
        $user->forceFill(['is_owner' => true, 'email_verified_at' => now()])->save();

        return $user;
    }
}
