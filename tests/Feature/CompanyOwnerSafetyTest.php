<?php

namespace Tests\Feature;

use App\Actions\Instance\DeleteUser;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RunsJsonCommands;
use Tests\TestCase;

/**
 * Each company that has an active user keeps an active owner. An active owner
 * is a user with the company role `owner` and no `deactivated_at`. Without
 * one, nobody in the company can change the roles or manage the members.
 */
class CompanyOwnerSafetyTest extends TestCase
{
    use RefreshDatabase, RunsJsonCommands;

    private function instanceOwner(): User
    {
        return User::factory()->create(['is_owner' => true, 'email' => 'root@example.com']);
    }

    /** @return array{0: Company, 1: User, 2: User} */
    private function companyWithOwnerAndMember(): array
    {
        $company = Company::factory()->create(['name' => 'Acme']);
        $owner = User::factory()->companyOwner()->create(['company_id' => $company->id]);
        $member = User::factory()->companyMember()->create(['company_id' => $company->id]);

        return [$company, $owner, $member];
    }

    // Deactivation in Admin > Users

    public function test_the_instance_owner_cannot_deactivate_the_last_active_owner_of_a_company(): void
    {
        $root = $this->instanceOwner();
        [, $owner] = $this->companyWithOwnerAndMember();

        $this->actingAs($root)
            ->from('/admin/users')
            ->post("/admin/users/{$owner->id}/deactivation")
            ->assertRedirect('/admin/users')
            ->assertSessionHas('error', fn (string $message) => str_contains($message, "{$owner->email} is the last active owner of Acme")
                && str_contains($message, 'php artisan snapsbrew:user:role'));

        $this->assertFalse($owner->fresh()->isDeactivated());
    }

    public function test_a_deactivated_second_owner_does_not_count_as_an_active_owner(): void
    {
        $root = $this->instanceOwner();
        [$company, $owner] = $this->companyWithOwnerAndMember();
        User::factory()->companyOwner()->create(['company_id' => $company->id, 'deactivated_at' => now()]);

        $this->actingAs($root)->post("/admin/users/{$owner->id}/deactivation")->assertSessionHas('error');

        $this->assertFalse($owner->fresh()->isDeactivated());
    }

    public function test_the_instance_owner_can_deactivate_an_owner_when_another_active_owner_exists(): void
    {
        $root = $this->instanceOwner();
        [, $owner, $member] = $this->companyWithOwnerAndMember();
        $member->forceFill(['company_role' => User::COMPANY_ROLE_OWNER])->save();

        $this->actingAs($root)
            ->post("/admin/users/{$owner->id}/deactivation")
            ->assertSessionHas('status')
            ->assertSessionMissing('error');

        $this->assertTrue($owner->fresh()->isDeactivated());
    }

    public function test_the_instance_owner_can_deactivate_the_owner_when_no_other_user_is_active(): void
    {
        $root = $this->instanceOwner();
        [, $owner, $member] = $this->companyWithOwnerAndMember();
        $member->deactivate();

        $this->actingAs($root)->post("/admin/users/{$owner->id}/deactivation")->assertSessionHas('status');

        $this->assertTrue($owner->fresh()->isDeactivated());
    }

    public function test_the_instance_owner_can_deactivate_a_member(): void
    {
        $root = $this->instanceOwner();
        [, , $member] = $this->companyWithOwnerAndMember();

        $this->actingAs($root)->post("/admin/users/{$member->id}/deactivation")->assertSessionHas('status');

        $this->assertTrue($member->fresh()->isDeactivated());
    }

    // Role changes on Account > Users

    public function test_an_owner_cannot_become_a_member_when_the_other_owner_is_deactivated(): void
    {
        [$company, $owner] = $this->companyWithOwnerAndMember();
        User::factory()->companyOwner()->create(['company_id' => $company->id, 'deactivated_at' => now()]);

        $this->actingAs($owner)
            ->from('/account')
            ->patch("/account/users/{$owner->id}/role", ['role' => 'member'])
            ->assertRedirect('/account')
            ->assertSessionHasErrors('role');

        $this->assertTrue($owner->fresh()->isCompanyOwner());
    }

    public function test_the_users_tab_keeps_the_last_active_owner_an_owner(): void
    {
        [$company, $owner] = $this->companyWithOwnerAndMember();
        User::factory()->companyOwner()->create(['company_id' => $company->id, 'deactivated_at' => now()]);

        $this->actingAs($owner)->get('/account?tab=users')
            ->assertOk()
            ->assertSee('The last active owner stays an owner.')
            ->assertDontSee("Make {$owner->email} a member");
    }

