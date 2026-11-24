<?php

namespace App\View\Components\Account;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class Developers extends Component
{
    public function __construct(
        public Collection $tokens,
        public Collection $apiKeys,
        public Collection $oauthConnections,
        public string $webhookSecret,
    ) {}

    public function render(): View
    {
        return view('components.account.developers');
    }
}
