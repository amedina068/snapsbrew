<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The app loads no analytics script. An operator's .env can still hold the
 * old Plausible variables, so the test sets them before the app boots.
 */
class AnalyticsScriptTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_VARIABLES = [
        'PLAUSIBLE_DOMAIN' => 'images.example.org',
        'PLAUSIBLE_SCRIPT_URL' => 'https://plausible.io/js/script.js',
    ];

    protected function setUp(): void
    {
        foreach (self::OLD_VARIABLES as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            foreach (array_keys(self::OLD_VARIABLES) as $name) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            }
        }
    }

    public function test_no_page_loads_plausible_when_the_old_variables_are_set()
    {
        User::factory()->create(); // The entry pages redirect to /setup until the first account exists.
        config(['snapsbrew.registration_enabled' => true]);

        $this->get('/login')->assertOk()->assertDontSee('plausible', false);
        $this->get('/register')->assertOk()->assertDontSee('plausible', false);
        $this->get('/documentation')->assertOk()->assertDontSee('plausible', false);

        $user = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertDontSee('plausible', false);
    }
}
