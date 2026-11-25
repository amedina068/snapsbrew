<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Horizon\HorizonServiceProvider;
use Tests\TestCase;

/**
 * The Docker image runs the database queue and no Horizon. Horizon then has
 * no route and no command. The Redis install keeps both (OwnerTest and
 * SelfHostDefaultsTest load Horizon and prove the gate).
 */
class HorizonOptOutTest extends TestCase
{
    use RefreshDatabase;

    public function test_horizon_is_not_loaded_without_the_redis_queue()
    {
        $this->assertNotSame('redis', config('queue.default'));
        $this->assertFalse($this->app->providerIsLoaded(HorizonServiceProvider::class));
    }

    public function test_the_horizon_page_does_not_exist_without_the_redis_queue()
    {
        $this->actingAs(User::factory()->create(['is_owner' => true]))->get('/horizon')->assertNotFound();
    }

    public function test_the_horizon_command_does_not_exist_without_the_redis_queue()
    {
        $this->assertArrayNotHasKey('horizon', Artisan::all());
    }
}
