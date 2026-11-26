<?php

namespace App\View\Components\Account;

use App\Models\Company;
use App\Models\User;
use App\Support\CompanyOwners;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Component;

/**
 * Owners invite people, cancel invitations, change roles and remove users. A member sees
 * the same lists without these actions, and a short reason. The server
 * checks CompanyPolicy for each action.
 */
class Users extends Component
{
    public bool $canInvite;

    public bool $canManageMembers;

    public function __construct(
        public Company $company,
        public Collection $users,
        public Collection $invitations
    ) {
        $this->canInvite = Gate::allows('manageInvitations', $company);
        $this->canManageMembers = Gate::allows('manageMembers', $company);
    }

    /** The same rule as the server: another active owner must stay. */
    public function canBecomeMember(User $user): bool
    {
        return CompanyOwners::hasOtherActiveOwner($this->users, $user);
    }

    /**
     * The same rules as the server: the user is active, is not the owner of
     * the instance, and is not the last active owner of the company.
     */
    public function canRemove(User $user): bool
    {
        return $this->canManageMembers
            && ! $user->isDeactivated()
            && ! $user->isOwner()
            && (! $user->isCompanyOwner() || CompanyOwners::hasOtherActiveOwner($this->users, $user));
    }

    public function render(): View
    {
        return view('components.account.users');
    }
}
