<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function adminRoutes(): array
    {
        return [
            'users list' => ['get', '/admin/users'],
            'settings page' => ['get', '/admin/settings'],
            'settings update' => ['put', '/admin/settings'],
            'deactivate' => ['post', '/admin/users/{user}/deactivation'],
            'reactivate' => ['delete', '/admin/users/{user}/deactivation'],
            'delete user' => ['delete', '/admin/users/{user}'],
        ];
    }

    #[DataProvider('adminRoutes')]
    public function test_a_guest_gets_a_404(string $method, string $uri)
    {
        $target = User::factory()->create();

        $this->{$method}(str_replace('{user}', $target->id, $uri), ['registration_mode' => 'open'])
            ->assertNotFound();
    }

    #[DataProvider('adminRoutes')]
    public function test_a_user_that_is_not_the_owner_gets_a_404(string $method, string $uri)
    {
        $target = User::factory()->create();

        // The `admin` product role is not the owner of the instance.
        $this->actingAs(User::factory()->admin()->create())
            ->{$method}(str_replace('{user}', $target->id, $uri), ['registration_mode' => 'open'])
            ->assertNotFound();

        $this->assertNull($target->fresh()->deactivated_at);
        $this->assertDatabaseMissing('instance_settings', ['key' => 'registration_mode']);
    }

    public function test_the_owner_opens_both_admin_pages()
    {
        $owner = User::factory()->create(['is_owner' => true]);

        $this->actingAs($owner)->get('/admin/users')->assertOk();
        $this->actingAs($owner)->get('/admin/settings')->assertOk();
    }

    public function test_only_the_owner_sees_the_admin_link_in_the_nav()
    {
        $owner = User::factory()->create(['is_owner' => true]);
        $user = User::factory()->create();

        $this->actingAs($owner)->get('/account')->assertSee('href="/admin/users"', false);
        $this->actingAs($user)->get('/account')->assertDontSee('href="/admin/users"', false);
    }
}