    // Delete

    public function test_deleting_the_last_owner_makes_the_oldest_active_user_an_owner(): void
    {
        [$company, $owner, $gone] = $this->companyWithOwnerAndMember();
        $gone->deactivate();
        $active = User::factory()->companyMember()->create(['company_id' => $company->id]);

        app(DeleteUser::class)->delete($owner);

        $this->assertTrue($active->fresh()->isCompanyOwner());
        $this->assertFalse($gone->fresh()->isCompanyOwner());
    }

    // Migration backfill

    public function test_the_backfill_skips_a_deactivated_founder(): void
    {
        $company = Company::factory()->create();
        $founder = User::factory()->create(['company_id' => $company->id, 'deactivated_at' => now()]);
        $active = User::factory()->create(['company_id' => $company->id]);
        $later = User::factory()->create(['company_id' => $company->id]);
        DB::table('users')->update(['company_role' => User::COMPANY_ROLE_MEMBER]);

        $this->roleMigration()->backfill();

        $this->assertFalse($founder->fresh()->isCompanyOwner());
        $this->assertTrue($active->fresh()->isCompanyOwner());
        $this->assertFalse($later->fresh()->isCompanyOwner());
    }

    public function test_the_backfill_uses_the_founder_when_no_user_of_the_company_is_active(): void
    {
        $company = Company::factory()->create();
        $founder = User::factory()->create(['company_id' => $company->id, 'deactivated_at' => now()]);
        $other = User::factory()->create(['company_id' => $company->id, 'deactivated_at' => now()]);
        DB::table('users')->update(['company_role' => User::COMPANY_ROLE_MEMBER]);

        $this->roleMigration()->backfill();

        $this->assertTrue($founder->fresh()->isCompanyOwner());
        $this->assertFalse($other->fresh()->isCompanyOwner());
    }

    // Doctor

    public function test_the_doctor_reports_each_company_with_no_active_owner_and_the_fix(): void
    {
        [$acme, $owner, $member] = $this->companyWithOwnerAndMember();
        $owner->forceFill(['deactivated_at' => now()])->save();
        $globex = Company::factory()->create(['name' => 'Globex']);
        $lee = User::factory()->companyMember()->create(['company_id' => $globex->id]);
        // A healthy company and a company with no active user are not in the report.
        $this->companyWithOwnerAndMember();
        $closed = Company::factory()->create(['name' => 'Closed']);
        User::factory()->companyOwner()->create(['company_id' => $closed->id, 'deactivated_at' => now()]);

        [, $json] = $this->runJson('snapsbrew:doctor');
        $check = collect($json['data']['checks'])->firstWhere('id', 'company_owners');

        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('2 companies have no active owner', $check['message']);
        $this->assertStringContainsString("Acme (id {$acme->id})", $check['message']);
        $this->assertStringContainsString("Globex (id {$globex->id})", $check['message']);
        $this->assertStringNotContainsString('Closed', $check['message']);
        $this->assertStringContainsString("php artisan snapsbrew:user:role {$member->email} owner", $check['fix']);
        $this->assertStringContainsString("php artisan snapsbrew:user:role {$lee->email} owner", $check['fix']);
        $this->assertSame([
            ['id' => $acme->id, 'name' => 'Acme', 'suggested_owner' => $member->email],
            ['id' => $globex->id, 'name' => 'Globex', 'suggested_owner' => $lee->email],
        ], $check['companies']);
    }

    public function test_the_doctor_passes_when_each_company_has_an_active_owner(): void
    {
        $this->companyWithOwnerAndMember();

        [, $json] = $this->runJson('snapsbrew:doctor');
        $check = collect($json['data']['checks'])->firstWhere('id', 'company_owners');

        $this->assertSame('ok', $check['status']);
        $this->assertSame('', $check['fix']);
    }

    public function test_the_doctor_fix_line_repairs_the_company(): void
    {
        [, $owner, $member] = $this->companyWithOwnerAndMember();
        $owner->forceFill(['deactivated_at' => now()])->save();

        $this->artisan('snapsbrew:user:role', ['email' => $member->email, 'role' => 'owner'])->assertSuccessful();

        [, $json] = $this->runJson('snapsbrew:doctor');
        $this->assertSame('ok', collect($json['data']['checks'])->firstWhere('id', 'company_owners')['status']);
    }

    private function roleMigration(): object
    {
        return require database_path('migrations/2026_11_25_000000_add_company_role_to_users_table.php');
    }
}
