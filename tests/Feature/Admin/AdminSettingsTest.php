<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_settings_page_shows_both_modes_and_the_current_one()
    {
        config(['snapsbrew.registration_enabled' => false]);
        $owner = User::factory()->create(['is_owner' => true]);

        $response = $this->actingAs($owner)->get('/admin/settings');

        $response->assertOk();
        $response->assertSee('value="open"', false);
        $response->assertSee('value="invite_only"', false);
        $response->assertSee('Anyone can sign up');
        $response->assertSee('Only people with an invitation');
        $response->assertSee('value="invite_only" checked', false);
    }

    public function test_the_owner_changes_the_registration_mode()
    {
        config(['snapsbrew.registration_enabled' => false]);
        $owner = User::factory()->create(['is_owner' => true]);

        $this->actingAs($owner)
            ->put('/admin/settings', ['registration_mode' => 'open'])
            ->assertRedirect('/admin/settings')
            ->assertSessionHas('status');

        $this->assertDatabaseHas('instance_settings', ['key' => 'registration_mode', 'value' => 'open']);
        $this->assertTrue(Registration::isOpen());

        $this->actingAs($owner)
            ->put('/admin/settings', ['registration_mode' => 'invite_only'])
            ->assertRedirect('/admin/settings');

        $this->assertFalse(Registration::isOpen());
    }

    public function test_an_unknown_mode_is_refused()
    {
        $owner = User::factory()->create(['is_owner' => true]);

        $this->actingAs($owner)
            ->from('/admin/settings')
            ->put('/admin/settings', ['registration_mode' => 'everyone'])
            ->assertRedirect('/admin/settings')
            ->assertSessionHasErrors('registration_mode');

        $this->assertDatabaseMissing('instance_settings', ['key' => 'registration_mode']);
    }
}
