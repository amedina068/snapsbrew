<?php

namespace App\Http\Middleware;

use App\Providers\AuthServiceProvider;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorize one MCP request that arrives with either of two credential types.
 *
 * The MCP server accepts a Sanctum personal access token and an OAuth 2.0
 * access token, because they serve different callers. A connector directory
 * completes an authorization code flow and holds an OAuth token. A person who
 * runs an agent from a terminal pastes the same bearer token the REST API uses.
 *
 * The two carry different claims for the same permission, so this replaces
 * Sanctum's `abilities` middleware, which reads only its own.
 */
class EnsureMcpAccess
{
    /**
     * The Sanctum ability that the REST API and the CLI bearer token carry.
     */
    public const SANCTUM_ABILITY = 'api_requests';

    /**
     * @throws AuthenticationException|MissingAbilityException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Sanctum stores the token it matched on the user model, and Passport's
        // TokenGuard writes its own token to the same property. Either way this
        // reads the credential that authenticated this request.
        $token = $user?->currentAccessToken();

        if (! $user || ! $token) {
            throw new AuthenticationException;
        }

        if ($token->can(self::SANCTUM_ABILITY) || $token->can(AuthServiceProvider::MCP_SCOPE)) {
            return $next($request);
        }

        // The same exception the route raised when Sanctum's `abilities`
        // middleware guarded it, so the body an existing client reads on a
        // token with the wrong abilities does not change.
        throw new MissingAbilityException(self::SANCTUM_ABILITY);
    }
}
