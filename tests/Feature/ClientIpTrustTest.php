<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The per IP limits are only as good as the client IP. A proxy setting that
 * trusts every caller lets a client choose its own IP, so the docs must name
 * the proxy, the compose files must not publish the app on every interface,
 * and the login must have one limit that no IP can change.
 */
class ClientIpTrustTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_email_gets_a_limited_number_of_login_attempts_from_all_ips_together()
    {
        $user = User::factory()->create();

        // The email plus IP limit allows 5 attempts each minute. Change the IP
        // after 4 attempts, so that only the email limit can stop the 11th.
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $ip = '203.0.113.'.(intdiv($attempt, 4) + 1);

            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
                ->assertStatus(302);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(429);
    }

    public function test_the_email_limit_ignores_the_case_of_the_email()
    {
        User::factory()->create(['email' => 'person@example.com']);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $email = $attempt % 2 === 0 ? 'person@example.com' : 'PERSON@example.com';

            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.($attempt + 1)])
                ->post('/login', ['email' => $email, 'password' => 'wrong-password'])
                ->assertStatus(302);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->post('/login', ['email' => 'Person@Example.com', 'password' => 'wrong-password'])
            ->assertStatus(429);
    }

    public static function composeFiles(): array
    {
        return [
            'build' => ['docker-compose.yml'],
            'image' => ['docker-compose.image.yml'],
        ];
    }

    #[DataProvider('composeFiles')]
    public function test_the_compose_file_publishes_the_app_on_the_loopback_interface_by_default(string $file)
    {
        $compose = file_get_contents(base_path($file));

        $this->assertStringContainsString('- "${APP_BIND:-127.0.0.1}:${APP_PORT:-8080}:8080"', $compose);
        $this->assertDoesNotMatchRegularExpression('/-\s*"\$\{APP_PORT:-8080\}:8080"/', $compose);
    }

    public static function proxyDocs(): array
    {
        return [
            'readme' => ['README.md'],
            'env example' => ['.env.example'],
            'docker env example' => ['.env.docker.example'],
            'config' => ['config/trustedproxy.php'],
        ];
    }

    #[DataProvider('proxyDocs')]
    public function test_the_docs_do_not_recommend_trusting_every_proxy(string $file)
    {
        $text = file_get_contents(base_path($file));

        $this->assertDoesNotMatchRegularExpression('/TRUSTED_PROXIES=\*/', $text);
        $this->assertDoesNotMatchRegularExpression('/Set "\*"|Use "\*"/', $text);
    }
}
