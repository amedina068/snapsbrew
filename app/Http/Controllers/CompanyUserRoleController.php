<?php

namespace App\Http\Controllers;

use App\Exceptions\LastActiveOwnerException;
use App\Models\User;
use App\Support\CompanyOwners;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * An owner of the company changes the role of a user of the same company.
 * The company always keeps at least one active owner. `CompanyOwners` holds
 * this rule, and `snapsbrew:user:role` uses the same rule.
 */
class CompanyUserRoleController extends Controller
{
    public function update(Request $request, int $user, CompanyOwners $owners): RedirectResponse
    {
        session()->flash('open_tab', 'users');

        $this->authorize('manageMembers', $request->user()->company);

        $data = $request->validate([
            'role' => ['required', 'string', Rule::in(User::COMPANY_ROLES)],
        ]);

        $target = User::query()
            ->where('company_id', $request->user()->company_id)
            ->whereKey($user)
            ->firstOrFail();

        try {
            $owners->changeRole($target, $data['role']);
        } catch (LastActiveOwnerException $exception) {
            throw ValidationException::withMessages(['role' => $exception->getMessage()]);
        }

        $label = $target->company_role === User::COMPANY_ROLE_OWNER ? 'an owner' : 'a member';

        return back()->with([
            'status' => "{$target->email} is now {$label} of the company.",
            'open_tab' => 'users',
        ]);
    }
}
