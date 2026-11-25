<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\RunsJsonCommands;
use Tests\TestCase;

class McpConnectCommandTest extends TestCase
{
    use RefreshDatabase, RunsJsonCommands;

    public function test_claude_without_a_token_reads_the_environment_and_shows_the_oauth_variant()
    {
        [$exit, $json] = $this->runJson('snapsbrew:mcp:connect', ['--client' => 'claude']);

        $this->assertSame(0, $exit);
        $data = $json['data'];
        $this->assertSame(['client', 'mcp_url', 'token_env', 'env', 'command', 'oauth', 'token'], array_keys($data));
        $this->assertSame('claude mcp add --transport http snapsbrew '.url('/mcp').' --header "Authorization: Bearer ${SNAPSBREW_TOKEN}"', $data['command']);
        $this->assertSame('claude mcp add --transport http snapsbrew '.url('/mcp').' && claude mcp login snapsbrew', $data['oauth']['command']);
        // The default redirect domains do not accept the localhost callback.
        $this->assertFalse($data['oauth']['available']);
        $this->assertNull($data['token']);
        $this->assertNull($data['env']);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_the_oauth_variant_is_available_when_localhost_is_a_redirect_domain()
    {
        config(['mcp.redirect_domains' => ['https://claude.ai', 'http://localhost']]);

        [, $json] = $this->runJson('snapsbrew:mcp:connect', ['--client' => 'claude']);

        $this->assertTrue($json['data']['oauth']['available']);
    }

    public function test_codex_returns_the_add_command_and_the_config_toml()
    {
        [, $json] = $this->runJson('snapsbrew:mcp:connect', ['--client' => 'codex']);

        $this->assertSame('codex mcp add snapsbrew --url '.url('/mcp').' --bearer-token-env-var SNAPSBREW_TOKEN', $json['data']['command']);
        $this->assertSame("[mcp_servers.snapsbrew]\nurl = \"".url('/mcp')."\"\nbearer_token_env_var = \"SNAPSBREW_TOKEN\"\n", $json['data']['config_toml']);
    }

    public function test_cursor_and_json_return_a_config_object()
    {
        [, $cursor] = $this->runJson('snapsbrew:mcp:connect', ['--client' => 'cursor']);
        [, $generic] = $this->runJson('snapsbrew:mcp:connect', ['--client' => 'json']);

        $this->assertSame(['url' => url('/mcp'), 'headers' => ['Authorization' => 'Bearer ${env:SNAPSBREW_TOKEN}']], $cursor['data']['config']['mcpServers']['snapsbrew']);
        $this->assertSame('~/.cursor/mcp.json', $cursor['data']['path']);
        $this->assertSame(['type' => 'http', 'url' => url('/mcp'), 'headers' => ['Authorization' => 'Bearer ${SNAPSBREW_TOKEN}']], $generic['data']['config']['mcpServers']['snapsbrew']);
    }

    public function test_token_new_puts_the_token_only_in_the_export_line()
    {
        $owner = User::factory()->create(['is_owner' => true]);

        [$exit, $json] = $this->runJson('snapsbrew:mcp:connect', ['--client' => 'claude', '--token' => 'new']);

        $this->assertSame(0, $exit);
        $token = $json['data']['token'];
        $this->assertSame('mcp-claude', $token['name']);
        $this->assertSame(['api_requests'], $token['abilities']);
        $this->assertSame($owner->id, $token['user']['id']);
        $this->assertSame('claude mcp add --transport http snapsbrew '.url('/mcp').' --header "Authorization: Bearer ${SNAPSBREW_TOKEN}"', $json['data']['command']);
        $this->assertStringNotContainsString($token['value'], $json['data']['command']);
        $this->assertSame("export SNAPSBREW_TOKEN='{$token['value']}'", $json['data']['env']);
    }

    public function test_token_new_keeps_the_token_out_of_the_cursor_config()
    {
        User::factory()->create(['is_owner' => true]);

        [, $json] = $this->runJson('snapsbrew:mcp:connect', ['--client' => 'cursor', '--token' => 'new']);

        $this->assertSame(['url' => url('/mcp'), 'headers' => ['Authorization' => 'Bearer ${env:SNAPSBREW_TOKEN}']], $json['data']['config']['mcpServers']['snapsbrew']);
        $this->assertSame("export SNAPSBREW_TOKEN='{$json['data']['token']['value']}'", $json['data']['env']);
    }

    public function test_text_output_with_a_new_token_prints_the_export_line_first()
    {
        User::factory()->create(['is_owner' => true]);

        $this->artisan('snapsbrew:mcp:connect', ['--client' => 'claude', '--token' => 'new'])
            ->expectsOutputToContain("export SNAPSBREW_TOKEN='")
            ->expectsOutputToContain('--header "Authorization: Bearer ${SNAPSBREW_TOKEN}"')
            ->assertSuccessful();
    }

    public function test_bad_input_exits_two()
    {
        [$exit, $json] = $this->runJson('snapsbrew:mcp:connect', ['--client' => 'vscode']);
        $this->assertSame(2, $exit);
        $this->assertSame('invalid_client', $json['errors'][0]['code']);

        [$exit, $json] = $this->runJson('snapsbrew:mcp:connect', ['--token' => 'abc']);
        $this->assertSame(2, $exit);
        $this->assertSame('invalid_token_option', $json['errors'][0]['code']);

        [$exit, $json] = $this->runJson('snapsbrew:mcp:connect', ['--token' => 'new']);
        $this->assertSame(2, $exit);
        $this->assertSame('user_not_found', $json['errors'][0]['code']);
    }

    public function test_it_exits_one_when_mcp_is_off()
    {
        config(['mcp.enabled' => false]);

        [$exit, $json] = $this->runJson('snapsbrew:mcp:connect');

        $this->assertSame(1, $exit);
        $this->assertSame('mcp_disabled', $json['errors'][0]['code']);
    }

    public function test_text_output_prints_the_command()
    {
        $this->artisan('snapsbrew:mcp:connect', ['--client' => 'codex'])
            ->expectsOutputToContain('codex mcp add snapsbrew --url')
            ->expectsOutputToContain('[mcp_servers.snapsbrew]')
            ->assertSuccessful();
    }
}
