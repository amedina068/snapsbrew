<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use League\OAuth2\Server\Exception\OAuthServerException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        // Passport's guard runs on `/mcp` after Sanctum declines, and it calls
        // `report()` on every bearer token it cannot parse
        // (`TokenGuard::getPsrRequestViaBearerToken()`). A Sanctum token is not
        // a JWT, so one expired key in an agent's configuration wrote 90 lines
        // of stack trace per retry.
        //
        // Nothing is lost. This class is how the OAuth endpoints report a bad
        // request to the caller, and they still answer 400 or 401 with the
        // error code. Only the log entry goes.
        OAuthServerException::class,
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }
}
