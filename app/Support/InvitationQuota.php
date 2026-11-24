<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One daily cap on the invitation mails of a company. The account page and
 * the onboarding wizard both use it, so the two paths share one count.
 */
final class InvitationQuota
{
    private const WINDOW_SECONDS = 86400;

    /**
     * Count one invitation. Returns false, and counts nothing, when the
     * company has used its cap.
     */
    public static function take(Company $company): bool
    {
        $key = 'company-invitations:'.$company->id;
        $cap = (int) config('snapsbrew.invitations_per_company_per_day');

        if (RateLimiter::tooManyAttempts($key, $cap)) {
            return false;
        }

        RateLimiter::hit($key, self::WINDOW_SECONDS);

        return true;
    }
}
