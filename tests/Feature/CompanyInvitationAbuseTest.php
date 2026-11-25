<?php

namespace Tests\Feature;

use App\Mail\CompanyInvitationMail;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * An invitation mail carries text that a customer typed. The mail must show
 * that text as text, and a company must not send mail without a limit.
 */
class CompanyInvitationAbuseTest extends TestCase
{
    use RefreshDatabase;

    private function invitationMail(string $companyName, string $inviterName): string
    {
        $company = Company::factory()->create(['name' => $companyName]);
        $inviter = User::factory()->create(['company_id' => $company->id, 'name' => $inviterName]);
        $invitation = $company->invitations()->create([
            'email' => 'invitee@example.com',
            'token' => Str::random(40),
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
        ]);

        return (new CompanyInvitationMail($invitation, $inviter))->render();
    }

    public function test_the_mail_shows_markdown_in_the_company_name_as_text()
    {
        $html = $this->invitationMail('Acme [Sign in](https://link.invalid/x) **now**', 'Jane');

        $this->assertStringNotContainsString('href="https://link.invalid/x"', $html);
        $this->assertStringNotContainsString('<strong>now</strong>', $html);
        $this->assertStringContainsString('[Sign in]', $html);
    }

    public function test_the_mail_shows_markdown_in_the_inviter_name_as_text()
    {
        $html = $this->invitationMail('Acme', '![x](https://link.invalid/p.png) Jane');

        $this->assertStringNotContainsString('src="https://link.invalid/p.png"', $html);
        $this->assertStringNotContainsString('href="https://link.invalid', $html);
    }

    public function test_the_mail_keeps_html_in_the_company_name_escaped()
    {
        $html = $this->invitationMail('Acme <b>Bold</b> & Co', 'Jane');

        $this->assertStringNotContainsString('<b>Bold</b>', $html);
        $this->assertStringContainsString('Acme &lt;b&gt;Bold&lt;/b&gt; &amp; Co', $html);
    }

    public static function closedOnboardingSteps(): array
    {
        return [
            'profile' => ['/onboarding/profile', ['name' => 'New', 'company_name' => 'Renamed']],
            'categories' => ['/onboarding/categories', ['categories' => ['social']]],
            'invites' => ['/onboarding/invites', ['emails' => ['someone@example.com']]],
        ];
    }

    #[DataProvider('closedOnboardingSteps')]
    public function test_an_onboarded_user_cannot_post_an_onboarding_step(string $uri, array $payload)
    {
        Mail::fake();
        $company = Company::factory()->create(['name' => 'Original']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $this->actingAs($user)->postJson($uri, $payload)->assertForbidden();

        $this->assertSame('Original', $company->fresh()->name);
        $this->assertSame(0, CompanyInvitation::query()->count());
        Mail::assertNothingQueued();
    }

    public function test_the_account_invitations_have_a_daily_cap_for_each_company()
    {
        Mail::fake();
        config(['snapsbrew.invitations_per_company_per_day' => 2]);
        $company = Company::factory()->create();
        // Two owners of one company share the cap. Only an owner can invite.
        $first = User::factory()->companyOwner()->create(['company_id' => $company->id]);
        $second = User::factory()->companyOwner()->create(['company_id' => $company->id]);

        $this->actingAs($first)->postJson('/account/invitations', ['email' => 'a@example.com'])->assertRedirect();
        $this->actingAs($second)->postJson('/account/invitations', ['email' => 'b@example.com'])->assertRedirect();

        $response = $this->actingAs($second)->postJson('/account/invitations', ['email' => 'c@example.com']);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertStringContainsString('daily limit', $response->json('errors.email.0'));
        $this->assertSame(2, CompanyInvitation::query()->where('company_id', $company->id)->count());
        Mail::assertSent(CompanyInvitationMail::class, 2);
    }

    public function test_the_onboarding_invitations_count_toward_the_same_daily_cap()
    {
        Mail::fake();
        config(['snapsbrew.invitations_per_company_per_day' => 2]);
        $company = Company::factory()->create();
        $user = User::factory()->needsOnboarding()->create(['company_id' => $company->id]);

        $this->actingAs($user)->postJson('/onboarding/invites', ['emails' => [
            'a@example.com', 'b@example.com', 'c@example.com',
        ]])->assertOk();

        $this->assertSame(2, CompanyInvitation::query()->where('company_id', $company->id)->count());
        Mail::assertQueued(CompanyInvitationMail::class, 2);
    }

    public function test_the_account_invitation_route_has_a_throttle()
    {
        $middleware = collect(app('router')->getRoutes()->match(
            request()->create('/account/invitations', 'POST')
        )->gatherMiddleware());

        $this->assertTrue($middleware->contains(fn ($name) => is_string($name) && str_starts_with($name, 'throttle:')));
    }
}
