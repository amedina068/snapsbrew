<?php

namespace Tests\Feature;

use App\Actions\Instance\CreateInstanceOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The setup page and `snapsbrew:install` already ask for the name and the
 * company of the owner. The owner goes to the dashboard, not to the wizard.
 */
class InstanceOwnerOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function input(): array
    {
        return [
            'name' => 'Olive Owner',
            'email' => 'owner@example.com',
            'password' => 'a-long-password-1',
            'company' => 'Olive Studio',
        ];
    }

    public function test_the_owner_from_the_install_command_has_completed_onboarding()
    {
        $owner = app(CreateInstanceOwner::class)->create($this->input());

        $this->assertTrue($owner->fresh()->hasCompletedOnboarding());
    }

    public function test_the_first_owner_has_completed_onboarding()
    {
        $owner = app(CreateInstanceOwner::class)->createFirstOwner($this->input());

        $this->assertTrue($owner->fresh()->hasCompletedOnboarding());
    }

    public function test_the_owner_opens_the_dashboard_and_the_account_page_without_the_wizard()
    {
        $owner = app(CreateInstanceOwner::class)->create($this->input());

        $this->actingAs($owner)->get('/dashboard')->assertOk();
        $this->actingAs($owner)->get('/account')->assertOk();
    }

    public function test_a_user_from_open_registration_still_gets_the_wizard()
    {
        $user = User::factory()->needsOnboarding()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/onboarding');
    }
}
