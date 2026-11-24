<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MCP_ENABLED is read while the routes register, so this class boots every
 * application with `mcp.enabled` already false. The value is set right after
 * the configuration loads and before any provider registers a route. An
 * environment variable does not work here, because dotenv overwrites the
 * variables that it loaded itself when `.env` sets MCP_ENABLED.
 */
class McpDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication(): Application
    {
        $app = require __DIR__.'/../../../bootstrap/app.php';

        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app) {
            $app['config']->set('mcp.enabled', false);
        });

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_mcp_enabled_false_removes_the_mcp_and_oauth_routes()
    {
        $this->assertFalse(config('mcp.enabled'));
        $this->postJson('/mcp', [])->assertNotFound();
        $this->getJson('/.well-known/oauth-protected-resource')->assertNotFound();
        $this->getJson('/.well-known/oauth-authorization-server')->assertNotFound();
        $this->postJson('/oauth/register', [])->assertNotFound();
        $this->get('/oauth/authorize')->assertNotFound();
        $this->postJson('/oauth/token', [])->assertNotFound();
    }

    public function test_the_account_page_hides_the_mcp_setup_when_mcp_is_off()
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);

        $this->actingAs($user)->get('/account')->assertOk()->assertDontSee('<mcp-connect', false);
    }
}
