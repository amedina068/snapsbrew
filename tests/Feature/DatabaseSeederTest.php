<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `php artisan migrate:fresh --seed` gives a contributor an account that can
 * sign in and open every area. Before, the seeded admin was not an owner, so
 * /admin and /horizon answered 403, and the setup page was closed because the
 * users table was not empty.
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeded_admin_is_an_owner_with_the_documented_password(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertTrue($admin->isOwner());
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue(Hash::check('password', $admin->password));
        $this->assertNotNull($admin->email_verified_at);
    }

    public function test_the_seed_prints_the_sign_in_details(): void
    {
        $this->artisan('db:seed')
            ->expectsOutputToContain('admin@example.com')
            ->assertSuccessful();
    }
}
