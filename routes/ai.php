<?php

use App\Http\Middleware\EnsureInstanceAdminAccess;
use App\Http\Middleware\EnsureMcpAccess;
use App\Http\Middleware\LimitOAuthClientRegistration;
use App\Http\Middleware\ValidateCreativePackageEnvelope;
use App\Mcp\Servers\InstanceAdminServer;
use App\Mcp\Servers\SnapsbrewServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

/*
|--------------------------------------------------------------------------
| MCP Server
|--------------------------------------------------------------------------
|
| Two credential types reach this one route.
|
| A connector directory runs the OAuth 2.0 authorization code flow with PKCE
| and sends the access token it received. A person who runs an agent from a
| terminal sends the same Sanctum bearer token the REST API takes.
|
| The order `sanctum,api` is load-bearing. Do not reverse it. Passport's guard
| erases the Authorization header when it cannot parse a token
| (`TokenGuard::getPsrRequestViaBearerToken()` calls
| `$this->request->headers->set('Authorization', '')` before it gives up), so a
| guard that runs after it reads an empty header. With `api,sanctum`, every
| Sanctum token receives 401 and one reported exception, and no test of the
| OAuth path can see it. `McpOAuthTest` locks the order with an assertion.
|
| `EnsureMcpAccess` then reads whichever token matched. Sanctum carries the
| `api_requests` ability and OAuth carries the `mcp:use` scope.
|
| Throttled with the `mcp` limiter.
|
*/

/*
|--------------------------------------------------------------------------
| Instance-Admin MCP Server
|--------------------------------------------------------------------------
|
| The operator server runs over stdio. An agent starts it on the server:
|
|   ssh host docker exec -i <container> php artisan mcp:start snapsbrew-admin
|
| It does not depend on MCP_ENABLED, which switches off the product server
| only. The HTTP route exists only when MCP_ADMIN_ENABLED=true. It takes
| Sanctum alone, never Passport, because an OAuth client never gets the
| `instance_admin` ability.
|
*/
Mcp::local(InstanceAdminServer::HANDLE, InstanceAdminServer::class);

if (config('mcp.admin_enabled')) {
    Mcp::web('/mcp/admin', InstanceAdminServer::class)
        ->middleware(['auth:sanctum', EnsureInstanceAdminAccess::class, 'throttle:mcp']);
}

// MCP_ENABLED=false removes the server and its OAuth routes (config/mcp.php).
if (! config('mcp.enabled')) {
    return;
}

Mcp::web('/mcp', SnapsbrewServer::class)
    ->middleware(['auth:sanctum,api', EnsureMcpAccess::class, 'throttle:mcp', ValidateCreativePackageEnvelope::class]);

/*
| The two discovery documents an MCP client reads before it authenticates
| (RFC 9728 and RFC 8414), plus `POST /oauth/register` for Dynamic Client
| Registration (RFC 7591). A 401 from the route above answers with a
| `WWW-Authenticate` header that points at the first of them.
|
| Passport serves the endpoints these documents name: `/oauth/authorize` and
| `/oauth/token`. The scope description and the token lifetimes are declared in
| `App\Providers\AuthServiceProvider`, not here, because this file does not run
| when the routes are cached.
*/
Mcp::oauthRoutes();

// The package registers `POST /oauth/register` with no middleware. This route
// replaces it with the same controller, a throttle for each IP, and a limit on
// the size of a registration. `OAuthRegistrationLimitsTest` makes sure that
// only one route answers. The route keeps the package name
// `mcp.oauth.register`, because the discovery document names the
// `registration_endpoint` only when a route has this name.
Route::post('oauth/register', OAuthRegisterController::class)
    ->middleware(['throttle:oauth-register', LimitOAuthClientRegistration::class])
    ->name('mcp.oauth.register');
