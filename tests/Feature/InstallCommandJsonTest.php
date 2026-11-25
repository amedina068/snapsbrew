<?php

namespace Tests\Feature;

use App\Jobs\InstallStarterTemplates;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\RunsJsonCommands;
use Tests\Support\WithPassportKeys;
use Tests\TestCase;

class InstallCommandJsonTest extends TestCase
{
    use RefreshDatabase, RunsJsonCommands, WithPassportKeys;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_a_fresh_install_returns_the_owner_and_a_login_url_with_no_password()
    {
        [$exit, $json] = $this->runJson('snapsbrew:install', ['--name' => 'Olive Owner', '--email' => 'owner@example.com']);

        $this->assertSame(0, $exit);
        $data = $json['data'];
        $this->assertSame('created', $data['status']);
        $this->assertSame(['id', 'name', 'email', 'company'], array_keys($data['owner']));
        $this->assertSame('owner@example.com', $data['owner']['email']);
        $this->assertFalse($data['password_set']);
        $this->assertSame('queued', $data['starter_templates']);
        $this->assertStringContainsString('/login/link/', $data['login_url']);
        $this->assertSame(15, $data['login_url_expires_in_minutes']);
        $this->assertArrayNotHasKey('token', $data);
        $this->assertArrayNotHasKey('connect', $data);
        $this->assertSame([], $json['errors']);

        $owner = User::where('email', 'owner@example.com')->firstOrFail();
        $this->assertTrue($owner->isOwner());
        Queue::assertPushed(InstallStarterTemplates::class, fn ($job) => $job->user->is($owner));
    }

    public function test_the_login_url_signs_the_owner_in_one_time()
    {
        [, $json] = $this->runJson('snapsbrew:install', ['--name' => 'Olive Owner', '--email' => 'owner@example.com', '--no-starter-templates' => true]);
        $path = parse_url($json['data']['login_url'], PHP_URL_PATH).'?'.parse_url($json['data']['login_url'], PHP_URL_QUERY);

        $this->get($path)->assertRedirect(config('fortify.home'));
        $this->assertAuthenticatedAs(User::where('email', 'owner@example.com')->first());

        auth()->logout();
        $this->get($path)->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_with_token_returns_a_product_token_and_ready_connect_lines()
    {
        [$exit, $json] = $this->runJson('snapsbrew:install', [
            '--name' => 'Olive Owner', '--email' => 'owner@example.com', '--with-token' => 'agent', '--no-starter-templates' => true,
        ]);

        $this->assertSame(0, $exit);
        $token = $json['data']['token'];
        $this->assertSame(['id', 'name', 'user', 'abilities', 'last_used_at', 'expires_at', 'created_at', 'value'], array_keys($token));
        $this->assertSame('agent', $token['name']);
        $this->assertSame(['api_requests'], $token['abilities']);
        $this->assertNull($token['expires_at']);

        $connect = $json['data']['connect'];
        $this->assertSame(['mcp_url', 'token_env', 'env', 'claude', 'codex', 'claude_oauth'], array_keys($connect));
        $this->assertSame(url('/mcp'), $connect['mcp_url']);
        $this->assertSame('claude mcp add --transport http snapsbrew '.url('/mcp').' --header "Authorization: Bearer '.$token['value'].'"', $connect['claude']);
        $this->assertSame('codex mcp add snapsbrew --url '.url('/mcp').' --bearer-token-env-var SNAPSBREW_TOKEN', $connect['codex']);
        $this->assertSame("export SNAPSBREW_TOKEN='{$token['value']}'", $connect['env']);

        // The token in the claude line opens the MCP server.
        $this->withHeader('Authorization', 'Bearer '.$token['value'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'whoami', 'arguments' => []]])
            ->assertOk()
            ->assertJsonPath('result.isError', false);
    }

    public function test_a_second_run_returns_exists_and_exits_zero_with_no_second_owner()
    {
        $this->runJson('snapsbrew:install', ['--name' => 'Olive Owner', '--email' => 'owner@example.com', '--no-starter-templates' => true]);

        [$exit, $json] = $this->runJson('snapsbrew:install', ['--name' => 'Second', '--email' => 'second@example.com']);

        $this->assertSame(0, $exit);
        $this->assertSame('exists', $json['data']['status']);
        $this->assertSame('owner@example.com', $json['data']['owner']['email']);
        $this->assertNull($json['data']['starter_templates']);
        $this->assertNotEmpty($json['data']['login_url']);
        $this->assertDatabaseMissing('users', ['email' => 'second@example.com']);
        $this->assertSame(1, User::where('is_owner', true)->count());
    }

