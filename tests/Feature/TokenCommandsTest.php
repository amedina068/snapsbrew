<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\RunsJsonCommands;
use Tests\TestCase;

class TokenCommandsTest extends TestCase
{
    use RefreshDatabase, RunsJsonCommands;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['email' => 'owner@example.com', 'is_owner' => true]);
    }

    public function test_create_makes_the_same_token_as_the_ui_for_the_first_owner()
    {
        [$exit, $json] = $this->runJson('snapsbrew:token:create', ['--name' => 'agent']);

        $this->assertSame(0, $exit);
        $token = $json['data']['token'];
        $this->assertSame(['id', 'name', 'user', 'abilities', 'last_used_at', 'expires_at', 'created_at', 'value'], array_keys($token));
        $this->assertSame(['api_requests'], $token['abilities']);
        $this->assertSame(['id' => $this->owner->id, 'email' => 'owner@example.com'], $token['user']);
        $this->assertNull($token['expires_at']);

        // The token opens the REST API, like a token from Account > Developers.
        $this->withHeader('Authorization', 'Bearer '.$token['value'])->getJson('/api/v1/templates')->assertOk();
    }

    public function test_create_reads_a_duration_as_the_expiry()
    {
        $this->freezeSecond();

        [, $json] = $this->runJson('snapsbrew:token:create', ['--name' => 'agent', '--expires' => '30d']);

        $this->assertSame(now()->addDays(30)->toIso8601String(), $json['data']['token']['expires_at']);
    }

    public function test_create_refuses_bad_input_with_exit_two()
    {
        $member = User::factory()->create(['email' => 'member@example.com']);

        $cases = [
            [['--name' => ''], 'missing_option'],
            [['--name' => 'x', '--user' => 'nobody@example.com'], 'user_not_found'],
            [['--name' => 'x', '--abilities' => '*'], 'invalid_token_request'],
            [['--name' => 'x', '--abilities' => 'signed_url'], 'invalid_token_request'],
            [['--name' => 'x', '--expires' => 'soon'], 'invalid_token_request'],
            [['--name' => 'x', '--expires' => '2001-01-01'], 'invalid_token_request'],
            // instance_admin: owners only, alone, and 7 days or less.
            [['--name' => 'x', '--abilities' => 'instance_admin', '--user' => $member->email], 'invalid_token_request'],
            [['--name' => 'x', '--abilities' => 'instance_admin,api_requests'], 'invalid_token_request'],
            [['--name' => 'x', '--abilities' => 'instance_admin', '--expires' => '8d'], 'invalid_token_request'],
            [['--name' => 'x', '--abilities' => 'instance_admin', '--expires' => 'never'], null],
        ];

        foreach ($cases as [$parameters, $code]) {
            [$exit, $json] = $this->runJson('snapsbrew:token:create', $parameters);

            if ($code === null) {
                // `never` gives the default admin expiry of one day.
                $this->assertSame(0, $exit);
                $this->assertNotNull($json['data']['token']['expires_at']);

                continue;
            }

            $this->assertSame(2, $exit, json_encode($parameters));
            $this->assertSame($code, $json['errors'][0]['code']);
        }

        $this->assertSame(1, PersonalAccessToken::count());
    }

    public function test_create_gives_an_owner_a_short_lived_instance_admin_token()
    {
        [$exit, $json] = $this->runJson('snapsbrew:token:create', ['--name' => 'ops', '--abilities' => 'instance_admin', '--expires' => '7d']);

        $this->assertSame(0, $exit);
        $this->assertSame(['instance_admin'], $json['data']['token']['abilities']);

        // The admin token does not open the product API.
        $this->withHeader('Authorization', 'Bearer '.$json['data']['token']['value'])->getJson('/api/v1/templates')->assertForbidden();
    }

    public function test_list_never_shows_a_value()
    {
        $this->owner->createToken('one', ['api_requests']);
        User::factory()->create(['email' => 'member@example.com'])->createToken('two', ['api_requests']);

        [$exit, $json, $raw] = $this->runJson('snapsbrew:token:list');

        $this->assertSame(0, $exit);
        $this->assertCount(2, $json['data']['tokens']);
        $this->assertSame(['id', 'name', 'user', 'abilities', 'last_used_at', 'expires_at', 'created_at'], array_keys($json['data']['tokens'][0]));
        $this->assertStringNotContainsString('"value"', $raw);
        $this->assertStringNotContainsString('"token"', $raw);

        [, $json] = $this->runJson('snapsbrew:token:list', ['--user' => 'member@example.com']);
        $this->assertSame(['two'], array_column($json['data']['tokens'], 'name'));

        [$exit, $json] = $this->runJson('snapsbrew:token:list', ['--user' => 'nobody@example.com']);
        $this->assertSame(2, $exit);
    }

    public function test_revoke_deletes_the_token_and_a_second_run_changes_nothing()
    {
        $token = $this->owner->createToken('one', ['api_requests']);

        [$exit, $json] = $this->runJson('snapsbrew:token:revoke', ['id' => (string) $token->accessToken->id]);
        $this->assertSame(0, $exit);
        $this->assertSame(['id' => $token->accessToken->id, 'status' => 'revoked'], $json['data']);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);

        [$exit, $json] = $this->runJson('snapsbrew:token:revoke', ['id' => (string) $token->accessToken->id]);
        $this->assertSame(0, $exit);
        $this->assertSame('absent', $json['data']['status']);

        [$exit, $json] = $this->runJson('snapsbrew:token:revoke', ['id' => 'abc']);
        $this->assertSame(2, $exit);
        $this->assertSame('invalid_id', $json['errors'][0]['code']);
    }
}
