<?php

namespace App\Http\Controllers;

use App\Exceptions\LastActiveOwnerException;
use App\Models\User;
use App\Support\CompanyOwners;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * An owner of the company removes a user of the same company. The removal
 * deactivates the account, the same as **Admin > Users**. The user cannot
 * sign in, and the tokens and signed URLs of the user stop working. The
 * templates and snaps of the user stay in the company.
 *
 * The company always keeps at least one active owner. `CompanyOwners` holds
 * this rule.
 */
class CompanyUserController extends Controller
{
    public function destroy(Request $request, int $user, CompanyOwners $owners): RedirectResponse
    {
        session()->flash('open_tab', 'users');

        $this->authorize('manageMembers', $request->user()->company);

        $target = User::query()
            ->where('company_id', $request->user()->company_id)
            ->whereNull('deactivated_at')
            ->whereKey($user)
            ->firstOrFail();

        // The owner of the instance cannot lose access. Admin > Users has the
        // same rule.
        abort_if($target->isOwner(), 403, 'You cannot remove the owner of this instance.');

        try {
            $owners->remove($target);
        } catch (LastActiveOwnerException $exception) {
            throw ValidationException::withMessages(['remove' => $exception->getMessage()]);
        }

        return back()->with([
            'status' => "{$target->email} is removed from the company.",
            'open_tab' => 'users',
        ]);
    }
}
