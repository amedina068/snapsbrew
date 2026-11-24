<?php

namespace App\Http\Middleware;

use App\Support\InstanceSetup;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends the entry pages of a new instance to the setup page.
 *
 * The middleware reads the database only for GET / and GET /login, and for
 * each request to /register. Every other request passes with no query.
 * A POST to /register also goes to setup. Otherwise, on an instance with open
 * registration, a stranger can create the first account before the owner.
 */
class RedirectToSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        $entryPage = $request->is('register') || ($request->isMethod('GET') && $request->is('/', 'login'));

        if ($entryPage && InstanceSetup::isNeeded()) {
            return redirect('/setup');
        }

        return $next($request);
    }
}
