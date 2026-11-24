<?php

namespace App\View\Components\Account;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class Users extends Component
{
    public function __construct(
        public Collection $users,
        public Collection $invitations
    ) {}

    public function render(): View
    {
        return view('components.account.users');
    }
}
