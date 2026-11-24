<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Fortify gives a limiter to the login and the two-factor routes only. The
 * other routes that take a password or send a mail need their own limits:
 * a guest can send reset mails or create accounts without end, and a person
 * with a stolen session can guess the password behind the confirm step.
 */
class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    public function test_a_password_reset_request_is_limited_for_each_ip()
    {
        for ($i = 0; $i < 5; $i++) {
            $this->fromIp('203.0.113.10')->post('/forgot-password', ['email' => "person{$i}@example.test"])
                ->assertStatus(302);
        }

        $this->fromIp('203.0.113.10')->post('/forgot-password', ['email' => 'person9@example.test'])
            ->assertStatus(429);

        $this->fromIp('203.0.113.11')->post('/forgot-password', ['email' => 'person9@example.test'])
            ->assertStatus(302);
    }

    public function test_a_password_reset_request_is_limited_for_each_email_from_all_ips()
    {
        for ($i = 0; $i < 5; $i++) {
            $this->fromIp("203.0.113.{$i}")->post('/forgot-password', ['email' => 'Victim@Example.test'])
                ->assertStatus(302);
        }

        $this->fromIp('203.0.113.99')->post('/forgot-password', ['email' => 'victim@example.test'])
            ->assertStatus(429);
    }

    public function test_a_registration_is_limited_for_each_ip()
    {
        // An instance with no user sends `/register` to the setup page.
        User::factory()->create();
        Registration::setMode(Registration::OPEN);

        for ($i = 0; $i < 5; $i++) {
            $this->fromIp('203.0.113.20')->post('/register', ['email' => 'not-an-email', 'password' => 'x'])
                ->assertStatus(302);
        }

        $this->fromIp('203.0.113.20')->post('/register', ['email' => 'not-an-email', 'password' => 'x'])
            ->assertStatus(429);
    }

    public function test_a_password_reset_is_limited_for_each_ip()
    {
        for ($i = 0; $i < 5; $i++) {
            $this->fromIp('203.0.113.30')->post('/reset-password', [
                'token' => "guess-{$i}", 'email' => 'person@example.test', 'password' => 'x', 'password_confirmation' => 'x',
            ])->assertStatus(302);
        }

        $this->fromIp('203.0.113.30')->post('/reset-password', [
            'token' => 'guess-9', 'email' => 'person@example.test', 'password' => 'x', 'password_confirmation' => 'x',
        ])->assertStatus(429);
    }

    public function test_the_password_confirmation_is_limited_for_each_user()
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->fromIp("203.0.113.{$i}")
                ->post('/user/confirm-password', ['password' => "guess-{$i}"])
                ->assertStatus(302);
        }

        $this->actingAs($user)->fromIp('203.0.113.99')
            ->post('/user/confirm-password', ['password' => 'guess-9'])
            ->assertStatus(429);
    }

    public function test_the_password_change_is_limited_for_each_user()
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->fromIp("203.0.113.{$i}")
                ->put('/user/password', ['current_password' => "guess-{$i}", 'password' => 'x', 'password_confirmation' => 'x'])
                ->assertStatus(302);
        }

        $this->actingAs($user)->fromIp('203.0.113.99')
            ->put('/user/password', ['current_password' => 'guess-9', 'password' => 'x', 'password_confirmation' => 'x'])
            ->assertStatus(429);
    }

    public function test_a_page_view_is_not_limited_by_these_limiters()
    {
        for ($i = 0; $i < 10; $i++) {
            $this->fromIp('203.0.113.40')->get('/forgot-password')->assertOk();
        }
    }
}
