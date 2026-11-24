<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bounces freshly registered users into the onboarding wizard until they
 * finish it. Existing users were backfilled as completed by migration, so
 * they are never affected.
 */
class EnsureUserOnboarded
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->hasCompletedOnboarding()) {
            return redirect('/onboarding');
        }

        return $next($request);
    }
}
