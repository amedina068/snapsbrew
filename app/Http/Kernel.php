<?php

namespace App\Http;

use App\Http\Middleware\AcceptJson;
use App\Http\Middleware\AllowIframeMiddleware;
use App\Http\Middleware\AllowsOnlySameServerRequests;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EmbeddedFormMiddleware;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\EnsureOnboardingIsOpen;
use App\Http\Middleware\EnsureUserIsOwner;
use App\Http\Middleware\EnsureUserOnboarded;
use App\Http\Middleware\LogApiRequestMiddleware;
use App\Http\Middleware\LogoutDeactivatedUser;
use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\RedirectToSetup;
use App\Http\Middleware\SignedUrlMiddleware;
use App\Http\Middleware\TrimStrings;
use App\Http\Middleware\TrustHosts;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

class Kernel extends HttpKernel
{
    public function __construct(Application $app, Router $router)
    {
        parent::__construct($app, $router);

        // Laravel sorts route middleware by priority, and it moves `auth` before
        // every middleware that is not in the priority list. Without this line,
        // `auth` sends a deactivated user to the login form with no message.
        $this->addToMiddlewarePriorityBefore(AuthenticatesRequests::class, LogoutDeactivatedUser::class);
    }

    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array
     */
    protected $middleware = [
        TrustHosts::class,
        TrustProxies::class,
        HandleCors::class,
        PreventRequestsDuringMaintenance::class,
        ValidatePostSize::class,
        TrimStrings::class,
        ConvertEmptyStringsToNull::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected $middlewareGroups = [
        'web' => [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            LogoutDeactivatedUser::class,
            AuthenticateSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            SubstituteBindings::class,
            RedirectToSetup::class,
        ],

        'api' => [
            AcceptJson::class,
            EnsureFrontendRequestsAreStateful::class,
            'throttle:api',
            SubstituteBindings::class,
        ],

        'api-embedded-form' => [],
    ];

    /**
     * The application's middleware aliases.
     *
     * Aliases may be used to conveniently assign middleware to routes and groups.
     *
     * @var array
     */
    protected $middlewareAliases = [
        'auth' => Authenticate::class,
        'auth.basic' => AuthenticateWithBasicAuth::class,
        'cache.headers' => SetCacheHeaders::class,
        'can' => Authorize::class,
        'guest' => RedirectIfAuthenticated::class,
        'password.confirm' => RequirePassword::class,
        'signed' => ValidateSignature::class,
        'throttle' => ThrottleRequests::class,
        'verified' => EnsureEmailIsVerified::class,
        'sameServer' => AllowsOnlySameServerRequests::class,
        'logApiRequest' => LogApiRequestMiddleware::class,
        'signedUrlMiddleware' => SignedUrlMiddleware::class,
        'embeddedFormMiddleware' => EmbeddedFormMiddleware::class,
        'abilities' => CheckAbilities::class,
        'allowIframe' => AllowIframeMiddleware::class,
        'onboarded' => EnsureUserOnboarded::class,
        'onboarding.open' => EnsureOnboardingIsOpen::class,
        'owner' => EnsureUserIsOwner::class,
    ];
}
