<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RunsJsonCommands;
use Tests\TestCase;

/**
 * `snapsbrew:user:role <email> owner|member` sets the company role of a user.
 * It uses the same rule as `PATCH /account/users/{id}/role`: each company
 * keeps at least one active owner.
 */
class UserRoleCommandTest extends TestCase
{
    use RefreshDatabase, RunsJsonCommands;

    /** @return array{0: Company, 1: User, 2: User} */
    private function companyWithOwnerAndMember(): array
    {
        $company = Company::factory()->create(['name' => 'Acme']);
        $owner = User::factory()->companyOwner()->create(['company_id' => $company->id, 'email' => 'ana@acme.test']);
        $member = User::factory()->companyMember()->create(['company_id' => $company->id, 'email' => 'pat@acme.test']);

        return [$company, $owner, $member];
    }

    public function test_it_makes_a_member_an_owner(): void
    {
        [$company, , $member] = $this->companyWithOwnerAndMember();

        [$exit, $json] = $this->runJson('snapsbrew:user:role', ['email' => 'pat@acme.test', 'role' => 'owner']);

        $this->assertSame(0, $exit);
        $this->assertSame([
            'user' => ['id' => $member->id, 'email' => 'pat@acme.test', 'deactivated' => false],
            'company' => ['id' => $company->id, 'name' => 'Acme'],
            'company_role' => 'owner',
            'previous_role' => 'member',
            'changed' => true,
        ], $json['data']);
        $this->assertSame([], $json['errors']);
        $this->assertTrue($member->fresh()->isCompanyOwner());
    }

    public function test_it_makes_an_owner_a_member_when_another_active_owner_exists(): void
    {
        [, $owner, $member] = $this->companyWithOwnerAndMember();
        $member->forceFill(['company_role' => User::COMPANY_ROLE_OWNER])->save();

        [$exit, $json] = $this->runJson('snapsbrew:user:role', ['email' => 'ana@acme.test', 'role' => 'member']);

        $this->assertSame(0, $exit);
        $this->assertTrue($json['data']['changed']);
        $this->assertFalse($owner->fresh()->isCompanyOwner());
    }

    public function test_the_same_role_is_no_change_and_exits_zero(): void
    {
        $this->companyWithOwnerAndMember();

        [$exit, $json] = $this->runJson('snapsbrew:user:role', ['email' => 'ana@acme.test', 'role' => 'owner']);

        $this->assertSame(0, $exit);
        $this->assertFalse($json['data']['changed']);
        $this->assertSame('owner', $json['data']['previous_role']);
    }

    public function test_it_refuses_to_demote_the_last_active_owner_with_exit_three(): void
    {
        [$company, $owner] = $this->companyWithOwnerAndMember();
        User::factory()->companyOwner()->create(['company_id' => $company->id, 'deactivated_at' => now()]);

        [$exit, $json] = $this->runJson('snapsbrew:user:role', ['email' => 'ana@acme.test', 'role' => 'member']);

        $this->assertSame(3, $exit);
        $this->assertFalse($json['ok']);
        $this->assertSame('last_active_owner', $json['errors'][0]['code']);
        $this->assertStringContainsString('php artisan snapsbrew:user:role <email> owner', $json['errors'][0]['fix']);
        $this->assertSame('ana@acme.test', $json['data']['user']['email']);
        $this->assertTrue($owner->fresh()->isCompanyOwner());
    }

    public function test_it_refuses_an_unknown_email_with_exit_two(): void
    {
        [$exit, $json] = $this->runJson('snapsbrew:user:role', ['email' => 'nobody@acme.test', 'role' => 'owner']);

        $this->assertSame(2, $exit);
        $this->assertSame('user_not_found', $json['errors'][0]['code']);
        $this->assertStringContainsString('snapsbrew:user:list', $json['errors'][0]['fix']);
    }

    public function test_it_refuses_a_role_that_is_not_owner_or_member_with_exit_two(): void
    {
        [, , $member] = $this->companyWithOwnerAndMember();

        [$exit, $json] = $this->runJson('snapsbrew:user:role', ['email' => 'pat@acme.test', 'role' => 'admin']);

        $this->assertSame(2, $exit);
        $this->assertSame('invalid_role', $json['errors'][0]['code']);
        $this->assertSame(User::COMPANY_ROLE_MEMBER, $member->fresh()->company_role);
    }

    public function test_it_refuses_a_user_without_a_company_with_exit_two(): void
    {
        User::factory()->create(['email' => 'solo@example.com', 'company_id' => null]);

        [$exit, $json] = $this->runJson('snapsbrew:user:role', ['email' => 'solo@example.com', 'role' => 'owner']);

        $this->assertSame(2, $exit);
        $this->assertSame('no_company', $json['errors'][0]['code']);
    }

    public function test_the_email_and_the_role_are_not_case_sensitive(): void
    {
        [, , $member] = $this->companyWithOwnerAndMember();

        [$exit] = $this->runJson('snapsbrew:user:role', ['email' => ' Pat@ACME.test ', 'role' => 'OWNER']);

        $this->assertSame(0, $exit);
        $this->assertTrue($member->fresh()->isCompanyOwner());
    }

    public function test_it_makes_a_user_the_owner_of_a_company_with_no_active_owner(): void
    {
        [, $owner, $member] = $this->companyWithOwnerAndMember();
        $owner->forceFill(['deactivated_at' => now()])->save();

        [$exit] = $this->runJson('snapsbrew:user:role', ['email' => 'pat@acme.test', 'role' => 'owner']);

        $this->assertSame(0, $exit);
        $this->assertTrue($member->fresh()->isCompanyOwner());
    }

    public function test_the_text_output_names_the_new_role(): void
    {
        $this->companyWithOwnerAndMember();

        $this->artisan('snapsbrew:user:role', ['email' => 'pat@acme.test', 'role' => 'owner'])
            ->expectsOutputToContain('pat@acme.test is now an owner of Acme.')
            ->assertExitCode(0);

        $this->artisan('snapsbrew:user:role', ['email' => 'ana@acme.test', 'role' => 'owner'])
            ->expectsOutputToContain('ana@acme.test is already an owner of Acme. Nothing changed.')
            ->assertExitCode(0);
    }

    public function test_the_text_output_explains_the_last_owner_refusal(): void
    {
        $this->companyWithOwnerAndMember();

        $this->artisan('snapsbrew:user:role', ['email' => 'ana@acme.test', 'role' => 'member'])
            ->expectsOutputToContain('The company must keep at least one active owner.')
            ->assertExitCode(3);
    }
}
