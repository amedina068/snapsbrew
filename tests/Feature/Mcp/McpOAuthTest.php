<?php

namespace Tests\Feature\Mcp;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Tests\Support\WithPassportKeys;
use Tests\TestCase;

/**
 * The OAuth 2.0 surface that a connector directory drives.
 *
 * A directory reads two discovery documents, registers itself, sends a person
 * to the consent screen, and exchanges the code it receives for a token. Every
 * step below is one of those, in that order, and the last one spends the token
 * on the MCP server.
 */
class McpOAuthTest extends TestCase
{
    use RefreshDatabase;
    use WithPassportKeys;

    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWithPassportKeys();

        // Pin the allowlist so the test reads the same way whatever a developer
        // put in their own environment file.
        config(['mcp.redirect_domains' => ['https://claude.ai', 'https://claude.com']]);
    }

    /**
     * Drop the signed-in person that the consent step left in memory.
     *
     * Sanctum's guard reads the `web` guard before it reads the bearer token,
     * and it gives a session user a TransientToken, which answers `can()` with
     * true for every ability. `actingAs()` sets that guard user for the rest of
     * the test, so a later call with an OAuth token passes on the session
     * instead of the token, and proves nothing.
     *
     * This is a test artifact and not a hole. Production reaches `/mcp` with no
     * session at all: the route sits outside the `web` middleware group, so
     * StartSession never runs and the `web` guard has nobody to return.
     */
    private function forgetTheBrowserSession(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    private function user(): User
    {
        $company = Company::factory()->create();

        return User::factory()->create(['company_id' => $company->id]);
    }

    /**
     * Register a client the way a directory does, through the public endpoint.
     *
     * @return array<string, mixed>
     */
    private function registerClient(string $redirect = self::REDIRECT): array
    {
        return $this->postJson('/oauth/register', [
            'client_name' => 'Test Connector',
            'redirect_uris' => [$redirect],
        ])->json();
    }

    // ---------------------------------------------------------------- discovery

    public function test_the_protected_resource_document_names_the_scope_and_the_server()
    {
        $response = $this->getJson('/.well-known/oauth-protected-resource');

        $response->assertOk();
        $this->assertSame(url('/'), $response->json('resource'));
        $this->assertSame([url('/')], $response->json('authorization_servers'));
        $this->assertSame(['mcp:use'], $response->json('scopes_supported'));
    }

    /**
     * A client that reads the document for a nested resource path still has to
     * find the same authorization server. This is the route the 401 challenge
     * points at, so it carries the whole discovery chain.
     */
    public function test_the_protected_resource_document_answers_for_a_nested_path()
    {
        $response = $this->getJson('/.well-known/oauth-protected-resource/mcp');

        $response->assertOk();
        $this->assertSame(url('/mcp'), $response->json('resource'));
    }

    public function test_the_authorization_server_document_advertises_pkce_and_the_endpoints()
    {
        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertOk();
        $this->assertSame(url('/'), $response->json('issuer'));
        $this->assertSame(url('/oauth/authorize'), $response->json('authorization_endpoint'));
        $this->assertSame(url('/oauth/token'), $response->json('token_endpoint'));
        $this->assertSame(url('/oauth/register'), $response->json('registration_endpoint'));

        // The directory refuses a server that cannot prove the code belongs to
        // the client that asked for it.
        $this->assertSame(['S256'], $response->json('code_challenge_methods_supported'));
        $this->assertSame(['authorization_code', 'refresh_token'], $response->json('grant_types_supported'));
    }

    /**
     * The MCP specification requires the device code grant to be absent unless
     * the server means to offer it. Four routes come with it, and every one is
     * public, so their absence is worth an assertion.
     */
    public function test_the_device_code_grant_is_not_registered()
    {
        $this->assertFalse(app('router')->has('passport.device.code'));
        $this->assertFalse(app('router')->has('passport.device.authorizations.approve'));

        $this->post('/oauth/device/code')->assertNotFound();
    }

    // ------------------------------------------------------------- registration

    public function test_registration_creates_a_public_client_for_an_allowed_domain()
    {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Test Connector',
            'redirect_uris' => [self::REDIRECT],
        ]);

        $response->assertStatus(201);
        $this->assertSame('mcp:use', $response->json('scope'));
        $this->assertSame([self::REDIRECT], $response->json('redirect_uris'));

        // PKCE replaces the client secret. A client that holds no secret must
        // say so, or the token endpoint asks it to authenticate.
        $this->assertSame('none', $response->json('token_endpoint_auth_method'));

        $client = Client::find($response->json('client_id'));
        $this->assertNotNull($client);
        $this->assertFalse($client->confidential(), 'A connector client must hold no secret.');
    }

    /**
     * The registration endpoint has no authentication, by design. The domain
     * list is the only thing that decides where a code can be delivered, so an
     * unlisted host must be refused.
     */
    public function test_registration_refuses_a_redirect_outside_the_allowlist()
    {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Not Us',
            'redirect_uris' => ['https://attacker.example/callback'],
        ]);

        $response->assertStatus(400);
        $this->assertSame('invalid_redirect_uri', $response->json('error'));
        $this->assertSame(0, Client::query()->count());
    }

    public function test_registration_refuses_a_custom_scheme_that_is_not_configured()
    {
        config(['mcp.custom_schemes' => []]);

        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Desktop Client',
            'redirect_uris' => ['cursor://anysite/callback'],
        ]);

        $response->assertStatus(400);
        $this->assertSame('invalid_redirect_uri', $response->json('error'));
    }

    public function test_registration_requires_a_redirect_uri()
    {
        $response = $this->postJson('/oauth/register', ['client_name' => 'No Redirect']);

        $response->assertStatus(400);
        $this->assertSame('invalid_redirect_uri', $response->json('error'));
    }

    public function test_a_registration_without_a_client_name_is_named_after_the_redirect_host()
    {
        // RFC 7591 makes client_name optional. laravel/mcp 0.8.2 names the
        // client after the host that receives the code, which is the fact the
        // consent screen must show.
        $response = $this->postJson('/oauth/register', ['redirect_uris' => [self::REDIRECT]]);

        $response->assertCreated();
        $this->assertSame('claude.ai', Client::find($response->json('client_id'))->name);
    }

    // ------------------------------------------------------------- the challenge

    /**
     * A client that holds no token must be able to find the authorization
     * server from the refusal alone. RFC 9728 puts that in the header.
     */
    public function test_an_unauthenticated_call_points_the_client_at_the_discovery_document()
    {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $response->assertStatus(401);

        $header = $response->headers->get('WWW-Authenticate');
        $this->assertStringContainsString('Bearer realm="mcp"', $header);
        $this->assertStringContainsString(
            'resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"',
            $header
        );
    }

    // --------------------------------------------------------- the full round trip

    public function test_a_connector_completes_the_pkce_flow_and_calls_the_server()
    {
        $user = $this->user();
        $client = $this->registerClient();

        $verifier = str_repeat('a', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        // 1. The person lands on the consent screen.
        $authorize = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT,
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => 'the-client-state',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]));

        $authorize->assertOk();
        $authorize->assertViewIs('oauth.authorize');
        $authorize->assertSee('Test Connector', false);

        // 2. They approve it.
        $approve = $this->actingAs($user)->post('/oauth/authorize', [
            'auth_token' => session('authToken'),
        ]);

        $approve->assertRedirect();
        $location = $approve->headers->get('Location');
        $this->assertStringStartsWith(self::REDIRECT, $location);

        parse_str(parse_url($location, PHP_URL_QUERY) ?: '', $query);
        $this->assertSame('the-client-state', $query['state'] ?? null);
        $this->assertArrayHasKey('code', $query);

        // 3. The client exchanges the code, proving it is the one that asked.
        $token = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT,
            'code' => $query['code'],
            'code_verifier' => $verifier,
        ]);

        $token->assertOk();
        $this->assertSame('Bearer', $token->json('token_type'));
        $this->assertNotEmpty($token->json('refresh_token'));
        $accessToken = $token->json('access_token');
        $this->assertNotEmpty($accessToken);

        // 4. The token reaches the MCP server, and nothing else does.
        $this->forgetTheBrowserSession();

        $call = $this->withHeader('Authorization', "Bearer {$accessToken}")
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
            ]);

        $call->assertOk();
    }

    /**
     * PKCE exists for this case. A code stolen from the redirect is worthless
     * without the verifier that produced the challenge.
     */
    public function test_the_token_endpoint_refuses_a_code_with_the_wrong_verifier()
    {
        $user = $this->user();
        $client = $this->registerClient();

        $verifier = str_repeat('a', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT,
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]))->assertOk();

        $approve = $this->actingAs($user)->post('/oauth/authorize', [
            'auth_token' => session('authToken'),
        ]);

        parse_str(parse_url($approve->headers->get('Location'), PHP_URL_QUERY) ?: '', $query);

        $token = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT,
            'code' => $query['code'],
            'code_verifier' => str_repeat('b', 64),
        ]);

        $token->assertStatus(400);
    }

    /**
     * The scope description is the whole explanation a person gets before they
     * grant access. laravel/mcp registers its own sentence, "Use MCP server",
     * which names neither the credits nor the company data at stake.
     */
    public function test_the_consent_screen_describes_the_scope_in_product_terms()
    {
        $user = $this->user();
        $client = $this->registerClient();

        $verifier = str_repeat('a', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $response = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT,
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]));

        $response->assertOk();
        $response->assertSee('Create and read snaps, templates and batches for your company', false);
        $response->assertDontSee('Use MCP server', false);
    }

    public function test_the_consent_screen_says_the_application_is_not_reviewed()
    {
        $user = $this->user();
        $client = $this->registerClient();

        $verifier = str_repeat('a', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $response = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT,
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]));

        // Registration is open, so the name on the screen is the client's own
        // claim. The screen has to say that, or it reads as an endorsement.
        $response->assertSee(config('app.name').' did not review this application', false);
    }

    public function test_the_consent_screen_needs_a_signed_in_person()
    {
        $client = $this->registerClient();
        $verifier = str_repeat('a', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $response = $this->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT,
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]));

        $response->assertRedirect('/login');
    }

    /**
     * A client that holds no secret must prove the code belongs to it. Passport
     * refuses the request before it reaches the consent screen when the
     * challenge is absent, so no code is ever created without one.
     */
    public function test_the_authorize_endpoint_refuses_a_public_client_with_no_pkce_challenge()
    {
        $user = $this->user();
        $client = $this->registerClient();

        $response = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT,
            'response_type' => 'code',
            'scope' => 'mcp:use',
        ]));

        $response->assertStatus(400);
    }

    // ------------------------------------------------------ the two credentials

    /**
     * The bearer token path is the reason this feature could ship without a
     * migration for existing callers. It must keep working unchanged.
     */
    public function test_a_sanctum_token_still_reaches_the_server()
    {
        $user = $this->user();
        $token = $user->createToken('cli', ['api_requests'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
            ])
            ->assertOk();
    }

    /**
     * A bearer token that is not an OAuth token must not fill the error log.
     *
     * Passport's guard runs after Sanctum declines, and it calls `report()` on
     * every token it cannot parse. A Sanctum token is not a JWT, so before the
     * handler ignored this class, one expired key in an agent's configuration
     * wrote 90 lines of stack trace on every retry. Measured over HTTP.
     */
    public function test_a_token_that_is_not_an_oauth_token_reports_nothing()
    {
        $records = [];
        $this->app['log']->listen(function ($record) use (&$records) {
            $records[] = $record;
        });

        $this->withHeader('Authorization', 'Bearer 99|not-a-real-token')
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
            ])
            ->assertStatus(401);

        $this->assertSame([], $records, 'A rejected bearer token must write no log entry.');
    }

    /**
     * Pin the order of the two guards on the route.
     *
     * Passport's guard erases the Authorization header when it cannot parse the
     * token, so a guard that runs after it sees an empty header. Listing `api`
     * first therefore returns 401 for every Sanctum token, and no test of the
     * OAuth path can catch it, because the OAuth path still works.
     */
    public function test_the_route_reads_the_sanctum_guard_before_the_oauth_guard()
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'mcp' && in_array('POST', $route->methods(), true));

        $this->assertNotNull($route);
        $this->assertContains('auth:sanctum,api', $route->gatherMiddleware());
    }

    /**
     * The two credentials carry different claims for one permission. A Sanctum
     * token that holds neither must still be refused, or the new middleware has
     * widened the door rather than added a second one.
     */
    public function test_a_sanctum_token_without_the_ability_is_still_refused()
    {
        $user = $this->user();
        $token = $user->createToken('cli', ['some_other_ability'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
            ])
            ->assertStatus(403);
    }
}
