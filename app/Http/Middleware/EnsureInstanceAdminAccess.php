<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Instance\ApiTokens;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards `/mcp/admin`, the HTTP route of the instance-admin MCP server.
 *
 * The request must carry a Sanctum token that names `instance_admin` in its
 * abilities, belongs to an owner, and expires in 7 days or less from its
 * creation. The check reads the ability list itself, because `can()` answers
 * true for a `*` token, and the API key endpoint creates `*` tokens.
 */
class EnsureInstanceAdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! $user instanceof User || ! $token instanceof PersonalAccessToken) {
            throw new AuthenticationException;
        }

        $abilities = (array) $token->abilities;
        $expiresAt = $token->expires_at;

        $allowed = in_array(ApiTokens::ADMIN_ABILITY, $abilities, true)
            && $user->isOwner()
            && ! $user->isDeactivated()
            && $expiresAt !== null
            && $token->created_at !== null
            && $expiresAt->lessThanOrEqualTo($token->created_at->copy()->addDays(ApiTokens::ADMIN_MAX_DAYS)->addMinute());

        abort_unless($allowed, 403, 'This route needs an owner token with the instance_admin ability that expires in 7 days or less.');

        return $next($request);
    }
}
