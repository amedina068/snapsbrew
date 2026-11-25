<?php

namespace App\Http\Controllers;

use App\Events\OnboardingCompleted;
use App\Mail\CompanyInvitationMail;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Policies\CompanyPolicy;
use App\Services\OnboardingSnapDispatcher;
use App\Support\InvitationQuota;
use App\Support\MailDelivery;
use App\Support\Queues;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OnboardingController extends Controller
{
    public function show()
    {
        $user = request()->user();

        if ($user->hasCompletedOnboarding()) {
            return redirect('/dashboard');
        }

        return view('onboarding.show', [
            'name' => $user->name,
            'companyName' => $user->company->name,
            'canRenameCompany' => $user->can('update', $user->company),
            'canInvite' => $user->can('manageInvitations', $user->company),
            'categories' => OnboardingSnapDispatcher::CATEGORY_PREFIX,
        ]);
    }

    /**
     * Save the name of the user. Only an owner of the company can set the
     * company name here. A member gets a validation error if the request
     * has a company name.
     */
    public function storeProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $canRename = $user->can('update', $user->company);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company_name' => $canRename
                ? ['required', 'string', 'max:255']
                : ['prohibited'],
        ], [
            'company_name.prohibited' => CompanyPolicy::DENIED,
        ]);

        DB::transaction(function () use ($user, $data, $canRename) {
            $user->update(['name' => $data['name']]);

            if ($canRename) {
                $user->company->update(['name' => $data['company_name']]);
            }
        });

        return response()->json(['ok' => true]);
    }

    public function storeCategories(Request $request, OnboardingSnapDispatcher $dispatcher): JsonResponse
    {
        $data = $request->validate([
            'categories' => ['required', 'array', 'min:1'],
            'categories.*' => ['string', Rule::in(array_keys(OnboardingSnapDispatcher::CATEGORY_PREFIX))],
        ]);

        $user = $request->user();
        $categories = array_values(array_unique($data['categories']));
        $user->update(['onboarding_categories' => $categories]);

        $dispatcher->discardPendingShowcase($user);
        $dispatcher->dispatchShowcase($user, $categories);

        return response()->json(['ok' => true]);
    }

    public function storeInvites(Request $request): JsonResponse
    {
        $data = $request->validate([
            'emails' => ['array', 'max:25'],
            'emails.*' => ['email:strict'],
        ]);

        $user = $request->user();
        $company = $user->company;

        $this->authorize('manageInvitations', $company);

        $invitations = collect($data['emails'] ?? [])
            ->map(fn ($email) => strtolower(trim($email)))
            ->filter()
            ->unique()
            ->map(fn ($email) => $this->inviteIfPossible($company, $user, $email))
            ->filter()
            ->map(fn (CompanyInvitation $invitation) => ['email' => $invitation->email, 'url' => $invitation->url()])
            ->values();

        // Without mail, the wizard shows each link so the user can send it.
        return response()->json([
            'ok' => true,
            'mail_configured' => MailDelivery::isConfigured(),
            'invitations' => $invitations,
        ]);
    }

    public function finish(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasCompletedOnboarding()) {
            $user->update(['onboarding_completed_at' => now()]);
            event(new OnboardingCompleted($user));
        }

        return response()->json(['redirect' => '/dashboard']);
    }

    /**
     * Create a company invitation and send its mail. The method skips an
     * email of a member or of a pending invitation, so a duplicate does not
     * stop onboarding. It returns null for a skipped email.
     */
    private function inviteIfPossible(Company $company, User $inviter, string $email): ?CompanyInvitation
    {
        $isMember = User::query()
            ->where('company_id', $company->id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->exists();

        if ($isMember) {
            return null;
        }

        $alreadyInvited = CompanyInvitation::query()
            ->where('company_id', $company->id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->pending()
            ->exists();

        if ($alreadyInvited || ! InvitationQuota::take($company)) {
            return null;
        }

        $invitation = $company->invitations()->create([
            'email' => $email,
            'token' => Str::random(40),
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
        ]);

        if (MailDelivery::isConfigured()) {
            Mail::to($email)->queue(
                (new CompanyInvitationMail($invitation, $inviter))->onQueue(Queues::MAIL)
            );
        }

        return $invitation;
    }
}
