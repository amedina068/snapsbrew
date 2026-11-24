<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /oauth/register` takes no authentication (RFC 7591). The package
 * controller does not limit the size of a registration, so this middleware
 * refuses a large body and a long or long list of redirect URIs first. The
 * errors use the RFC 7591 error format.
 */
class LimitOAuthClientRegistration
{
    public const MAX_BODY_BYTES = 16384;

    public const MAX_REDIRECT_URIS = 5;

    public const MAX_REDIRECT_URI_LENGTH = 2000;

    public function handle(Request $request, Closure $next): Response
    {
        if (strlen((string) $request->getContent()) > self::MAX_BODY_BYTES) {
            return response()->json([
                'error' => 'invalid_client_metadata',
                'error_description' => 'The registration body must be 16 KB or less.',
            ], 413);
        }

        $uris = $request->input('redirect_uris');

        if (is_array($uris) && count($uris) > self::MAX_REDIRECT_URIS) {
            return $this->invalidRedirectUri('A client can register '.self::MAX_REDIRECT_URIS.' redirect URIs or fewer.');
        }

        foreach (is_array($uris) ? $uris : [] as $uri) {
            if (is_string($uri) && strlen($uri) > self::MAX_REDIRECT_URI_LENGTH) {
                return $this->invalidRedirectUri('Each redirect URI must be '.self::MAX_REDIRECT_URI_LENGTH.' characters or fewer.');
            }
        }

        return $next($request);
    }

    private function invalidRedirectUri(string $description): Response
    {
        return response()->json([
            'error' => 'invalid_redirect_uri',
            'error_description' => $description,
        ], 400);
    }
}
