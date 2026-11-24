<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AllowIframeMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $response->header('Content-Security-Policy', 'frame-ancestors *;');

        return $response;
    }
}
