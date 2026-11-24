<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;

/**
 * The signed middleware casts `signature` to a string and crashes on an array
 * (B-066). This check runs before it and answers the same 403 for any query
 * value that is not one string.
 */
class RejectNonScalarSignature
{
    public function handle(Request $request, Closure $next): mixed
    {
        foreach (['signature', 'expires'] as $parameter) {
            if ($request->query->has($parameter) && ! is_string($request->query($parameter))) {
                throw new InvalidSignatureException;
            }
        }

        return $next($request);
    }
}
