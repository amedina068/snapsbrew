<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Checks a signed route URL on the current host or on a previous app host.
 *
 * Laravel signs the absolute URL, so the host is part of the signature. Third
 * parties keep embedded form URLs that the app signed on an old host, and that
 * host now answers with a 308 to the current one. This check rebuilds the URL
 * with each host in `app.previous_urls` and asks Laravel to verify it. The
 * signing key does not change, so a URL that nobody signed still fails.
 */
final class HostIndependentSignature
{
    public static function valid(Request $request): bool
    {
        if ($request->hasValidSignature()) {
            return true;
        }

        $path = $request->getBaseUrl().$request->getPathInfo();
        $queryString = (string) $request->server->get('QUERY_STRING');

        foreach (config('app.previous_urls', []) as $origin) {
            $previous = Request::create(rtrim($origin, '/').$path.'?'.$queryString, $request->getMethod());

            // Laravel signs the raw query string. Request::create() rebuilds it,
            // so put the raw one back.
            $previous->server->set('QUERY_STRING', $queryString);

            if (URL::hasValidSignature($previous)) {
                return true;
            }
        }

        return false;
    }
}
