<?php

namespace Tests\Feature;

use App\Http\Kernel;
use App\Http\Middleware\TrustHosts;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The password reset link takes its host from the URL generator. Without a
 * trusted host list, a client sets the Host header of the reset request, and
 * the mail sends the token to the host of that client.
 */
class TrustedHostTest extends TestCase
{
    use RefreshDatabase;

    private const APP = 'https://app.snapsbrew.test';

    private const PREVIOUS = 'https://snapsbrew.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => self::APP,
            'app.previous_urls' => [self::PREVIOUS],
        ]);
    }

    public function test_the_middleware_runs_on_every_request()
    {
        $this->assertTrue($this->app->make(Kernel::class)->hasMiddleware(TrustHosts::class));
    }

    public function test_a_request_for_another_host_is_refused_and_sends_no_mail()
    {
        Notification::fake();
        $user = User::factory()->create();

        $response = $this->post('https://attacker.example/forgot-password', ['email' => $user->email]);

        $response->assertStatus(400);
        Notification::assertNothingSent();
    }

    public function test_a_subdomain_of_the_app_host_is_refused()
    {
        $this->get('https://evil.app.snapsbrew.test/forgot-password')->assertStatus(400);
    }

    public function test_the_app_host_is_trusted()
    {
        $this->get(self::APP.'/forgot-password')->assertOk();
    }

    public function test_the_container_health_check_on_a_loopback_host_still_answers()
    {
        $this->get('http://localhost/up')->assertOk();
        $this->get('http://127.0.0.1/up')->assertOk();
    }

    public function test_a_link_built_on_a_loopback_host_names_the_app_host()
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('http://localhost/forgot-password', ['email' => $user->email])->assertRedirect();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            return str_starts_with($notification->toMail($user)->actionUrl, self::APP.'/reset-password/');
        });
    }

    public function test_a_previous_host_is_trusted_and_the_reset_link_names_the_app_host()
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(self::PREVIOUS.'/forgot-password', ['email' => $user->email])->assertRedirect();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, self::APP.'/reset-password/');
        });
    }
}
