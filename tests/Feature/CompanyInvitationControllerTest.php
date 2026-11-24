<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyInvitationControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createAuthenticatedUser(): User
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_authenticated_user_can_send_invitation()
    {
        $user = $this->createAuthenticatedUser();

        $response = $this->post('/account/invitations', [
            'email' => 'newuser@example.com',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('company_invitations', [
            'company_id' => $user->company_id,
            'invited_by' => $user->id,
            'email' => 'newuser@example.com',
        ]);
    }

    public function test_guests_cannot_send_invitations()
    {
        $response = $this->post('/account/invitations', [
            'email' => 'newuser@example.com',
        ]);

        $response->assertRedirect('/login');
    }

    public function test_can_delete_pending_invitation()
    {
        $user = $this->createAuthenticatedUser();

        $invitation = CompanyInvitation::factory()->create([
            'company_id' => $user->company_id,
            'invited_by' => $user->id,
        ]);

        $response = $this->delete("/account/invitations/{$invitation->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('company_invitations', ['id' => $invitation->id]);
    }

    public function test_cannot_invite_existing_member()
    {
        $user = $this->createAuthenticatedUser();

        User::factory()->create([
            'company_id' => $user->company_id,
            'email' => 'member@example.com',
        ]);

        $response = $this->from('/account')->post('/account/invitations', [
            'email' => 'member@example.com',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('company_invitations', [
            'company_id' => $user->company_id,
            'email' => 'member@example.com',
        ]);
    }

    public function test_cannot_invite_when_pending_invitation_exists()
    {
        $user = $this->createAuthenticatedUser();

        CompanyInvitation::factory()->create([
            'company_id' => $user->company_id,
            'invited_by' => $user->id,
            'email' => 'pending@example.com',
        ]);

        $response = $this->from('/account')->post('/account/invitations', [
            'email' => 'pending@example.com',
        ]);

        $response->assertSessionHasErrors('email');

        $count = CompanyInvitation::query()
            ->where('company_id', $user->company_id)
            ->where('email', 'pending@example.com')
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_destroy_deletes_only_targeted_invitation()
    {
        $user = $this->createAuthenticatedUser();

        $keep = CompanyInvitation::factory()->create([
            'company_id' => $user->company_id,
            'invited_by' => $user->id,
            'email' => 'keep@example.com',
        ]);

        $remove = CompanyInvitation::factory()->create([
            'company_id' => $user->company_id,
            'invited_by' => $user->id,
            'email' => 'remove@example.com',
        ]);

        $response = $this->delete("/account/invitations/{$remove->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('company_invitations', ['id' => $remove->id]);
        $this->assertDatabaseHas('company_invitations', ['id' => $keep->id]);
    }

    public function test_cannot_delete_other_company_invitation()
    {
        $this->createAuthenticatedUser();

        $otherCompany = Company::factory()->create();
        $otherUser = User::factory()->create(['company_id' => $otherCompany->id]);
        $invitation = CompanyInvitation::factory()->create([
            'company_id' => $otherCompany->id,
            'invited_by' => $otherUser->id,
        ]);

        $this->delete("/account/invitations/{$invitation->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('company_invitations', ['id' => $invitation->id]);
    }
}
