<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * An owner of the company changes the role of a user of the same company.
 * The company always keeps at least one owner.
 */
class CompanyUserRoleController extends Controller
{
    public const LAST_OWNER = 'The company must keep at least one owner. Make another user an owner first.';

    public function update(Request $request, int $user): RedirectResponse
    {
        session()->flash('open_tab', 'users');

        $this->authorize('manageMembers', $request->user()->company);

        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'role' => ['required', 'string', Rule::in(User::COMPANY_ROLES)],
        ]);

        $target = DB::transaction(function () use ($companyId, $user, $data) {
            // Lock the owner rows, so two owners cannot demote each other at
            // the same time and leave the company with no owner.
            $owners = User::query()
                ->where('company_id', $companyId)
                ->where('company_role', User::COMPANY_ROLE_OWNER)
                ->lockForUpdate()
                ->pluck('id');

            $target = User::query()
                ->where('company_id', $companyId)
                ->whereKey($user)
                ->firstOrFail();

            if ($data['role'] === User::COMPANY_ROLE_MEMBER && $owners->count() === 1 && (int) $owners->first() === (int) $target->id) {
                throw ValidationException::withMessages(['role' => self::LAST_OWNER]);
            }

            $target->forceFill(['company_role' => $data['role']])->save();

            return $target;
        });

        $label = $target->company_role === User::COMPANY_ROLE_OWNER ? 'an owner' : 'a member';

        return back()->with([
            'status' => "{$target->email} is now {$label} of the company.",
            'open_tab' => 'users',
        ]);
    }
}
