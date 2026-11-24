<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_sends_a_guest_to_the_login_page()
    {
        User::factory()->create(); // The entry pages redirect to /setup until the first account exists.
        $this->get('/')->assertRedirect('login');
    }

    public function test_the_home_page_sends_a_signed_in_user_to_the_dashboard()
    {
        $this->actingAs(User::factory()->create())->get('/')->assertRedirect('dashboard');
    }

    public function test_the_marketing_pages_are_gone()
    {
        foreach (['/pricing', '/product', '/customers', '/guides', '/terms', '/privacy', '/tools', '/tools/certificate-creator'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }
}
