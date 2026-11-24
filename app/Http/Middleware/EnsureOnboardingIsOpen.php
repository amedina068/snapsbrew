<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The onboarding steps change the company name and send invitations with no
 * other limit. A user who finished onboarding gets 403 from these steps.
 */
class EnsureOnboardingIsOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->hasCompletedOnboarding()) {
            abort(403, 'Onboarding is complete.');
        }

        return $next($request);
    }
}
