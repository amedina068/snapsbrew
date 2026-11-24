<?php

namespace Tests\Feature;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_up_answers_200_without_a_session()
    {
        $this->get('/up')->assertOk()->assertCookieMissing(config('session.cookie'));
    }

    public function test_up_answers_500_when_a_health_listener_fails()
    {
        Event::listen(DiagnosingHealth::class, fn () => throw new \RuntimeException('database is down'));

        $this->get('/up')->assertStatus(500);
    }

    public function test_the_proxy_list_comes_from_trusted_proxies()
    {
        $this->assertArrayHasKey('proxies', config('trustedproxy'));
    }
}
