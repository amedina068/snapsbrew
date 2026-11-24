<?php

namespace Tests\Feature\Mcp;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WithPassportKeys;
use Tests\TestCase;

/**
 * An OAuth 2.0 token that a connector holds stops working when the owner
 * deactivates the person that approved it. Reactivation restores it.
 */
class McpDeactivatedUserTest extends TestCase
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
        return User::factory()->create(['company_id' => Company::factory()->create()->id]);
    }

    /**
     * @return array<string, string>
     */
    private function authorizeQuery(string $clientId, string $challenge): array
    {
        return [
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => 'state',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];
    }

    /**
     * Run the PKCE flow and return the access token.
     */
    private function oauthToken(User $user): string
    {
        $client = $this->postJson('/oauth/register', [
            'client_name' => 'Test Connector',
            'redirect_uris' => [self::REDIRECT],
        ])->json();

        $verifier = str_repeat('a', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->actingAs($user)
            ->get('/oauth/authorize?'.http_build_query($this->authorizeQuery($client['client_id'], $challenge)))
            ->assertOk();

        $approve = $this->actingAs($user)->post('/oauth/authorize', ['auth_token' => session('authToken')]);
        parse_str(parse_url($approve->headers->get('Location'), PHP_URL_QUERY) ?: '', $query);

        $token = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT,
            'code' => $query['code'],
            'code_verifier' => $verifier,
        ])->json('access_token');

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $token;
    }

    private function callMcp(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);
    }

    public function test_an_oauth_token_stops_working_and_works_again_after_reactivation()
    {
        $user = $this->user();
        $token = $this->oauthToken($user);

        $this->callMcp($token)->assertOk();

        $user->deactivate();
        $this->callMcp($token)->assertUnauthorized();

        $user->reactivate();
        $this->callMcp($token)->assertOk();
    }

    public function test_a_deactivated_user_cannot_open_the_consent_screen()
    {
        $user = $this->user();
        $client = $this->postJson('/oauth/register', [
            'client_name' => 'Test Connector',
            'redirect_uris' => [self::REDIRECT],
        ])->json();

        $this->actingAs($user);
        $user->deactivate();

        $this->get('/oauth/authorize?'.http_build_query($this->authorizeQuery($client['client_id'], str_repeat('b', 43))))
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => User::DEACTIVATED_MESSAGE]);
    }
}
