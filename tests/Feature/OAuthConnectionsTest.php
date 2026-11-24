<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Tests\Support\WithPassportKeys;
use Tests\TestCase;

/**
 * The OAuth connections section of the Developers tab.
 *
 * The consent screen tells the person that they can end a connection from the
 * account page. These tests hold the page to that sentence: it lists each
 * application that the person authorized, and its revoke action ends both the
 * access token and the refresh token.
 */
class OAuthConnectionsTest extends TestCase
{
    use RefreshDatabase;
    use WithPassportKeys;

    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWithPassportKeys();

        config(['mcp.redirect_domains' => ['https://claude.ai', 'https://claude.com']]);
    }

    private function user(): User
    {
        $company = Company::factory()->create();

        return User::factory()->create(['company_id' => $company->id]);
    }

    private function client(string $name): Client
    {
        return Client::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'secret' => null,
            'provider' => null,
            'redirect_uris' => [self::REDIRECT],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
    }

    /**
     * Write an access token and its refresh token, the two rows a code
     * exchange leaves behind.
     */
    private function token(User $user, Client $client, array $attributes = []): Token
    {
        $token = Token::forceCreate($attributes + [
            'id' => Str::random(80),
            'user_id' => $user->id,
            'client_id' => $client->id,
            'name' => null,
            'scopes' => ['mcp:use'],
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
            'expires_at' => now()->addDays(15),
        ]);

        RefreshToken::forceCreate([
            'id' => Str::random(80),
            'access_token_id' => $token->id,
            'revoked' => false,
            'expires_at' => now()->addMonths(6),
        ]);

        return $token;
    }

    // ------------------------------------------------------------------- list

    public function test_the_developers_tab_lists_the_applications_the_user_authorized()
    {
        $user = $this->user();
        $client = $this->client('Claude Connector');
        $this->token($user, $client, ['created_at' => now()->subDays(10)]);
        $this->token($user, $client, ['created_at' => now()->subDay()]);

        $response = $this->actingAs($user)->get('/account?tab=developers');

        $response->assertOk();
        $response->assertSee('OAuth connections');
        $response->assertSee('Claude Connector');
        $response->assertSee('action="'.url('/account/oauth-connections/'.$client->id).'"', false);

        $connections = $response->viewData('oauthConnections');
        $this->assertCount(1, $connections);
        $this->assertSame($client->id, $connections->first()->client_id);
        $this->assertSame('Claude Connector', $connections->first()->client_name);
    }

    public function test_the_list_leaves_out_other_users_and_revoked_or_expired_connections()
    {
        $user = $this->user();
        $other = $this->user();

        $this->token($other, $this->client('Other User App'));
        $this->token($user, $this->client('Revoked App'), ['revoked' => true]);

        $expired = $this->token($user, $this->client('Expired App'), ['expires_at' => now()->subDay()]);
        $expired->refreshToken->forceFill(['expires_at' => now()->subDay()])->save();

        $response = $this->actingAs($user)->get('/account?tab=developers');

        $response->assertOk();
        $response->assertDontSee('Other User App');
        $response->assertDontSee('Revoked App');
        $response->assertDontSee('Expired App');
        $response->assertSee('No OAuth connections yet.');
    }

    /**
     * An access token lives 15 days and a refresh token lives 6 months. A
     * connector whose access token expired can still refresh, so the
     * connection is still live and the person must still see it.
     */
    public function test_a_connection_with_an_expired_access_token_and_a_live_refresh_token_is_listed()
    {
        $user = $this->user();
        $this->token($user, $this->client('Sleeping Connector'), ['expires_at' => now()->subDay()]);

        $this->actingAs($user)->get('/account?tab=developers')
            ->assertOk()
            ->assertSee('Sleeping Connector');
    }

    // ----------------------------------------------------------------- revoke

    public function test_revoke_ends_the_access_and_refresh_tokens_of_that_user_for_that_client()
    {
        $user = $this->user();
        $other = $this->user();
        $client = $this->client('Claude Connector');
        $keep = $this->client('Another Connector');

        $first = $this->token($user, $client);
        $second = $this->token($user, $client);
        $otherUsers = $this->token($other, $client);
        $otherClients = $this->token($user, $keep);

        DB::table('oauth_auth_codes')->insert([
            'id' => Str::random(80),
            'user_id' => $user->id,
            'client_id' => $client->id,
            'scopes' => '["mcp:use"]',
            'revoked' => false,
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->actingAs($user)
            ->from('/account?tab=developers')
            ->delete('/account/oauth-connections/'.$client->id)
            ->assertRedirect('/account?tab=developers')
            ->assertSessionHas('open_tab', 'developers');

        foreach ([$first, $second] as $token) {
            $this->assertTrue($token->fresh()->revoked);
            $this->assertTrue($token->refreshToken->fresh()->revoked);
        }

        $this->assertSame(0, DB::table('oauth_auth_codes')->where('client_id', $client->id)->where('revoked', false)->count());

        $this->assertFalse($otherUsers->fresh()->revoked);
        $this->assertFalse($otherUsers->refreshToken->fresh()->revoked);
        $this->assertFalse($otherClients->fresh()->revoked);
        $this->assertFalse($otherClients->refreshToken->fresh()->revoked);
    }

    public function test_revoke_of_a_client_with_no_token_for_the_user_returns_404()
    {
        $user = $this->user();
        $other = $this->user();
        $client = $this->client('Claude Connector');
        $token = $this->token($other, $client);

        $this->actingAs($user)
            ->delete('/account/oauth-connections/'.$client->id)
            ->assertNotFound();

        $this->assertFalse($token->fresh()->revoked);
    }

    public function test_revoke_needs_a_signed_in_user()
    {
        $client = $this->client('Claude Connector');

        $this->delete('/account/oauth-connections/'.$client->id)->assertRedirect('/login');
    }

    // ------------------------------------------------------- the real token

    /**
     * Run the real consent and code exchange, then revoke from the account
     * page. The access token must stop at `/mcp`, and the refresh token must
     * not mint a new one.
     */
    public function test_a_revoked_connection_can_no_longer_call_the_mcp_server_or_refresh()
    {
        $user = $this->user();
        $client = $this->postJson('/oauth/register', [
            'client_name' => 'Test Connector',
            'redirect_uris' => [self::REDIRECT],
        ])->json();

        $verifier = str_repeat('a', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT,
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => 'state',
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
            'code_verifier' => $verifier,
        ])->assertOk();

        $accessToken = $token->json('access_token');
        $refreshToken = $token->json('refresh_token');

        // The person revokes the connection from the account page.
        $this->actingAs($user)
            ->delete('/account/oauth-connections/'.$client['client_id'])
            ->assertRedirect();

        // Drop the session user, or Sanctum answers for the session and the
        // bearer token is never read.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->withHeader('Authorization', "Bearer {$accessToken}")
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertUnauthorized();

        $this->withHeaders(['Authorization' => ''])->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client['client_id'],
            'refresh_token' => $refreshToken,
        ])->assertStatus(400);
    }
}
