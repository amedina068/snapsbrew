<?php

namespace App\View\Components\Account;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Security extends Component
{
    public bool $twoFactorEnabled;

    public bool $twoFactorConfirmed;

    public array $recoveryCodes;

    public function __construct(public User $user)
    {
        $this->twoFactorEnabled = ! is_null($this->user->two_factor_secret);
        $this->twoFactorConfirmed = ! is_null($this->user->two_factor_confirmed_at);
        $this->recoveryCodes = $this->twoFactorEnabled && $this->user->two_factor_recovery_codes
            ? $this->user->recoveryCodes()
            : [];
    }

    public function render(): View
    {
        return view('components.account.security');
    }
}
