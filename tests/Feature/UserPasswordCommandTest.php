<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sets_the_password_from_the_prompt()
    {
        $user = User::factory()->create(['email' => 'lost@example.com']);

        $this->artisan('snapsbrew:user:password', ['email' => 'lost@example.com'])
            ->expectsQuestion('New password', 'a-long-password-1')
            ->expectsQuestion('Type the new password again', 'a-long-password-1')
            ->expectsOutputToContain('The password of lost@example.com changed.')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('a-long-password-1', $user->fresh()->password));
    }

    public function test_it_refuses_two_different_passwords_from_the_prompt()
    {
        $user = User::factory()->create(['email' => 'lost@example.com']);
        $before = $user->password;

        $this->artisan('snapsbrew:user:password', ['email' => 'lost@example.com'])
            ->expectsQuestion('New password', 'a-long-password-1')
            ->expectsQuestion('Type the new password again', 'a-long-password-2')
            ->expectsOutputToContain('The two passwords are not the same.')
            ->assertFailed();

        $this->assertSame($before, $user->fresh()->password);
    }

    public function test_it_sets_the_password_from_the_option()
    {
        $user = User::factory()->create(['email' => 'lost@example.com']);

        $this->artisan('snapsbrew:user:password', [
            'email' => 'lost@example.com',
            '--password' => 'a-long-password-1',
        ])->assertSuccessful();

        $this->assertTrue(Hash::check('a-long-password-1', $user->fresh()->password));
    }

    public function test_it_generates_a_password_that_authenticates_the_user()
    {
        $user = User::factory()->create(['email' => 'lost@example.com']);

        $exitCode = Artisan::call('snapsbrew:user:password', [
            'email' => 'lost@example.com',
            '--generate' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, preg_match('/New password: (\S+)/', Artisan::output(), $matches));
        $this->assertGreaterThanOrEqual(20, strlen($matches[1]));
        $this->assertTrue(Hash::check($matches[1], $user->fresh()->password));
    }

    public function test_it_refuses_a_password_that_breaks_the_rules()
    {
        $user = User::factory()->create(['email' => 'lost@example.com']);
        $before = $user->password;

        $this->artisan('snapsbrew:user:password', [
            'email' => 'lost@example.com',
            '--password' => 'short',
        ])->expectsOutputToContain('at least 8 characters')->assertFailed();

        $this->assertSame($before, $user->fresh()->password);
    }

    public function test_it_refuses_an_unknown_email()
    {
        $this->artisan('snapsbrew:user:password', [
            'email' => 'nobody@example.com',
            '--password' => 'a-long-password-1',
        ])->expectsOutputToContain('No user has the email nobody@example.com.')->assertFailed();
    }

    public function test_it_refuses_the_password_and_the_generate_options_together()
    {
        $user = User::factory()->create(['email' => 'lost@example.com']);
        $before = $user->password;

        $this->artisan('snapsbrew:user:password', [
            'email' => 'lost@example.com',
            '--password' => 'a-long-password-1',
            '--generate' => true,
        ])->assertFailed();

        $this->assertSame($before, $user->fresh()->password);
    }

    public function test_it_rotates_the_remember_token()
    {
        $user = User::factory()->create(['email' => 'lost@example.com', 'remember_token' => 'old-token']);

        $this->artisan('snapsbrew:user:password', [
            'email' => 'lost@example.com',
            '--password' => 'a-long-password-1',
        ])->expectsOutputToContain('"Remember me" logins ended')->assertSuccessful();

        $this->assertNotSame('old-token', $user->fresh()->remember_token);
    }

    public function test_it_ends_the_database_sessions_of_the_user()
    {
        config(['session.driver' => 'database']);
        $this->createSessionsTable();

        $user = User::factory()->create(['email' => 'lost@example.com']);
        $other = User::factory()->create();

        DB::table('sessions')->insert([
            ['id' => 'a', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'b', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'c', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()],
        ]);

        $this->artisan('snapsbrew:user:password', [
            'email' => 'lost@example.com',
            '--password' => 'a-long-password-1',
        ])->expectsOutputToContain('Ended 2 web sessions')->assertSuccessful();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());
    }

    public function test_it_explains_the_session_effect_for_other_drivers()
    {
        config(['session.driver' => 'file']);
        User::factory()->create(['email' => 'lost@example.com']);

        $this->artisan('snapsbrew:user:password', [
            'email' => 'lost@example.com',
            '--password' => 'a-long-password-1',
        ])->expectsOutputToContain('The "file" session driver')->assertSuccessful();
    }

    public function test_it_resets_two_factor_authentication()
    {
        $user = User::factory()->create(['email' => 'lost@example.com']);
        $user->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt('[]'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->artisan('snapsbrew:user:password', [
            'email' => 'lost@example.com',
            '--password' => 'a-long-password-1',
            '--reset-two-factor' => true,
        ])->expectsOutputToContain('Two-factor authentication is off')->assertSuccessful();

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_it_keeps_two_factor_authentication_without_the_option()
    {
        $user = User::factory()->create(['email' => 'lost@example.com']);
        $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();

        $this->artisan('snapsbrew:user:password', [
            'email' => 'lost@example.com',
            '--password' => 'a-long-password-1',
        ])->assertSuccessful();

        $this->assertNotNull($user->fresh()->two_factor_secret);
    }

    private function createSessionsTable(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }
}
