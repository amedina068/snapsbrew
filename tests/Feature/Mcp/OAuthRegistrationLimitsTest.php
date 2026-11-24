<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Token;
use Tests\TestCase;

/**
 * `POST /oauth/register` takes no authentication (RFC 7591). It must limit
 * each IP, the size of each registration, and the life of an unused client.
 */
class OAuthRegistrationLimitsTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

    protected function setUp(): void
    {
        parent::setUp();

        config(['mcp.redirect_domains' => ['https://claude.ai', 'https://claude.com']]);
    }

    public function test_the_register_route_is_registered_once_and_has_a_throttle()
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => $route->uri() === 'oauth/register' && in_array('POST', $route->methods(), true));

        $this->assertCount(1, $routes);
        $this->assertContains('throttle:oauth-register', $routes->first()->gatherMiddleware());
    }

    public function test_a_registration_with_too_many_redirect_uris_is_refused()
    {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Many',
            'redirect_uris' => array_fill(0, 6, self::REDIRECT),
        ]);

        $response->assertStatus(400)->assertJson(['error' => 'invalid_redirect_uri']);
        $this->assertStringContainsString('5', $response->json('error_description'));
        $this->assertSame(0, Client::query()->count());
    }

    public function test_a_registration_with_a_long_redirect_uri_is_refused()
    {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Long',
            'redirect_uris' => [self::REDIRECT.'?pad='.str_repeat('a', 2100)],
        ]);

        $response->assertStatus(400)->assertJson(['error' => 'invalid_redirect_uri']);
        $this->assertStringContainsString('2000', $response->json('error_description'));
        $this->assertSame(0, Client::query()->count());
    }

    public function test_a_large_registration_body_is_refused()
    {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Large',
            'redirect_uris' => [self::REDIRECT],
            'logo_uri' => str_repeat('a', 20000),
        ]);

        $response->assertStatus(413);
        $this->assertSame(0, Client::query()->count());
    }

    public function test_one_ip_can_register_only_a_few_clients_each_minute()
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/oauth/register', [
                'client_name' => "Client $i",
                'redirect_uris' => [self::REDIRECT],
            ])->assertCreated();
        }

        $this->postJson('/oauth/register', [
            'client_name' => 'One too many',
            'redirect_uris' => [self::REDIRECT],
        ])->assertStatus(429);

        $this->assertSame(3, Client::query()->count());
    }

    private function registeredClient(int $daysAgo): Client
    {
        RateLimiter::clear('oauth-register:minute:127.0.0.1');

        $id = $this->postJson('/oauth/register', [
            'client_name' => 'Registered',
            'redirect_uris' => [self::REDIRECT],
        ])->assertCreated()->json('client_id');

        $client = Client::query()->findOrFail($id);
        $client->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $client;
    }

    public function test_the_prune_deletes_only_old_registered_clients_that_never_got_a_token()
    {
        $oldUnused = $this->registeredClient(8);
        $recentUnused = $this->registeredClient(6);
        $oldUsed = $this->registeredClient(30);
        $user = User::factory()->create();
        Token::query()->forceCreate([
            'id' => Str::random(80),
            'user_id' => $user->id,
            'client_id' => $oldUsed->id,
            'name' => null,
            'scopes' => ['mcp:use'],
            'revoked' => false,
            'expires_at' => now()->subDays(20),
        ]);
        $confidential = Client::query()->forceCreate([
            'name' => 'First party',
            'secret' => 'not-a-real-secret',
            'redirect_uris' => [self::REDIRECT],
            'grant_types' => ['authorization_code'],
            'revoked' => false,
        ]);
        $confidential->forceFill(['created_at' => now()->subDays(60)])->save();

        $this->artisan('oauth-clients:prune')->assertSuccessful();

        $this->assertNull(Client::query()->find($oldUnused->id));
        $this->assertNotNull(Client::query()->find($recentUnused->id));
        $this->assertNotNull(Client::query()->find($oldUsed->id));
        $this->assertNotNull(Client::query()->find($confidential->id));
    }

    public function test_the_prune_runs_every_day()
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_ends_with((string) $event->command, ' oauth-clients:prune'));

        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->first()->expression);
    }
}
