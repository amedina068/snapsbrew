<?php

namespace Tests\Feature;

use App\Exceptions\LastActiveOwnerException;
use App\Models\Company;
use App\Models\Snap;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * A company owner removes a user from **Account > Users**. The removal
 * deactivates the account with the same rule as **Admin > Users**. The user
 * cannot sign in, the tokens of the user stop working, and the templates and
 * snaps of the user stay in the company.
 */
class CompanyMemberRemovalTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: User, 2: User} */
    private function companyWithOwnerAndMember(): array
    {
        $company = Company::factory()->create(['name' => 'Acme']);
        $owner = User::factory()->companyOwner()->create(['company_id' => $company->id]);
        $member = User::factory()->companyMember()->create(['company_id' => $company->id]);

        return [$company, $owner, $member];
    }

    /**
     * Drop the guards that the earlier request built, so that the next request
     * authenticates from scratch the way a new HTTP request does.
     */
    private function freshRequest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_an_owner_removes_a_member_and_the_account_is_deactivated(): void
    {
        [, $owner, $member] = $this->companyWithOwnerAndMember();

        $this->actingAs($owner)
            ->from('/account')
            ->delete("/account/users/{$member->id}")
            ->assertRedirect('/account')
            ->assertSessionHas('status', "{$member->email} is removed from the company.")
            ->assertSessionHas('open_tab', 'users');

        $this->assertTrue($member->fresh()->isDeactivated());
        $this->assertSame($owner->company_id, $member->fresh()->company_id);
    }

    public function test_the_api_token_of_a_removed_member_stops_working(): void
    {
        [$company, $owner, $member] = $this->companyWithOwnerAndMember();
        $colleague = User::factory()->companyMember()->create(['company_id' => $company->id]);
        $token = $member->createToken('cli', ['api_requests'])->plainTextToken;
        $colleagueToken = $colleague->createToken('cli', ['api_requests'])->plainTextToken;

        $this->actingAs($owner)->delete("/account/users/{$member->id}")->assertRedirect();
        $this->freshRequest();

        $this->withToken($token)->getJson('/api/v1/auth')->assertUnauthorized();
        $this->freshRequest();
        $this->withToken($token)->getJson('/api/v1/templates')->assertUnauthorized();
        $this->freshRequest();
        // The tokens of the other users of the company keep working.
        $this->withToken($colleagueToken)->getJson('/api/v1/auth')->assertOk();
    }

    public function test_the_open_session_of_a_removed_member_ends(): void
    {
        [, $owner, $member] = $this->companyWithOwnerAndMember();
        $sessionKey = Auth::guard('web')->getName();

        $this->actingAs($owner)->delete("/account/users/{$member->id}")->assertRedirect();
        $this->freshRequest();

        $this->withSession([$sessionKey => $member->id])
            ->get('/dashboard')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => User::DEACTIVATED_MESSAGE]);
        $this->assertGuest();
    }

    public function test_the_templates_and_snaps_of_a_removed_member_stay_in_the_company(): void
    {
        [$company, $owner, $member] = $this->companyWithOwnerAndMember();
        $template = Template::factory()->create(['company_id' => $company->id, 'user_id' => $member->id]);
        $snap = Snap::factory()->create(['company_id' => $company->id, 'template_id' => $template->id, 'user_id' => $member->id]);

        $this->actingAs($owner)->delete("/account/users/{$member->id}")->assertRedirect();

        $this->assertDatabaseHas('templates', ['id' => $template->id, 'company_id' => $company->id, 'user_id' => $member->id]);
        $this->assertDatabaseHas('snaps', ['id' => $snap->id, 'company_id' => $company->id]);
        $this->assertDatabaseHas('users', ['id' => $member->id]);
    }

    public function test_a_member_cannot_remove_a_user(): void
    {
        [$company, $owner, $member] = $this->companyWithOwnerAndMember();
        $other = User::factory()->companyMember()->create(['company_id' => $company->id]);

        $this->actingAs($member)->delete("/account/users/{$other->id}")->assertForbidden();
        $this->actingAs($member)->delete("/account/users/{$owner->id}")->assertForbidden();

        $this->assertFalse($other->fresh()->isDeactivated());
        $this->assertFalse($owner->fresh()->isDeactivated());
    }

    public function test_an_owner_cannot_remove_a_user_of_another_company(): void
    {
        [, $owner] = $this->companyWithOwnerAndMember();
        [, , $stranger] = $this->companyWithOwnerAndMember();

        $this->actingAs($owner)->delete("/account/users/{$stranger->id}")->assertNotFound();

        $this->assertFalse($stranger->fresh()->isDeactivated());
    }

    public function test_the_last_active_owner_cannot_remove_themselves(): void
    {
        [, $owner] = $this->companyWithOwnerAndMember();

        $this->actingAs($owner)
            ->from('/account')
            ->delete("/account/users/{$owner->id}")
            ->assertRedirect('/account')
            ->assertSessionHasErrors(['remove' => LastActiveOwnerException::MESSAGE]);

        $this->assertFalse($owner->fresh()->isDeactivated());
    }

    public function test_the_last_active_owner_cannot_remove_themselves_when_alone_in_the_company(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->companyOwner()->create(['company_id' => $company->id]);

        $this->actingAs($owner)
            ->delete("/account/users/{$owner->id}")
            ->assertSessionHasErrors('remove');

        $this->assertFalse($owner->fresh()->isDeactivated());
    }

    public function test_a_deactivated_second_owner_does_not_count_as_an_active_owner(): void
    {
        [$company, $owner] = $this->companyWithOwnerAndMember();
        User::factory()->companyOwner()->create(['company_id' => $company->id, 'deactivated_at' => now()]);

        $this->actingAs($owner)->delete("/account/users/{$owner->id}")->assertSessionHasErrors('remove');

        $this->assertFalse($owner->fresh()->isDeactivated());
    }

    public function test_an_owner_can_remove_another_owner_when_an_active_owner_stays(): void
    {
        [$company, $owner] = $this->companyWithOwnerAndMember();
        $second = User::factory()->companyOwner()->create(['company_id' => $company->id]);

        $this->actingAs($owner)->delete("/account/users/{$second->id}")->assertSessionHasNoErrors();

        $this->assertTrue($second->fresh()->isDeactivated());
    }

    public function test_an_owner_can_remove_themselves_when_another_active_owner_stays(): void
    {
        [$company, $owner] = $this->companyWithOwnerAndMember();
        User::factory()->companyOwner()->create(['company_id' => $company->id]);

        $this->actingAs($owner)->delete("/account/users/{$owner->id}")->assertSessionHasNoErrors();

        $this->assertTrue($owner->fresh()->isDeactivated());
    }

    public function test_an_owner_cannot_remove_the_owner_of_the_instance(): void
    {
        [$company, $owner] = $this->companyWithOwnerAndMember();
        $root = User::factory()->companyMember()->create(['company_id' => $company->id, 'is_owner' => true]);

        $this->actingAs($owner)->delete("/account/users/{$root->id}")->assertForbidden();

        $this->assertFalse($root->fresh()->isDeactivated());
    }

    public function test_a_user_that_is_already_deactivated_is_not_found(): void
    {
        [$company, $owner] = $this->companyWithOwnerAndMember();
        $gone = User::factory()->companyMember()->create(['company_id' => $company->id, 'deactivated_at' => now()->subDay()]);
        $deactivatedAt = $gone->fresh()->deactivated_at;

        $this->actingAs($owner)->delete("/account/users/{$gone->id}")->assertNotFound();

        $this->assertEquals($deactivatedAt, $gone->fresh()->deactivated_at);
    }

    // The users tab

    public function test_the_users_tab_shows_a_remove_button_with_a_confirmation_to_an_owner(): void
    {
        [, $owner, $member] = $this->companyWithOwnerAndMember();

        $this->actingAs($owner)->get('/account?tab=users')
            ->assertOk()
            ->assertSee('action="/account/users/'.$member->id.'"', false)
            ->assertSee("Remove {$member->email}", false)
            ->assertSee('The templates and snaps of this user stay in the company.', false);
    }

    public function test_the_users_tab_hides_the_remove_button_from_a_member(): void
    {
        [, $owner, $member] = $this->companyWithOwnerAndMember();

        $this->actingAs($member)->get('/account?tab=users')
            ->assertOk()
            ->assertDontSee('action="/account/users/'.$owner->id.'"', false)
            ->assertDontSee("Remove {$owner->email}");
    }

    public function test_the_users_tab_does_not_offer_to_remove_the_last_active_owner(): void
    {
        [, $owner] = $this->companyWithOwnerAndMember();

        $this->actingAs($owner)->get('/account?tab=users')
            ->assertOk()
            ->assertDontSee('action="/account/users/'.$owner->id.'"', false);
    }

    public function test_the_users_tab_marks_a_removed_user_as_deactivated(): void
    {
        [, $owner, $member] = $this->companyWithOwnerAndMember();
        $member->deactivate();

        $this->actingAs($owner)->get('/account?tab=users')
            ->assertOk()
            ->assertSee('Deactivated')
            ->assertDontSee('action="/account/users/'.$member->id.'"', false);
    }
}
