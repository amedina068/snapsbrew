<?php

namespace App\Events;

use App\Models\Snap;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class FirstSnapCreated
{
    use Dispatchable;

    public function __construct(public User $user, public Snap $snap) {}
}
