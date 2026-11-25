<?php

namespace App\View\Components\Account;

use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Component;

/**
 * Owners invite people, cancel invitations and change roles. A member sees
 * the same lists without these actions, and a short reason. The server
 * checks CompanyPolicy for each action.
 */
class Users extends Component
{
    public bool $canInvite;

    public bool $canManageMembers;

    public int $ownerCount;

    public function __construct(
        public Company $company,
        public Collection $users,
        public Collection $invitations
    ) {
        $this->canInvite = Gate::allows('manageInvitations', $company);
        $this->canManageMembers = Gate::allows('manageMembers', $company);
        $this->ownerCount = $users->filter(fn (User $user) => $user->isCompanyOwner())->count();
    }

    public function render(): View
    {
        return view('components.account.users');
    }
}
