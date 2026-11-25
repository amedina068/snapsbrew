<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Instance\LoginLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginLinkTest extends TestCase
{
    use RefreshDatabase;

    private function path(string $url): string
    {
        return parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
    }

    public function test_a_link_signs_the_user_in_and_works_one_time()
    {
        $user = User::factory()->create();
        $path = $this->path(LoginLink::create($user));

        $this->get($path)->assertRedirect(config('fortify.home'));
        $this->assertAuthenticatedAs($user);

        auth()->logout();
        $this->get($path)->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_the_signature_is_relative_so_another_host_name_works()
    {
        config(['app.url' => 'http://localhost:8080']);
        $user = User::factory()->create();
        $link = LoginLink::create($user);

        $this->assertStringStartsWith('http://localhost:8080/login/link/', $link);
        $this->get('http://127.0.0.1:8080'.$this->path($link))->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_changed_link_is_refused()
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $path = $this->path(LoginLink::create($user));

        $this->get(str_replace("/login/link/{$user->id}/", "/login/link/{$other->id}/", $path))->assertRedirect('/login');
        $this->get($path.'x')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_an_expired_link_is_refused()
    {
        $user = User::factory()->create();
        $path = $this->path(LoginLink::create($user));

        $this->travel(LoginLink::MINUTES + 1)->minutes();

        $this->get($path)->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_a_deactivated_user_cannot_use_a_link()
    {
        $user = User::factory()->create(['deactivated_at' => now()]);

        $this->get($this->path(LoginLink::create($user)))->assertRedirect('/login');
        $this->assertGuest();
    }
}
