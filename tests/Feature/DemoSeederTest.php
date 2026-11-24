<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * `DatabaseSeeder` creates demo users with the factory password and the
 * address admin@example.com. The repository is public, so these credentials
 * are public. The seeder must not run in production, also with `--force`.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_seeder_refuses_to_run_in_production()
    {
        $this->app['env'] = 'production';

        try {
            (new DatabaseSeeder)->setContainer($this->app)->__invoke();
            $this->fail('The demo seeder ran in production.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('production', $exception->getMessage());
        }

        $this->assertSame(0, User::query()->count());
    }

    public function test_db_seed_with_force_does_not_create_the_demo_admin_in_production()
    {
        $this->app['env'] = 'production';

        try {
            $this->artisan('db:seed', ['--force' => true]);
        } catch (RuntimeException) {
            // The command reports the refusal.
        }

        $this->assertFalse(User::query()->where('email', 'admin@example.com')->exists());
    }
}
