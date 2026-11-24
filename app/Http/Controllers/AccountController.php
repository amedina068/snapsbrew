<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Models\ApiLog;
use App\Services\OAuthConnections;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AccountController extends Controller
{
    public function index(Request $request, OAuthConnections $oauthConnections)
    {
        $user = Auth::user();

        $company = $user->company()
            ->with(['users' => fn ($query) => $query->orderBy('name')])
            ->first();

        $tokens = $user->tokens
            ->filter(fn ($token) => $token->can('api_requests'))
            ->values();

        $apiKeys = ApiKey::query()
            ->signedUrl()
            ->with(['accessToken'])
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get();

        $tokenIds = $tokens->pluck('id');
        $apiKeyTokenIds = $apiKeys->pluck('token_id');

        $usageTokenIds = $tokenIds
            ->merge($apiKeyTokenIds)
            ->filter()
            ->unique();

        $usageByToken = $usageTokenIds->isEmpty()
            ? collect()
            : ApiLog::query()
                ->select('personal_access_token_id', DB::raw('SUM(renders) as renders'))
                ->whereIn('personal_access_token_id', $usageTokenIds)
                ->post()
                ->successful()
                ->where('created_at', '>=', Carbon::now()->subDays(7))
                ->groupBy('personal_access_token_id')
                ->pluck('renders', 'personal_access_token_id');

        $tokens->each(function ($token) use ($usageByToken) {
            $token->renders_last_7_days = $usageByToken->get($token->id, 0);
        });

        $apiKeys->each(function ($apiKey) use ($usageByToken) {
            $apiKey->renders_last_7_days = $usageByToken->get($apiKey->token_id, 0);
        });

        $invitations = $company
            ? $company->invitations()->pending()->with('inviter')->orderByDesc('created_at')->get()
            : collect();

        $companyRendersLast7Days = $company
            ? ApiLog::query()
                ->whereIn('user_id', $company->users->pluck('id'))
                ->where('created_at', '>=', Carbon::now()->subDays(7))
                ->post()
                ->successful()
                ->sum('renders')
            : 0;
        $statusKey = session('status');
        $statusMessages = [
            'two-factor-authentication-enabled' => 'Two-factor authentication enabled. Please finish configuring it below.',
            'two-factor-authentication-confirmed' => 'Two-factor authentication confirmed and enabled successfully.',
            'two-factor-authentication-disabled' => 'Two-factor authentication disabled for your account.',
            'recovery-codes-generated' => 'Recovery codes regenerated successfully.',
            'password-updated' => 'Your password is updated.',
            'profile-information-updated' => 'Your profile is updated.',
        ];
        $statusMessage = $statusKey ? ($statusMessages[$statusKey] ?? $statusKey) : null;

        $validTabs = ['company', 'users', 'developers', 'security'];
        $initialTab = $request->query('tab', session('open_tab', 'company'));
        if (! in_array($initialTab, $validTabs, true)) {
            $initialTab = 'company';
        }

        return view('account', [
            'user' => $user,
            'company' => $company,
            'users' => $company->users ?? collect(),
            'tokens' => $tokens,
            'apiKeys' => $apiKeys,
            'oauthConnections' => $oauthConnections->forUser($user),
            'companyRendersLast7Days' => $companyRendersLast7Days,
            'invitations' => $invitations,
            'statusMessage' => $statusMessage,
            'initialTab' => $initialTab,
        ]);
    }
}
