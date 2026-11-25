<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WithPassportKeys;
use Tests\TestCase;

/**
 * MySQL compares emails without case (utf8mb4_unicode_ci). SQLite compares
 * them with case. The app stores each email in lowercase and lowercases each
 * email that a person types, so both databases find the same user.
 */
class EmailCaseTest extends TestCase
{
    use RefreshDatabase, WithPassportKeys;

    public function test_the_user_model_stores_the_email_in_lowercase()
    {
        $user = User::factory()->create(['email' => 'Mixed.Case@Example.COM']);

        $this->assertSame('mixed.case@example.com', $user->fresh()->email);
    }

    public function test_a_user_can_log_in_with_the_email_in_another_case()
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $response = $this->post('/login', [
            'email' => 'JANE@Example.com',
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(RouteServiceProvider::HOME);
    }

    public function test_registration_rejects_an_email_that_differs_only_in_case()
    {
        config(['snapsbrew.registration_enabled' => true]);
        User::factory()->create(['email' => 'jane@example.com']);

        $response = $this->post('/register', [
            'name' => 'Jane Again',
            'email' => 'Jane@Example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertSame(1, User::query()->count());
    }

    public function test_the_install_command_stores_the_owner_email_in_lowercase()
    {
        $this->artisan('snapsbrew:install', [
            '--name' => 'Olive Owner',
            '--email' => 'Owner@Example.com',
            '--password' => 'a-long-password-1',
            '--no-starter-templates' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'owner@example.com', 'is_owner' => true]);
    }

    public function test_the_promote_option_finds_the_user_in_any_case()
    {
        User::factory()->create(['email' => 'staff@example.com']);

        $this->artisan('snapsbrew:install', ['--promote' => 'Staff@Example.com'])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'staff@example.com', 'is_owner' => true]);
    }

    public function test_the_password_command_finds_the_user_in_any_case()
    {
        User::factory()->create(['email' => 'staff@example.com']);

        $this->artisan('snapsbrew:user:password', [
            'email' => 'STAFF@example.com',
            '--password' => 'a-new-long-password-1',
        ])->assertSuccessful();
    }
}
