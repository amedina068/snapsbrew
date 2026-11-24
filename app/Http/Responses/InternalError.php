<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The 500 body of the snap API, as `ApiSpec` documents the `InternalError`
 * schema.
 *
 * The message of an unexpected exception can hold SQL, file paths, host names
 * or renderer output. The log gets the exception with context. The caller
 * gets a fixed message, the code `internal_error` and the request uuid.
 */
final class InternalError
{
    public const CODE = 'internal_error';

    /**
     * Log the exception and return the body for the caller.
     *
     * @param  string  $subject  What the request tried to create, for example "snap".
     * @param  array<string, mixed>  $context  Extra fields that identify the request.
     * @return array{message: string, code: string}
     */
    public static function report(Throwable $exception, Request $request, string $subject, array $context = []): array
    {
        Log::error("The snap API could not create a {$subject}.", [
            'exception' => $exception,
            'user_id' => Auth::id(),
            'company_id' => Auth::user()?->company_id,
            ...$context,
            'api_log_uuid' => $request->api_log_uuid,
        ]);

        return [
            'message' => "The server could not create the {$subject}. Try again later. If the error continues, contact support and give the X-Request-Uuid header of this response.",
            'code' => self::CODE,
        ];
    }
}
