<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\LastActiveOwnerException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyOwners;

/**
 * Deactivate (store) and reactivate (destroy) one user. The data of the user
 * stays in place. Only the access changes.
 */
class UserDeactivationController extends Controller
{
    public function store(User $user, CompanyOwners $owners)
    {
        // The owner cannot lock the instance. This rule also covers the owner
        // that sends the request.
        abort_if($user->isOwner(), 403, 'You cannot deactivate an owner.');

        // A company that has active users keeps an active owner. The instance
        // owner chooses the new company owner. The app does not choose one.
        try {
            $owners->deactivate($user);
        } catch (LastActiveOwnerException) {
            $company = Company::query()->whereKey($user->company_id)->value('name') ?? 'the company';

            return back()->with('error', "{$user->email} is the last active owner of {$company}. "
                .'Make another active user of the company an owner first. '
                .'Run php artisan snapsbrew:user:role <email> owner.');
        }

        return back()->with('status', "{$user->email} is deactivated.");
    }

    public function destroy(User $user)
    {
        $user->reactivate();

        return back()->with('status', "{$user->email} is active again.");
    }
}
