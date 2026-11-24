<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies a named limiter to the Fortify routes that Fortify leaves open.
 *
 * Fortify registers its routes inside a group with the middleware of
 * `fortify.middleware`, and gives a limiter to the login and the two-factor
 * routes only. This middleware runs on every Fortify route and throttles the
 * routes below by their name. `FortifyServiceProvider` defines the limiters.
 */
class ThrottleAuthRequests
{
    /** Route name => limiter name. */
    public const LIMITERS = [
        'password.email' => 'password-reset-link',
        'password.update' => 'password-reset',
        'register.store' => 'register',
        'password.confirm.store' => 'password-confirm',
        'user-password.update' => 'password-change',
    ];

    public function __construct(private ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next): Response
    {
        $limiter = self::LIMITERS[$request->route()?->getName()] ?? null;

        if ($limiter === null) {
            return $next($request);
        }

        return $this->throttle->handle($request, $next, $limiter);
    }
}
