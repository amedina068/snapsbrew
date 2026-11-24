<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AllowsOnlySameServerRequests
{
    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if ($request->server('SERVER_ADDR') != $request->ip()) {
            return abort(404);
        }

        return $next($request);
    }
}
