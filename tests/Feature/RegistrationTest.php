<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['snapsbrew.registration_enabled' => true]);
        $this->completeInstanceSetup();
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_registration_creates_the_user_and_company_with_no_outbound_http()
    {
        // Registration must not call Stripe or any other external service.
        // Http::fake() records each request, so assertNothingSent() also sees
        // a request that the app sends and then catches the error of.
        Http::fake();
        Http::preventStrayRequests();

        $this->post('/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();

        $user = User::where('email', 'jane@example.com')->firstOrFail();
        $this->assertNotNull($user->company);
        $this->assertSame("Jane Doe's Company", $user->company->name);
        $this->assertFalse(Schema::hasColumn('companies', 'trial_ends_at'));
        Http::assertNothingSent();
    }

    public function test_registration_with_valid_invitation()
    {
        $company = Company::factory()->create();
        $inviter = User::factory()->create(['company_id' => $company->id]);
        $invitation = CompanyInvitation::factory()->create([
            'company_id' => $company->id,
            'invited_by' => $inviter->id,
            'email' => 'invited@example.com',
        ]);

        $response = $this->post('/register', [
            'name' => 'Invited User',
            'email' => 'invited@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'invitation_token' => $invitation->token,
        ]);

        $this->assertAuthenticated();

        $user = User::where('email', 'invited@example.com')->first();
        $this->assertEquals($company->id, $user->company_id);

        // Invitation should be marked as accepted
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_registration_with_invalid_invitation_token_fails()
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'invitation_token' => 'invalid-token',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_registration_requires_email()
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_registration_requires_password()
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_registration_rejects_duplicate_email()
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'existing@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
    }
}
