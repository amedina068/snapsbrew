<?php

namespace App\Providers;

use App\Auth\ActiveUserProvider;
use App\Models\Company;
use App\Models\Font;
use App\Models\Template;
use App\Models\User;
use App\Policies\CompanyPolicy;
use App\Policies\FontPolicy;
use App\Policies\TemplatePolicy;
use DateInterval;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Passport;
use Laravel\Sanctum\Sanctum;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The one scope the MCP server issues.
     *
     * `Mcp::oauthRoutes()` advertises this string in both discovery documents
     * and writes it into every Dynamic Client Registration response. The name
     * is the package's, not ours, so it cannot be renamed here alone.
     */
    public const MCP_SCOPE = 'mcp:use';

    /**
     * The policy mappings for the application.
     *
     * @var array
     */
    protected $policies = [
        Template::class => TemplatePolicy::class,
        Font::class => FontPolicy::class,
        Company::class => CompanyPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function register()
    {
        // The device code grant asks a person to type a code into a second
        // screen. No surface of this product uses it, and an enabled grant is
        // four more public endpoints to defend.
        //
        // This one line sits in register(), not boot(). Passport declares its
        // routes in its own boot() method, and it reads this flag as it does so.
        // Every register() runs before the first boot(), so this is the last
        // moment that still removes the routes.
        Passport::$deviceCodeGrantEnabled = false;

        // Passport serves the OAuth flow of the MCP server only. With
        // MCP_ENABLED=false, no route of it is registered. The flag is static,
        // so it is set both ways on every boot.
        Passport::$registersRoutes = (bool) config('mcp.enabled');
    }

    /**
     * Bootstrap any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        $this->refuseDeactivatedUsers();

        $this->configurePassport();
    }

    /**
     * Keep a deactivated user out of every guard.
     *
     * The session guard and the Passport guard find the user through the
     * `active-eloquent` provider. Sanctum reads the user of a personal access
     * token from the token row and skips the provider, so it needs its own check.
     * The login form, signed URLs and embedded forms do their own checks.
     */
    protected function refuseDeactivatedUsers(): void
    {
        Auth::provider('active-eloquent', function ($app, array $config) {
            return new ActiveUserProvider($app['hash'], $config['model']);
        });

        Sanctum::authenticateAccessTokensUsing(function ($token, bool $isValid) {
            return $isValid
                && ! ($token->tokenable instanceof User && $token->tokenable->isDeactivated());
        });
    }

    /**
     * Configure the OAuth 2.0 authorization server that serves the MCP server.
     */
    protected function configurePassport(): void
    {
        // laravel/mcp registers this scope on its own, from an `app->booted()`
        // callback, with the description "Use MCP server". That string is the
        // only sentence the consent screen can show a person about what they
        // are about to hand over, and it does not name the company data.
        //
        // This declaration wins because a provider boots before the booted
        // callbacks run, and `Registrar::ensureMcpScope()` keeps a description
        // that is already there. Delete it and the flow still works, with the
        // package sentence back on the screen.
        Passport::tokensCan([
            self::MCP_SCOPE => 'Create and read snaps, templates and batches for your company',
        ]);

        // Read-and-poll agents hold a connection for weeks, so an access token
        // that lives one hour breaks them. Passport defaults to one year, which
        // is too long for a token that can render for a company. These two numbers are
        // the middle: a stolen access token expires inside a month, and a
        // connector that refreshes twice a year keeps working. Passport rotates
        // the refresh token on every use (`revokeRefreshTokenAfterUse`).
        Passport::tokensExpireIn(new DateInterval('P15D'));
        Passport::refreshTokensExpireIn(new DateInterval('P6M'));

        Passport::authorizationView('oauth.authorize');
    }
}
