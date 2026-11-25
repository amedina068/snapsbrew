<?php

namespace Tests\Feature;

use App\Mail\CompanyInvitationMail;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
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

    private function inviteUrl(CompanyInvitation $invitation): string
    {
        return route('register', ['invitation' => $invitation->token]);
    }

    public function test_an_invitation_flashes_its_invite_link()
    {
        Mail::fake();
        $this->createAuthenticatedUser();

        $response = $this->from('/account')->post('/account/invitations', ['email' => 'new@example.com']);

        $invitation = CompanyInvitation::query()->where('email', 'new@example.com')->firstOrFail();
        $response->assertSessionHas('invite_url', $this->inviteUrl($invitation));
    }

    public function test_with_a_mailer_the_invitation_goes_out_and_the_page_says_so()
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);
        $this->createAuthenticatedUser();

        $this->from('/account')->post('/account/invitations', ['email' => 'new@example.com'])
            ->assertSessionHas('status', 'We sent the invitation to new@example.com.')
            ->assertSessionHas('invite_sent', true);

        Mail::assertSent(CompanyInvitationMail::class, fn ($mail) => $mail->hasTo('new@example.com'));
    }

    public function test_with_the_log_mailer_the_page_says_that_mail_is_not_set_up()
    {
        config(['mail.default' => 'log']);
        $this->createAuthenticatedUser();

        $this->followingRedirects()
            ->from('/account')
            ->post('/account/invitations', ['email' => 'new@example.com'])
            ->assertOk()
            ->assertSee('Mail is not set up. Send this link yourself.')
            ->assertDontSee('Invitation sent');

        $invitation = CompanyInvitation::query()->where('email', 'new@example.com')->firstOrFail();
        $this->get('/account')->assertSee($this->inviteUrl($invitation), false);
    }

    public function test_a_failed_mail_keeps_the_invitation_and_gives_the_link()
    {
        config(['mail.default' => 'smtp']);
        Mail::shouldReceive('to')->andThrow(new RuntimeException('Connection refused'));
        $this->createAuthenticatedUser();

        $this->from('/account')->post('/account/invitations', ['email' => 'new@example.com'])
            ->assertSessionHas('status', 'The invitation email did not go out. Send this link yourself.')
            ->assertSessionHas('invite_sent', false)
            ->assertSessionHas('invite_url');

        $this->assertDatabaseHas('company_invitations', ['email' => 'new@example.com']);
    }

    public function test_each_pending_invitation_has_a_copy_invite_link_button()
    {
        $user = $this->createAuthenticatedUser();
        $invitation = CompanyInvitation::factory()->create([
            'company_id' => $user->company_id,
            'invited_by' => $user->id,
            'email' => 'pending@example.com',
        ]);

        $this->get('/account')
            ->assertOk()
            ->assertSee('Copy invite link')
            ->assertSee('data-copy-text="'.e($this->inviteUrl($invitation)).'"', false);
    }

    public function test_the_page_shows_no_link_of_an_invitation_of_another_company()
    {
        $this->createAuthenticatedUser();
        $other = CompanyInvitation::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->get('/account')
            ->assertOk()
            ->assertDontSee($other->token);
    }

    public function test_with_the_log_mailer_the_pending_list_says_that_mail_is_not_set_up()
    {
        config(['mail.default' => 'log']);
        $user = $this->createAuthenticatedUser();
        CompanyInvitation::factory()->create(['company_id' => $user->company_id, 'invited_by' => $user->id]);

        $this->get('/account')->assertSee('Mail is not set up. Send this link yourself.');
    }
}
