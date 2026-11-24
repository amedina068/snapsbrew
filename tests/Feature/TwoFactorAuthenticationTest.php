<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_user_can_enable_two_factor_authentication()
    {
        $user = $this->actingUser();

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->post('/user/two-factor-authentication')
            ->assertRedirect();

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->two_factor_secret);
        $this->assertNotNull($fresh->two_factor_recovery_codes);
    }

    public function test_qr_code_endpoint_returns_svg_after_enable()
    {
        $user = $this->actingUser();

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->post('/user/two-factor-authentication')
            ->assertRedirect();

        $response = $this->withSession(['auth.password_confirmed_at' => time()])
            ->getJson('/user/two-factor-qr-code');

        $response->assertOk()->assertJsonStructure(['svg']);
    }

    public function test_recovery_codes_endpoint_returns_codes_after_enable()
    {
        $user = $this->actingUser();

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->post('/user/two-factor-authentication')
            ->assertRedirect();

        $response = $this->withSession(['auth.password_confirmed_at' => time()])
            ->getJson('/user/two-factor-recovery-codes');

        $response->assertOk();
        $this->assertIsArray($response->json());
        $this->assertCount(8, $response->json());
    }

    public function test_recovery_codes_can_be_regenerated()
    {
        $user = $this->actingUser();

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->post('/user/two-factor-authentication')
            ->assertRedirect();

        $original = $this->withSession(['auth.password_confirmed_at' => time()])
            ->getJson('/user/two-factor-recovery-codes')
            ->json();

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->post('/user/two-factor-recovery-codes')
            ->assertRedirect();

        $regenerated = $this->withSession(['auth.password_confirmed_at' => time()])
            ->getJson('/user/two-factor-recovery-codes')
            ->json();

        $this->assertNotEquals($original, $regenerated);
    }

    public function test_user_can_disable_two_factor_authentication()
    {
        $user = $this->actingUser();

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->post('/user/two-factor-authentication')
            ->assertRedirect();

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->delete('/user/two-factor-authentication')
            ->assertRedirect();

        $fresh = $user->fresh();
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_recovery_codes);
    }

    public function test_enabling_2fa_requires_password_confirmation()
    {
        $this->actingUser();

        $this->post('/user/two-factor-authentication')
            ->assertRedirect('/user/confirm-password');
    }

    public function test_guests_cannot_enable_2fa()
    {
        $this->post('/user/two-factor-authentication')
            ->assertRedirect('/login');
    }
}
