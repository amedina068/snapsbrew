<?php

namespace Tests\Feature;

use App\Actions\Instance\CreateInstanceOwner;
use App\Actions\Instance\DeleteUser;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\Font;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A user has one of two roles in the company: owner or member.
 *
 * Members share the content of the company (templates, snaps, collections).
 * Only an owner can change the company itself: the name, the webhook secret,
 * the invitations, the fonts and the roles of the members.
 */
class CompanyRolesTest extends TestCase
{
    use RefreshDatabase;

    private const DENIED = 'Only a company owner can do this.';

    /** @return array{0: Company, 1: User, 2: User} */
    private function companyWithOwnerAndMember(): array
    {
        $company = Company::factory()->create(['name' => 'Acme']);
        $owner = User::factory()->companyOwner()->create(['company_id' => $company->id]);
        $member = User::factory()->companyMember()->create(['company_id' => $company->id]);

        return [$company, $owner, $member];
    }

    // Role assignment

    public function test_the_first_user_of_a_company_is_an_owner_and_later_users_are_members(): void
    {
        $company = Company::factory()->create();
        $first = User::factory()->create(['company_id' => $company->id]);
        $second = User::factory()->create(['company_id' => $company->id]);

        $this->assertSame(User::COMPANY_ROLE_OWNER, $first->fresh()->company_role);
        $this->assertSame(User::COMPANY_ROLE_MEMBER, $second->fresh()->company_role);
        $this->assertTrue($first->fresh()->isCompanyOwner());
        $this->assertFalse($second->fresh()->isCompanyOwner());
    }

