<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;

/**
 * Deactivate (store) and reactivate (destroy) one user. The data of the user
 * stays in place. Only the access changes.
 */
class UserDeactivationController extends Controller
{
    public function store(User $user)
    {
        // The owner cannot lock the instance. This rule also covers the owner
        // that sends the request.
        abort_if($user->isOwner(), 403, 'You cannot deactivate an owner.');

        $user->deactivate();

        return back()->with('status', "{$user->email} is deactivated.");
    }

    public function destroy(User $user)
    {
        $user->reactivate();

        return back()->with('status', "{$user->email} is active again.");
    }
}
