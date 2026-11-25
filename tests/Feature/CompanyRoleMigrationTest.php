<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The migration adds `users.company_role`. Every existing row gets the
 * default `member`, and the backfill then makes the founder of each company
 * (the user with the lowest id) an `owner`.
 *
 * The test calls the backfill and not `up()`. A schema change on MySQL ends
 * the transaction of RefreshDatabase, so the test changes data only.
 */
class CompanyRoleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_row_without_a_role_gets_the_member_default(): void
    {
        $company = Company::factory()->create();

        $id = DB::table('users')->insertGetId([
            'name' => 'Raw',
            'email' => 'raw@example.com',
            'password' => 'x',
            'company_id' => $company->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(User::COMPANY_ROLE_MEMBER, DB::table('users')->where('id', $id)->value('company_role'));
    }

    public function test_the_backfill_makes_the_founder_of_each_company_an_owner(): void
    {
        $acme = Company::factory()->create();
        $globex = Company::factory()->create();
        $empty = Company::factory()->create();

        $acmeFounder = User::factory()->create(['company_id' => $acme->id]);
        $globexFounder = User::factory()->create(['company_id' => $globex->id]);
        $acmeMember = User::factory()->create(['company_id' => $acme->id]);
        $globexMember = User::factory()->create(['company_id' => $globex->id]);
        $acmeLate = User::factory()->create(['company_id' => $acme->id]);

        // The state right after the column is added: every row has the default.
        DB::table('users')->update(['company_role' => User::COMPANY_ROLE_MEMBER]);

        $this->migration()->backfill();

        $roles = DB::table('users')->pluck('company_role', 'id');
        $this->assertSame(User::COMPANY_ROLE_OWNER, $roles[$acmeFounder->id]);
        $this->assertSame(User::COMPANY_ROLE_OWNER, $roles[$globexFounder->id]);
        $this->assertSame(User::COMPANY_ROLE_MEMBER, $roles[$acmeMember->id]);
        $this->assertSame(User::COMPANY_ROLE_MEMBER, $roles[$globexMember->id]);
        $this->assertSame(User::COMPANY_ROLE_MEMBER, $roles[$acmeLate->id]);
        $this->assertSame(0, User::query()->where('company_id', $empty->id)->count());
    }

    public function test_the_backfill_can_run_two_times(): void
    {
        $company = Company::factory()->create();
        $founder = User::factory()->create(['company_id' => $company->id]);
        $member = User::factory()->create(['company_id' => $company->id]);

        $this->migration()->backfill();
        $this->migration()->backfill();

        $this->assertTrue($founder->fresh()->isCompanyOwner());
        $this->assertFalse($member->fresh()->isCompanyOwner());
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_25_000000_add_company_role_to_users_table.php');
    }
}
