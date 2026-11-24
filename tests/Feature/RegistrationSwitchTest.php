<?php

namespace Tests\Feature;

use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_is_closed_by_default()
    {
        $this->assertFalse(config('snapsbrew.registration_enabled'));
    }

    public function test_a_closed_instance_hides_the_form_and_refuses_a_new_account()
    {
        User::factory()->create(); // The entry pages redirect to /setup until the first account exists.
        config(['snapsbrew.registration_enabled' => false]);

        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'mallory@example.com']);
    }

    public function test_a_closed_instance_still_accepts_a_pending_invitation()
    {
        config(['snapsbrew.registration_enabled' => false]);
        $invitation = CompanyInvitation::factory()->create(['email' => 'teammate@example.com']);

        $this->get('/register?invitation='.$invitation->token)->assertOk();
        $this->post('/register', [
            'name' => 'Teammate',
            'email' => 'teammate@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invitation_token' => $invitation->token,
        ])->assertRedirect();

        $this->assertSame($invitation->company_id, User::where('email', 'teammate@example.com')->value('company_id'));
    }

    public function test_an_open_instance_accepts_a_new_account()
    {
        User::factory()->create(); // The entry pages redirect to /setup until the first account exists.
        config(['snapsbrew.registration_enabled' => true]);

        $this->get('/register')->assertOk();
        $this->post('/register', [
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);
    }

    public function test_the_login_page_links_to_registration_only_when_it_is_open()
    {
        User::factory()->create(); // The entry pages redirect to /setup until the first account exists.
        config(['snapsbrew.registration_enabled' => false]);
        $this->get('/login')->assertDontSee('href="/register"', false);

        config(['snapsbrew.registration_enabled' => true]);
        $this->get('/login')->assertSee('href="/register"', false);
    }
}
