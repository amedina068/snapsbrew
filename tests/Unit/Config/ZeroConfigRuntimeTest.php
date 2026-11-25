<?php

namespace Tests\Unit\Config;

use Laravel\Horizon\HorizonServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Docker image starts with no variables. Each config file must derive a
 * working value from APP_URL, from APP_DOMAIN, or from a default. An install
 * that sets its own values, for example the production .env of the owner,
 * must get the same config as before.
 */
class ZeroConfigRuntimeTest extends TestCase
{
    private const KEYS = [
        'APP_URL', 'APP_DOMAIN', 'APP_KEY', 'APP_KEY_FILE', 'TRUSTED_PROXIES',
        'SESSION_SECURE_COOKIE', 'SANCTUM_STATEFUL_DOMAINS', 'QUEUE_CONNECTION',
        'DB_BUSY_TIMEOUT', 'DB_JOURNAL_MODE', 'DB_SYNCHRONOUS', 'DB_TRANSACTION_MODE',
    ];

    /** @var array<string, array{0: mixed, 1: mixed, 2: string|false}> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::KEYS as $key) {
            $this->saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            $this->setEnv($key, null);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => [$env, $server, $putenv]) {
            $this->setEnv($key, $env ?? $server ?? ($putenv === false ? null : $putenv));
        }

        parent::tearDown();
    }

    public static function appUrlCases(): array
    {
        return [
            'no setting' => [null, null, 'http://localhost:8080'],
            'domain only' => [null, 'snaps.example.test', 'https://snaps.example.test'],
            'url only' => ['https://images.example.test', null, 'https://images.example.test'],
            'url wins over domain' => ['http://localhost:8000', 'snaps.example.test', 'http://localhost:8000'],
        ];
    }

    #[DataProvider('appUrlCases')]
    public function test_the_app_url_comes_from_app_url_then_app_domain_then_the_default(?string $url, ?string $domain, string $expected)
    {
        $this->setEnv('APP_URL', $url);
        $this->setEnv('APP_DOMAIN', $domain);

        $this->assertSame($expected, $this->configFile('app')['url']);
    }

    public function test_a_domain_makes_the_session_cookie_secure()
    {
        $this->setEnv('APP_DOMAIN', 'snaps.example.test');

        $this->assertTrue($this->configFile('session')['secure']);
    }

    public function test_a_domain_is_a_stateful_sanctum_domain()
    {
        $this->setEnv('APP_DOMAIN', 'snaps.example.test');

        $this->assertContains('snaps.example.test', $this->configFile('sanctum')['stateful']);
    }

    public function test_a_domain_trusts_the_caddy_of_the_container()
    {
        $this->setEnv('APP_DOMAIN', 'snaps.example.test');

        $this->assertSame('127.0.0.1', $this->configFile('trustedproxy')['proxies']);
    }

    public function test_trusted_proxies_win_over_the_domain_default()
    {
        $this->setEnv('APP_DOMAIN', 'snaps.example.test');
        $this->setEnv('TRUSTED_PROXIES', '10.0.0.1');

        $this->assertSame('10.0.0.1', $this->configFile('trustedproxy')['proxies']);
    }

    public function test_no_proxy_is_trusted_without_a_setting()
    {
        $this->assertNull($this->configFile('trustedproxy')['proxies']);
    }

    public function test_the_app_key_comes_from_the_key_file_when_app_key_is_empty()
    {
        $file = tempnam(sys_get_temp_dir(), 'app_key');
        file_put_contents($file, 'base64:'.base64_encode(str_repeat('k', 32))."\n");

        try {
            $this->setEnv('APP_KEY_FILE', $file);

            $this->assertSame('base64:'.base64_encode(str_repeat('k', 32)), $this->configFile('app')['key']);

            $this->setEnv('APP_KEY', 'base64:'.base64_encode(str_repeat('e', 32)));

            $this->assertSame('base64:'.base64_encode(str_repeat('e', 32)), $this->configFile('app')['key']);
        } finally {
            unlink($file);
        }
    }

    public function test_a_missing_key_file_gives_no_key()
    {
        $this->setEnv('APP_KEY_FILE', sys_get_temp_dir().'/snapsbrew-no-such-key-file');

        $this->assertNull($this->configFile('app')['key']);
    }

    public function test_sqlite_uses_wal_a_busy_timeout_and_immediate_transactions()
    {
        $sqlite = $this->configFile('database')['connections']['sqlite'];

        $this->assertSame(5000, $sqlite['busy_timeout']);
        $this->assertSame('wal', $sqlite['journal_mode']);
        $this->assertSame('normal', $sqlite['synchronous']);
        $this->assertSame('IMMEDIATE', $sqlite['transaction_mode']);
    }

    public function test_horizon_loads_only_with_the_redis_queue()
    {
        $horizon = [HorizonServiceProvider::class, \App\Providers\HorizonServiceProvider::class];

        $this->setEnv('QUEUE_CONNECTION', 'redis');
        $this->assertEmpty(array_diff($horizon, $this->configFile('app')['providers']));

        foreach (['database', 'sync', null] as $queue) {
            $this->setEnv('QUEUE_CONNECTION', $queue);
            $this->assertEmpty(array_intersect($horizon, $this->configFile('app')['providers']), "Horizon loaded with the queue {$queue}.");
        }
    }

    public function test_composer_does_not_discover_horizon()
    {
        $composer = json_decode(file_get_contents(base_path('composer.json')), true);

        $this->assertContains('laravel/horizon', $composer['extra']['laravel']['dont-discover']);
    }

    public function test_the_database_queue_gives_a_job_more_time_than_the_longest_job_timeout()
    {
        // A creative package item can run for 120 seconds, plus a backoff of 45.
        $this->assertGreaterThanOrEqual(180, $this->configFile('queue')['connections']['database']['retry_after']);
    }

    private function configFile(string $name): array
    {
        return require base_path("config/{$name}.php");
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
        putenv("{$key}={$value}");
    }
}
