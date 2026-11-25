<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\InstanceSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\RunsJsonCommands;
use Tests\TestCase;

/**
 * `--json` on the commands that existed before the agent-first contract:
 * `snapsbrew:setup-url`, `snapsbrew:user:list` and `snapsbrew:user:password`.
 */
class AgentCommandsJsonTest extends TestCase
{
    use RefreshDatabase, RunsJsonCommands;

    public function test_setup_url_returns_the_url_while_setup_is_needed()
    {
        [$exit, $json] = $this->runJson('snapsbrew:setup-url');

        $this->assertSame(0, $exit);
        $this->assertSame(['setup_needed' => true, 'url' => InstanceSetup::url()], $json['data']);
    }

    public function test_setup_url_returns_null_after_an_account_exists()
    {
        User::factory()->create();

        [$exit, $json] = $this->runJson('snapsbrew:setup-url');

        $this->assertSame(0, $exit);
        $this->assertSame(['setup_needed' => false, 'url' => null], $json['data']);
    }

    public function test_setup_url_text_output_stays_one_line_with_the_url()
    {
        // The Docker entrypoint reads this line.
        $this->artisan('snapsbrew:setup-url')->expectsOutput(InstanceSetup::url())->assertSuccessful();
    }

    public function test_user_list_returns_every_user_with_a_stable_shape()
    {
        $owner = User::factory()->create(['name' => 'Olive Owner', 'email' => 'owner@example.com', 'is_owner' => true, 'company_id' => Company::factory()->create(['name' => 'Olive Studio'])]);
        User::factory()->create(['email' => 'member@example.com', 'deactivated_at' => now(), 'company_id' => $owner->company_id]);

        [$exit, $json] = $this->runJson('snapsbrew:user:list');

        $this->assertSame(0, $exit);
        $users = $json['data']['users'];
        $this->assertTrue(array_is_list($users));
        $this->assertCount(2, $users);
        $this->assertSame(['id', 'name', 'email', 'company', 'company_role', 'role', 'is_owner', 'deactivated', 'email_verified', 'created_at'], array_keys($users[0]));
        $this->assertSame($owner->id, $users[0]['id']);
        $this->assertSame(['id' => $owner->company_id, 'name' => 'Olive Studio'], $users[0]['company']);
        $this->assertTrue($users[0]['is_owner']);
        $this->assertSame('owner', $users[0]['company_role']);
        $this->assertSame('member', $users[1]['company_role']);
        $this->assertTrue($users[1]['deactivated']);
    }

    public function test_user_list_returns_an_empty_list_when_nothing_matches()
    {
        [$exit, $json] = $this->runJson('snapsbrew:user:list', ['--search' => 'nobody']);

        $this->assertSame(0, $exit);
        $this->assertSame([], $json['data']['users']);
    }

    public function test_user_password_generate_returns_the_password_one_time()
    {
        $user = User::factory()->create(['email' => 'member@example.com']);

        [$exit, $json] = $this->runJson('snapsbrew:user:password', ['email' => 'member@example.com', '--generate' => true]);

        $this->assertSame(0, $exit);
        $data = $json['data'];
        $this->assertSame(['user', 'password_changed', 'generated_password', 'remember_token_rotated', 'web_sessions_ended', 'two_factor_reset'], array_keys($data));
        $this->assertTrue(Hash::check($data['generated_password'], $user->fresh()->password));
        $this->assertFalse($data['two_factor_reset']);
    }

    public function test_user_password_with_a_given_password_does_not_echo_it()
    {
        User::factory()->create(['email' => 'member@example.com']);

        [$exit, $json, $raw] = $this->runJson('snapsbrew:user:password', ['email' => 'member@example.com', '--password' => 'a-long-password-1']);

        $this->assertSame(0, $exit);
        $this->assertNull($json['data']['generated_password']);
        $this->assertStringNotContainsString('a-long-password-1', $raw);
    }

    public function test_user_password_bad_input_exits_two()
    {
        User::factory()->create(['email' => 'member@example.com']);

        $cases = [
            [['email' => 'nobody@example.com', '--generate' => true], 'user_not_found'],
            [['email' => 'member@example.com', '--generate' => true, '--password' => 'a-long-password-1'], 'conflicting_options'],
            // JSON mode never prompts.
            [['email' => 'member@example.com'], 'missing_option'],
            [['email' => 'member@example.com', '--password' => 'short'], 'invalid_password'],
        ];

        foreach ($cases as [$parameters, $code]) {
            [$exit, $json] = $this->runJson('snapsbrew:user:password', $parameters);

            $this->assertSame(2, $exit, $code);
            $this->assertSame($code, $json['errors'][0]['code']);
        }
    }
}
