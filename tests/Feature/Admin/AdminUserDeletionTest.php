<?php

namespace Tests\Feature\Admin;

use App\Models\ApiLog;
use App\Models\Company;
use App\Models\Screenshot;
use App\Models\Snap;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AdminUserDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_deletes_a_user_and_the_personal_data_of_the_user(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);
        $colleague = User::factory()->create();
        $user = User::factory()->create(['company_id' => $colleague->company_id, 'email' => 'leaving@example.com']);
        $user->createToken('cli', ['api_requests']);
        $user->createSignedUrlToken();
        ApiLog::factory()->create(['user_id' => $user->id]);
        $screenshot = Screenshot::factory()->create(['user_id' => $user->id, 'company_id' => $user->company_id]);
        activity()->causedBy($user)->log('created');
        $template = Template::factory()->create(['user_id' => $user->id, 'company_id' => $user->company_id]);

        $this->actingAs($owner)
            ->delete("/admin/users/{$user->id}")
            ->assertRedirect()
            ->assertSessionHas('status', 'leaving@example.com is deleted.');

        $this->assertModelMissing($user);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseMissing('api_keys', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('api_logs', ['user_id' => $user->id]);
        $this->assertModelMissing($screenshot);
        $this->assertSame(0, Activity::query()->causedBy($user)->count());

        // The company keeps its templates, because the other members use them.
        $this->assertModelExists($template);
        $this->assertModelExists($colleague->company);
    }

    public function test_the_delete_of_the_last_member_also_deletes_the_company_and_its_content(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);
        $user = User::factory()->create();
        $company = $user->company;
        $template = Template::factory()->create(['user_id' => $user->id, 'company_id' => $company->id]);
        $snap = Snap::factory()->completed()->create(['template_id' => $template->id, 'company_id' => $company->id, 'user_id' => $user->id]);

        $this->actingAs($owner)->delete("/admin/users/{$user->id}")->assertRedirect();

        $this->assertModelMissing($user);
        $this->assertModelMissing($company);
        $this->assertModelMissing($template);
        $this->assertModelMissing($snap);
        $this->assertModelExists($owner->company);
    }

    public function test_the_owner_cannot_delete_an_owner(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);
        $otherOwner = User::factory()->create(['is_owner' => true]);

        $this->actingAs($owner)->delete("/admin/users/{$otherOwner->id}")->assertForbidden();
        $this->actingAs($owner)->delete("/admin/users/{$owner->id}")->assertForbidden();

        $this->assertModelExists($otherOwner);
        $this->assertModelExists($owner);
    }

    public function test_the_list_offers_a_delete_with_a_confirmation_next_to_deactivation(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);
        $active = User::factory()->create(['email' => 'active@example.com']);
        $inactive = User::factory()->create(['email' => 'inactive@example.com', 'deactivated_at' => now()]);

        $response = $this->actingAs($owner)->get('/admin/users')->assertOk();

        foreach ([$active, $inactive] as $member) {
            $response->assertSee('action="'.url("/admin/users/{$member->id}").'"', false);
        }

        $response->assertSee('Delete active@example.com and the data of this user?', false);
    }

    public function test_a_company_record_outlives_a_member_that_is_not_the_last(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);
        $company = Company::factory()->create();
        $leaving = User::factory()->create(['company_id' => $company->id]);
        User::factory()->create(['company_id' => $company->id]);

        $this->actingAs($owner)->delete("/admin/users/{$leaving->id}")->assertRedirect();

        $this->assertModelExists($company);
    }
}
