<?php

namespace Tests\Unit\Mail;

use App\Mail\CompanyInvitationMail;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the SwiftMailer -> Symfony Mailer markdown rendering for the only
 * other user-facing email. The rendered HTML must carry the registration CTA
 * URL (the one click-through that breaks onboarding if mangled).
 */
class CompanyInvitationMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_html_with_registration_url()
    {
        $company = Company::factory()->create();
        $inviter = User::factory()->create(['company_id' => $company->id]);
        $invitation = CompanyInvitation::factory()->create([
            'company_id' => $company->id,
            'invited_by' => $inviter->id,
        ]);

        $expectedUrl = route('register', ['invitation' => $invitation->token]);

        $html = (new CompanyInvitationMail($invitation, $inviter))->render();

        $this->assertStringContainsString($expectedUrl, $html);
    }
}