    public function test_a_user_who_registers_without_an_invitation_owns_the_new_company(): void
    {
        config(['snapsbrew.registration_enabled' => true]);
        // With no user, every page sends the visitor to /setup.
        User::factory()->create();

        $this->post('/register', [
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect();

        $this->assertTrue(User::where('email', 'ana@example.com')->firstOrFail()->isCompanyOwner());
    }

    public function test_a_user_who_registers_with_an_invitation_joins_as_a_member(): void
    {
        [$company, $owner] = $this->companyWithOwnerAndMember();
        $invitation = CompanyInvitation::factory()->create([
            'company_id' => $company->id,
            'invited_by' => $owner->id,
            'email' => 'pat@example.com',
        ]);

        $this->post('/register', [
            'email' => 'pat@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'invitation_token' => $invitation->token,
        ])->assertRedirect();

        $pat = User::where('email', 'pat@example.com')->firstOrFail();
        $this->assertSame($company->id, $pat->company_id);
        $this->assertSame(User::COMPANY_ROLE_MEMBER, $pat->company_role);
    }

    public function test_the_instance_owner_from_setup_or_install_owns_the_company(): void
    {
        $owner = app(CreateInstanceOwner::class)->create([
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'password' => 'a-long-password',
            'company' => 'Acme',
        ]);

        $this->assertTrue($owner->isOwner());
        $this->assertTrue($owner->isCompanyOwner());
    }

    public function test_the_role_is_not_mass_assignable(): void
    {
        $user = new User(['company_role' => User::COMPANY_ROLE_OWNER]);

        $this->assertNull($user->company_role);
    }

    // Webhook secret

    public function test_a_member_cannot_rotate_the_webhook_secret(): void
    {
        [$company, , $member] = $this->companyWithOwnerAndMember();
        $secret = $company->webhook_secret;

        $this->actingAs($member)->post('/account/webhook-secret')->assertForbidden();

        $this->assertSame($secret, $company->fresh()->webhook_secret);
    }

    public function test_an_owner_can_rotate_the_webhook_secret(): void
    {
        [$company, $owner] = $this->companyWithOwnerAndMember();
        $secret = $company->webhook_secret;

        $this->actingAs($owner)->post('/account/webhook-secret')->assertRedirect();

        $this->assertNotSame($secret, $company->fresh()->webhook_secret);
    }

    // Invitations

    public function test_a_member_cannot_invite_from_the_account_page(): void
    {
        [, , $member] = $this->companyWithOwnerAndMember();

        $this->actingAs($member)
            ->post('/account/invitations', ['email' => 'new@example.com'])
            ->assertForbidden();

        $this->assertDatabaseMissing('company_invitations', ['email' => 'new@example.com']);
    }

    public function test_a_member_cannot_invite_from_onboarding(): void
    {
        [, , $member] = $this->companyWithOwnerAndMember();
        $member->forceFill(['onboarding_completed_at' => null])->save();

        $this->actingAs($member)
            ->postJson('/onboarding/invites', ['emails' => ['new@example.com']])
            ->assertForbidden();

        $this->assertDatabaseMissing('company_invitations', ['email' => 'new@example.com']);
    }

    public function test_a_member_cannot_cancel_an_invitation(): void
    {
        [$company, $owner, $member] = $this->companyWithOwnerAndMember();
        $invitation = CompanyInvitation::factory()->create(['company_id' => $company->id, 'invited_by' => $owner->id]);

        $this->actingAs($member)->delete("/account/invitations/{$invitation->id}")->assertForbidden();

        $this->assertDatabaseHas('company_invitations', ['id' => $invitation->id]);
    }

    public function test_an_owner_can_cancel_a_pending_or_expired_invitation(): void
    {
        [$company, $owner] = $this->companyWithOwnerAndMember();
        $pending = CompanyInvitation::factory()->create(['company_id' => $company->id, 'invited_by' => $owner->id]);
        $expired = CompanyInvitation::factory()->expired()->create(['company_id' => $company->id, 'invited_by' => $owner->id]);

        $this->actingAs($owner)->delete("/account/invitations/{$pending->id}")->assertRedirect();
        $this->actingAs($owner)->delete("/account/invitations/{$expired->id}")->assertRedirect();

        $this->assertDatabaseMissing('company_invitations', ['id' => $pending->id]);
        $this->assertDatabaseMissing('company_invitations', ['id' => $expired->id]);
    }

    public function test_nobody_can_delete_an_accepted_invitation(): void
    {
        [$company, $owner, $member] = $this->companyWithOwnerAndMember();
        $accepted = CompanyInvitation::factory()->create([
            'company_id' => $company->id,
            'invited_by' => $owner->id,
            'accepted_at' => now(),
            'accepted_by' => $member->id,
        ]);

        $this->actingAs($owner)->delete("/account/invitations/{$accepted->id}")->assertNotFound();

        $this->assertDatabaseHas('company_invitations', ['id' => $accepted->id]);
    }

    // Fonts

    public function test_a_member_cannot_delete_a_company_font(): void
    {
        [$company, , $member] = $this->companyWithOwnerAndMember();
        $font = Font::factory()->create(['company_id' => $company->id]);

        $this->actingAs($member)->delete("/fonts/{$font->uuid}")->assertForbidden();

        $this->assertDatabaseHas('fonts', ['id' => $font->id]);
    }

    public function test_an_owner_can_delete_a_company_font(): void
    {
        [$company, $owner] = $this->companyWithOwnerAndMember();
        $font = Font::factory()->create(['company_id' => $company->id]);

        $this->actingAs($owner)->delete("/fonts/{$font->uuid}")->assertRedirect();

        $this->assertDatabaseMissing('fonts', ['id' => $font->id]);
    }

    public function test_the_fonts_page_hides_the_delete_button_from_a_member(): void
    {
        [$company, , $member] = $this->companyWithOwnerAndMember();
        $font = Font::factory()->create(['company_id' => $company->id]);

        $this->actingAs($member)->get('/fonts')
            ->assertOk()
            ->assertDontSee('action="'.url('/fonts/'.$font->uuid).'"', false)
            ->assertSee(self::DENIED);
    }

    // Company name

    public function test_a_promoted_owner_can_rename_the_company_in_onboarding(): void
    {
        [$company, , $member] = $this->companyWithOwnerAndMember();
        $member->forceFill(['company_role' => User::COMPANY_ROLE_OWNER, 'onboarding_completed_at' => null])->save();

        $this->actingAs($member)
            ->postJson('/onboarding/profile', ['name' => 'Pat', 'company_name' => 'Acme Studio'])
            ->assertOk();

        $this->assertSame('Acme Studio', $company->fresh()->name);
    }

    public function test_a_demoted_founder_cannot_rename_the_company_in_onboarding(): void
    {
        [$company, $founder, $member] = $this->companyWithOwnerAndMember();
        $member->forceFill(['company_role' => User::COMPANY_ROLE_OWNER])->save();
        $founder->forceFill(['company_role' => User::COMPANY_ROLE_MEMBER, 'onboarding_completed_at' => null])->save();

        $this->actingAs($founder)
            ->postJson('/onboarding/profile', ['name' => 'Ana', 'company_name' => 'Taken Over'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('company_name');

        $this->assertSame('Acme', $company->fresh()->name);
    }

    public function test_the_wizard_skips_the_invite_step_for_a_member(): void
    {
        [, $owner, $member] = $this->companyWithOwnerAndMember();
        $member->forceFill(['onboarding_completed_at' => null])->save();
        $owner->forceFill(['onboarding_completed_at' => null])->save();

        $this->actingAs($member)->get('/onboarding')
            ->assertOk()
            ->assertViewHas('canInvite', false)
            ->assertSee(':can-invite="false"', false);

        $this->actingAs($owner)->get('/onboarding')
            ->assertOk()
            ->assertViewHas('canInvite', true)
            ->assertSee(':can-invite="true"', false);
    }

    // Roles

    public function test_an_owner_can_make_a_member_an_owner(): void
    {
        [, $owner, $member] = $this->companyWithOwnerAndMember();

        $this->actingAs($owner)
            ->patch("/account/users/{$member->id}/role", ['role' => 'owner'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue($member->fresh()->isCompanyOwner());
    }

    public function test_an_owner_can_make_another_owner_a_member(): void
    {
        [, $owner, $member] = $this->companyWithOwnerAndMember();
        $member->forceFill(['company_role' => User::COMPANY_ROLE_OWNER])->save();

        $this->actingAs($owner)
            ->patch("/account/users/{$member->id}/role", ['role' => 'member'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($member->fresh()->isCompanyOwner());
    }

    public function test_a_member_cannot_change_a_role(): void
    {
        [, $owner, $member] = $this->companyWithOwnerAndMember();

        $this->actingAs($member)
            ->patch("/account/users/{$member->id}/role", ['role' => 'owner'])
            ->assertForbidden();
        $this->actingAs($member)
            ->patch("/account/users/{$owner->id}/role", ['role' => 'member'])
            ->assertForbidden();

        $this->assertFalse($member->fresh()->isCompanyOwner());
        $this->assertTrue($owner->fresh()->isCompanyOwner());
    }

    public function test_the_last_owner_cannot_become_a_member(): void
    {
        [, $owner] = $this->companyWithOwnerAndMember();

        $this->actingAs($owner)
            ->from('/account')
            ->patch("/account/users/{$owner->id}/role", ['role' => 'member'])
            ->assertRedirect('/account')
            ->assertSessionHasErrors('role');

        $this->assertTrue($owner->fresh()->isCompanyOwner());
    }

    public function test_an_owner_cannot_change_a_role_in_another_company(): void
    {
        [, $owner] = $this->companyWithOwnerAndMember();
        [, , $stranger] = $this->companyWithOwnerAndMember();

        $this->actingAs($owner)
            ->patch("/account/users/{$stranger->id}/role", ['role' => 'owner'])
            ->assertNotFound();

        $this->assertFalse($stranger->fresh()->isCompanyOwner());
    }

    public function test_the_role_must_be_owner_or_member(): void
    {
        [, $owner, $member] = $this->companyWithOwnerAndMember();

        $this->actingAs($owner)
            ->patch("/account/users/{$member->id}/role", ['role' => 'admin'])
            ->assertSessionHasErrors('role');

        $this->assertSame(User::COMPANY_ROLE_MEMBER, $member->fresh()->company_role);
    }

    public function test_deleting_the_last_owner_makes_the_oldest_member_an_owner(): void
    {
        [$company, $owner, $member] = $this->companyWithOwnerAndMember();
        $newer = User::factory()->companyMember()->create(['company_id' => $company->id]);

        app(DeleteUser::class)->delete($owner);

        $this->assertTrue($member->fresh()->isCompanyOwner());
        $this->assertFalse($newer->fresh()->isCompanyOwner());
    }

    // Account page

    public function test_the_users_tab_shows_the_role_of_each_user(): void
    {
        [, $owner, $member] = $this->companyWithOwnerAndMember();

        $this->actingAs($owner)->get('/account?tab=users')
            ->assertOk()
            ->assertSeeInOrder([$owner->email, 'Owner'])
            ->assertSeeInOrder([$member->email, 'Member'])
            ->assertSee('Make owner');
    }

    public function test_a_member_sees_why_the_owner_actions_are_off(): void
    {
        [$company, $owner, $member] = $this->companyWithOwnerAndMember();
        $invitation = CompanyInvitation::factory()->create(['company_id' => $company->id, 'invited_by' => $owner->id]);

        $this->actingAs($member)->get('/account?tab=users')
            ->assertOk()
            ->assertSee(self::DENIED)
            ->assertSee($invitation->email)
            ->assertDontSee($invitation->token)
            ->assertDontSee('Make owner')
            ->assertDontSee('action="/account/invitations"', false)
            ->assertDontSee('action="/account/webhook-secret"', false);
    }
}
