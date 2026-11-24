<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_account()
    {
        $response = $this->get('/account');

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_account()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $response = $this->actingAs($user)->get('/account');

        $response->assertStatus(200);
    }

    public function test_account_page_shows_company_info()
    {
        $company = Company::factory()->create(['name' => 'Test Company']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $response = $this->actingAs($user)->get('/account');

        $response->assertStatus(200);
        $response->assertSee('Test Company');
    }

    public function test_account_page_with_tab_parameter()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $response = $this->actingAs($user)->get('/account?tab=developers');

        $response->assertStatus(200);
    }

    public function test_account_page_ignores_invalid_tab()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $response = $this->actingAs($user)->get('/account?tab=invalid');

        $response->assertStatus(200);
    }

    public function test_a_password_update_shows_a_sentence_and_not_the_status_code()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['status' => 'password-updated'])
            ->get('/account?tab=security')
            ->assertSee('Your password is updated.')
            ->assertDontSee('password-updated');
    }

    public function test_a_token_revoke_says_that_the_token_is_revoked()
    {
        $user = User::factory()->create();
        $token = $user->createToken('CI token', ['api_requests'])->accessToken;

        $this->actingAs($user)
            ->from('/account?tab=developers')
            ->delete("/tokens/{$token->id}")
            ->assertSessionHas('status', 'API token revoked.');
    }

    public function test_every_destructive_action_of_the_account_page_asks_for_a_confirmation()
    {
        $user = User::factory()->create();
        $user->createToken('CI token', ['api_requests']);
        CompanyInvitation::factory()->create([
            'company_id' => $user->company_id,
            'invited_by' => $user->id,
        ]);

        $html = $this->actingAs($user)->get('/account')->getContent();

        foreach ([
            'Revoke the token CI token?',
            'Cancel the invitation of',
            'Rotate the webhook signing secret?',
        ] as $question) {
            $this->assertStringContainsString($question, $html);
        }
    }

    public function test_a_new_token_has_a_copy_button()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['token' => '1|abc123'])
            ->get('/account')
            ->assertSee('Copy this token now. You cannot see it again.')
            ->assertSee('<copy-text-button link="1|abc123"', false);
    }
}
