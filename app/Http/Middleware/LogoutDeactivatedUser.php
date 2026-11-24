<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * End the web session of a deactivated user at the next request.
 *
 * Two cases reach this middleware. The guard can hold a user that the owner
 * deactivated during the request cycle. Or the session holds the id of a
 * deactivated user, which `ActiveUserProvider` does not return. In both cases
 * the session ends, and the login form tells the person why.
 */
class LogoutDeactivatedUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->hasDeactivatedUser($request)) {
            return $next($request);
        }

        $guard = Auth::guard('web');

        $guard->forgetUser();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Cookie::queue(Cookie::forget($guard->getRecallerName()));

        if ($request->expectsJson()) {
            return response()->json(['message' => User::DEACTIVATED_MESSAGE], 401);
        }

        return redirect('/login')->withErrors(['email' => User::DEACTIVATED_MESSAGE]);
    }

    private function hasDeactivatedUser(Request $request): bool
    {
        $guard = Auth::guard('web');
        $user = $guard->user();

        if ($user) {
            return $user instanceof User && $user->isDeactivated();
        }

        $id = $request->session()->get($guard->getName());

        return $id !== null
            && User::query()->whereKey($id)->whereNotNull('deactivated_at')->exists();
    }
}
