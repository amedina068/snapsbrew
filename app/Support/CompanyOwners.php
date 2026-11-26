<?php

namespace App\Support;

use App\Exceptions\LastActiveOwnerException;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The rule that keeps an active owner in each company.
 *
 * An active owner has the company role `owner` and no `deactivated_at`. Only
 * an active owner can change the roles and manage the members of a company.
 * A company that has active users must keep at least one active owner.
 *
 * These paths use this class, so they use the same rule:
 *
 * - `PATCH /account/users/{id}/role` and `snapsbrew:user:role` change a role.
 * - `DELETE /account/users/{id}` removes a user from the company. The
 *   removal deactivates the user.
 * - **Admin > Users** deactivates a user.
 * - `snapsbrew:doctor` reports each company with no active owner.
 *
 * A company with no active user is closed on purpose. It needs no owner.
 */
class CompanyOwners
{
    /**
     * Sets the company role of the user. Returns false when the user already
     * has this role.
     *
     * @throws LastActiveOwnerException when the user is the last active owner
     */
    public function changeRole(User $user, string $role): bool
    {
        return DB::transaction(function () use ($user, $role) {
            $owners = $this->lockOwners($user);

            if (User::query()->whereKey($user->id)->value('company_role') === $role) {
                $user->refresh();

                return false;
            }

            if ($role === User::COMPANY_ROLE_MEMBER && ! self::hasOtherActiveOwner($owners, $user)) {
                throw new LastActiveOwnerException($user);
            }

            $user->forceFill(['company_role' => $role])->save();

            return true;
        });
    }

    /**
     * Deactivates the user. The last active owner of a company stays active
     * while the company has other active users.
     *
     * @throws LastActiveOwnerException when the user is the last active owner
     */
    public function deactivate(User $user): void
    {
        $this->deactivateKeepingAnOwner($user, closeEmptyCompany: true);
    }

    /**
     * An owner removes a user from the company. The removal deactivates the
     * user with the same rule as `deactivate()`. The last active owner always
     * stays, also when the company has no other active user, because an
     * owner cannot close their own company.
     *
     * @throws LastActiveOwnerException when the user is the last active owner
     */
    public function remove(User $user): void
    {
        $this->deactivateKeepingAnOwner($user, closeEmptyCompany: false);
    }

    /**
     * True when one of the owners is active and is not this user.
     *
     * @param  Collection<int, User>  $owners
     */
    public static function hasOtherActiveOwner(Collection $owners, User $user): bool
    {
        return $owners->contains(fn (User $owner) => $owner->isCompanyOwner()
            && ! $owner->isDeactivated()
            && (int) $owner->id !== (int) $user->id);
    }

    /**
     * Each company that has an active user but no active owner. The
     * suggested owner is the active user who joined first.
     *
     * @return list<array{id: int, name: string, suggested_owner: string}>
     */
    public static function companiesWithoutActiveOwner(): array
    {
        $active = fn (Builder $query) => $query->whereNull('deactivated_at');

        return Company::query()
            ->whereHas('users', $active)
            ->whereDoesntHave('users', fn (Builder $query) => $active($query)->where('company_role', User::COMPANY_ROLE_OWNER))
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (Company $company) => [
                'id' => (int) $company->id,
                'name' => (string) $company->name,
                'suggested_owner' => (string) $company->users()->whereNull('deactivated_at')->orderBy('id')->value('email'),
            ])
            ->values()
            ->all();
    }

    /**
     * Locks the owner rows of the company of the user, so that two changes
     * cannot remove the last two active owners at the same time.
     *
     * @return Collection<int, User>
     */
    private function lockOwners(User $user): Collection
    {
        if ($user->company_id === null) {
            return collect();
        }

        return User::query()
            ->where('company_id', $user->company_id)
            ->where('company_role', User::COMPANY_ROLE_OWNER)
            ->lockForUpdate()
            ->get(['id', 'company_role', 'deactivated_at']);
    }

    /**
     * Deactivates the user unless the user is the last active owner. With
     * `closeEmptyCompany`, the last active owner can go when no other user of
     * the company is active.
     *
     * @throws LastActiveOwnerException when the user is the last active owner
     */
    private function deactivateKeepingAnOwner(User $user, bool $closeEmptyCompany): void
    {
        DB::transaction(function () use ($user, $closeEmptyCompany) {
            $owners = $this->lockOwners($user);
            $isActiveOwner = $owners->contains(fn (User $owner) => (int) $owner->id === (int) $user->id && ! $owner->isDeactivated());

            if ($isActiveOwner
                && ! self::hasOtherActiveOwner($owners, $user)
                && (! $closeEmptyCompany || $this->hasOtherActiveUser($user))) {
                throw new LastActiveOwnerException($user);
            }

            $user->deactivate();
        });
    }

    private function hasOtherActiveUser(User $user): bool
    {
        return User::query()
            ->where('company_id', $user->company_id)
            ->whereKeyNot($user->id)
            ->whereNull('deactivated_at')
            ->exists();
    }
}
