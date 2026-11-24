<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CompanyInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitation_belongs_to_company()
    {
        $invitation = CompanyInvitation::factory()->create();

        $this->assertInstanceOf(Company::class, $invitation->company);
    }

    public function test_invitation_belongs_to_inviter()
    {
        $inviter = User::factory()->create();
        $invitation = CompanyInvitation::factory()->create([
            'company_id' => $inviter->company_id,
            'invited_by' => $inviter->id,
        ]);

        $this->assertInstanceOf(User::class, $invitation->inviter);
        $this->assertEquals($inviter->id, $invitation->inviter->id);
    }

    public function test_invitation_belongs_to_accepted_by()
    {
        $acceptedBy = User::factory()->create();
        $invitation = CompanyInvitation::factory()->accepted()->create([
            'accepted_by' => $acceptedBy->id,
        ]);

        $this->assertInstanceOf(User::class, $invitation->acceptedBy);
    }

    public function test_pending_scope_excludes_accepted()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        CompanyInvitation::factory()->create([
            'company_id' => $company->id,
            'invited_by' => $user->id,
        ]);
        CompanyInvitation::factory()->accepted()->create([
            'company_id' => $company->id,
            'invited_by' => $user->id,
        ]);

        $pending = CompanyInvitation::pending()->get();

        $this->assertCount(1, $pending);
    }

    public function test_pending_scope_excludes_expired()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        CompanyInvitation::factory()->create([
            'company_id' => $company->id,
            'invited_by' => $user->id,
        ]);
        CompanyInvitation::factory()->expired()->create([
            'company_id' => $company->id,
            'invited_by' => $user->id,
        ]);

        $pending = CompanyInvitation::pending()->get();

        $this->assertCount(1, $pending);
    }

    public function test_pending_scope_includes_no_expiration()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        CompanyInvitation::factory()->noExpiration()->create([
            'company_id' => $company->id,
            'invited_by' => $user->id,
        ]);

        $pending = CompanyInvitation::pending()->get();

        $this->assertCount(1, $pending);
    }

    public function test_mark_accepted()
    {
        $invitation = CompanyInvitation::factory()->create();
        $user = User::factory()->create();

        $invitation->markAccepted($user);

        $invitation->refresh();

        $this->assertNotNull($invitation->accepted_at);
        $this->assertEquals($user->id, $invitation->accepted_by);
    }

    public function test_expires_at_cast()
    {
        $invitation = CompanyInvitation::factory()->create();

        $this->assertInstanceOf(Carbon::class, $invitation->expires_at);
    }

    public function test_accepted_at_cast()
    {
        $invitation = CompanyInvitation::factory()->accepted()->create();

        $this->assertInstanceOf(Carbon::class, $invitation->accepted_at);
    }
}
