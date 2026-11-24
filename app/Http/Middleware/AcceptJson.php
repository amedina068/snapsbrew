<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every error of a route in the `api` group is a JSON body, also for a client
 * that sends no `Accept: application/json` header.
 *
 * Without this middleware, the framework answered such a client with a 302 to
 * /login for a bad token, a 302 back for a validation error and an HTML page
 * for a missing record. A success body does not change, because no API
 * controller reads the Accept header.
 */
class AcceptJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
