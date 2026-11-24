<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The free tools routes exist only when SNAPSBREW_FREE_TOOLS_ENABLED is true.
 * Otherwise they return 404.
 */
class EnsureFreeToolsAreEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('services.free_tools.enabled'), 404);

        return $next($request);
    }
}