    public function test_a_second_run_with_a_token_creates_a_new_token_for_the_existing_owner()
    {
        $this->runJson('snapsbrew:install', ['--name' => 'Olive Owner', '--email' => 'owner@example.com', '--with-token' => 'agent', '--no-starter-templates' => true]);
        [$exit, $json] = $this->runJson('snapsbrew:install', ['--with-token' => 'agent']);

        $this->assertSame(0, $exit);
        $this->assertSame('exists', $json['data']['status']);
        $this->assertSame(2, PersonalAccessToken::where('name', 'agent')->count());
    }

    public function test_missing_name_or_email_is_bad_input()
    {
        [$exit, $json] = $this->runJson('snapsbrew:install', ['--name' => 'Olive Owner']);

        $this->assertSame(2, $exit);
        $this->assertFalse($json['ok']);
        $this->assertSame('missing_option', $json['errors'][0]['code']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_an_invalid_email_is_bad_input_and_creates_nothing()
    {
        [$exit, $json] = $this->runJson('snapsbrew:install', ['--name' => 'Olive Owner', '--email' => 'not-an-email']);

        $this->assertSame(2, $exit);
        $this->assertSame('invalid_input', $json['errors'][0]['code']);
        $this->assertSame('invalid', $json['data']['status']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_deprecated_password_option_still_sets_the_password()
    {
        [$exit, $json] = $this->runJson('snapsbrew:install', [
            '--name' => 'Olive Owner', '--email' => 'owner@example.com', '--password' => 'a-long-password-1', '--no-starter-templates' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertTrue($json['data']['password_set']);
        $this->assertTrue(Hash::check('a-long-password-1', User::where('email', 'owner@example.com')->first()->password));
    }

    public function test_an_empty_token_name_is_bad_input()
    {
        [$exit, $json] = $this->runJson('snapsbrew:install', ['--name' => 'Olive Owner', '--email' => 'owner@example.com', '--with-token' => ' ', '--no-starter-templates' => true]);

        $this->assertSame(2, $exit);
        $this->assertSame('invalid_token_name', $json['errors'][0]['code']);
    }

    public function test_prepare_returns_what_it_prepared_and_creates_no_account()
    {
        [$exit, $json] = $this->runJson('snapsbrew:install', ['--prepare' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame('prepared', $json['data']['status']);
        $this->assertContains($json['data']['prepared']['passport_keys'], ['exists', 'created', 'environment']);
        $this->assertContains($json['data']['prepared']['storage_link'], ['exists', 'created']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_promote_and_demote_use_the_contract_exit_codes()
    {
        User::factory()->create(['email' => 'only@example.com', 'is_owner' => true]);
        User::factory()->create(['email' => 'member@example.com']);

        [$exit, $json] = $this->runJson('snapsbrew:install', ['--demote' => 'only@example.com']);
        $this->assertSame(3, $exit);
        $this->assertSame('last_owner', $json['errors'][0]['code']);

        [$exit, $json] = $this->runJson('snapsbrew:install', ['--demote' => 'member@example.com']);
        $this->assertSame(3, $exit);
        $this->assertSame('not_an_owner', $json['errors'][0]['code']);

        [$exit, $json] = $this->runJson('snapsbrew:install', ['--promote' => 'nobody@example.com']);
        $this->assertSame(2, $exit);
        $this->assertSame('user_not_found', $json['errors'][0]['code']);

        [$exit, $json] = $this->runJson('snapsbrew:install', ['--promote' => 'member@example.com']);
        $this->assertSame(0, $exit);
        $this->assertSame('promoted', $json['data']['status']);

        [$exit, $json] = $this->runJson('snapsbrew:install', ['--promote' => 'member@example.com']);
        $this->assertSame(0, $exit);
        $this->assertSame('unchanged', $json['data']['status']);
    }
}
