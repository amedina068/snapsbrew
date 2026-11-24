<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Let only the owner of the instance through. Everyone else, guests included,
 * gets a 404, so the admin area does not show that it exists.
 */
class EnsureUserIsOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isOwner(), 404);

        return $next($request);
    }
}
