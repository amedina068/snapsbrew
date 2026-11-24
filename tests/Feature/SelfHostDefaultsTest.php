<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Horizon\Horizon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A self-hosted instance starts from `.env.example`. Its defaults must be safe
 * for a public server, and Horizon must stay closed in every environment.
 */
class SelfHostDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private function horizonAllows(?User $user): bool
    {
        // The gate reads the user of the auth guard, as a real request does.
        $this->app['auth']->forgetGuards();

        if ($user) {
            $this->actingAs($user);
        }

        $request = Request::create('/horizon');
        $request->setUserResolver(fn () => $user);

        return Horizon::check($request);
    }

    public function test_horizon_is_closed_to_a_guest_and_a_customer_in_the_local_environment()
    {
        $this->app['env'] = 'local';

        $this->assertFalse($this->horizonAllows(null));
        $this->assertFalse($this->horizonAllows(User::factory()->create()));
        $this->assertTrue($this->horizonAllows(User::factory()->create(['is_owner' => true])));
    }

    public function test_the_horizon_page_returns_403_to_a_customer_in_the_local_environment()
    {
        $this->app['env'] = 'local';

        $this->actingAs(User::factory()->create())->get('/horizon')->assertForbidden();
    }

    public function test_the_env_example_defaults_to_production_without_debug()
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^APP_ENV=production$/m', $example);
        $this->assertMatchesRegularExpression('/^APP_DEBUG=false$/m', $example);
    }

    public static function secureCookieCases(): array
    {
        return [
            'https url, no setting' => ['https://snaps.example.test', null, true],
            'http url, no setting' => ['http://localhost:8000', null, false],
            'https url, setting false' => ['https://snaps.example.test', 'false', false],
            'http url, setting true' => ['http://localhost:8000', 'true', true],
        ];
    }

    #[DataProvider('secureCookieCases')]
    public function test_the_session_cookie_is_secure_by_default_when_the_app_url_is_https(string $url, ?string $setting, bool $secure)
    {
        $keys = ['APP_URL' => $url, 'SESSION_SECURE_COOKIE' => $setting];
        $saved = [];

        foreach ($keys as $key => $value) {
            $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            $this->setEnv($key, $value);
        }

        try {
            $config = require base_path('config/session.php');

            $this->assertSame($secure, $config['secure']);
        } finally {
            foreach ($saved as $key => [$env, $server, $putenv]) {
                $this->setEnv($key, $env ?? $server ?? ($putenv === false ? null : $putenv));
            }
        }
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            return;
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("$key=$value");
    }
}
