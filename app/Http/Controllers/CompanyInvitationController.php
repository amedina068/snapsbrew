<?php

namespace App\Http\Controllers;

use App\Mail\CompanyInvitationMail;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Support\InvitationQuota;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompanyInvitationController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        session()->flash('open_tab', 'users');

        $request->validate([
            'email' => ['required', 'email:strict'],
        ]);

        $company = Auth::user()->company;

        $email = strtolower($request->email);

        $existingMember = User::query()
            ->where('company_id', $company->id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->exists();

        if ($existingMember) {
            session()->flash('open_tab', 'users');
            throw ValidationException::withMessages([
                'email' => 'This user is already part of your company.',
            ]);
        }

        $existingInvitation = CompanyInvitation::query()
            ->where('company_id', $company->id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->pending()
            ->first();

        if ($existingInvitation) {
            session()->flash('open_tab', 'users');
            throw ValidationException::withMessages([
                'email' => 'An invitation has already been sent to this email.',
            ]);
        }

        if (! InvitationQuota::take($company)) {
            throw ValidationException::withMessages([
                'email' => 'Your company reached the daily limit of invitations. Try again tomorrow.',
            ]);
        }

        $invitation = $company->invitations()->create([
            'email' => $email,
            'token' => Str::random(40),
            'invited_by' => Auth::id(),
            'expires_at' => now()->addDays(7),
        ]);

        Mail::to($email)
            ->send(new CompanyInvitationMail($invitation, Auth::user()));

        return back()->with([
            'status' => 'Invitation sent successfully.',
            'open_tab' => 'users',
        ]);
    }

    public function destroy($invitation): RedirectResponse
    {
        $invitation = CompanyInvitation::query()
            ->where('company_id', Auth::user()->company_id)
            ->where('id', $invitation)
            ->firstOrFail();

        $invitation->delete();

        return back()->with([
            'status' => 'Invitation cancelled.',
            'open_tab' => 'users',
        ]);
    }
}
