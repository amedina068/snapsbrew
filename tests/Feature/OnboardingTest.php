<?php

namespace Tests\Feature;

use App\Jobs\ProcessSnap;
use App\Mail\CompanyInvitationMail;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\Snap;
use App\Models\Template;
use App\Models\User;
use App\Models\UserEvent;
use App\Services\OnboardingSnapDispatcher;
use Database\Seeders\TemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::factory()->create();
    }

    public function test_user_without_onboarding_is_redirected_to_wizard(): void
    {
        $user = User::factory()->needsOnboarding()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/onboarding');
    }

    public function test_onboarded_user_can_reach_dashboard(): void
    {
        $company = $this->company();
        $user = User::factory()->create(['company_id' => $company->id]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_store_profile_updates_user_and_company(): void
    {
        $company = Company::factory()->create(['name' => 'Old Name']);
        $user = User::factory()->needsOnboarding()->create(['company_id' => $company->id]);

        $this->actingAs($user)
            ->post('/onboarding/profile', ['name' => 'Jane Diner', 'company_name' => 'Acme Co.'])
            ->assertOk();

        $this->assertEquals('Jane Diner', $user->fresh()->name);
        $this->assertEquals('Acme Co.', $company->fresh()->name);
    }

    public function test_store_categories_creates_a_sync_showcase_snap(): void
    {
        Bus::fake();
        $this->seed(TemplateSeeder::class);

        $company = $this->company();
        $user = User::factory()->needsOnboarding()->create(['company_id' => $company->id]);

        $this->actingAs($user)
            ->post('/onboarding/categories', ['categories' => ['social', 'blog']])
            ->assertOk();

        $this->assertEquals(['social', 'blog'], $user->fresh()->onboarding_categories);

        $snap = Snap::query()->where('metadata->onboarding', 'showcase')->first();
        $this->assertNotNull($snap);
        $this->assertFalse((bool) $snap->is_async, 'Showcase snap must be non-async so it stays out of the usage log.');
    }

    public function test_repicking_categories_redispatches_showcase_with_new_selection(): void
    {
        Bus::fake();
        $this->seed(TemplateSeeder::class);

        $company = $this->company();
        $user = User::factory()->needsOnboarding()->create(['company_id' => $company->id]);

        $this->actingAs($user)
            ->post('/onboarding/categories', ['categories' => ['social']])
            ->assertOk();

        $firstSnap = Snap::query()->where('metadata->onboarding', 'showcase')->firstOrFail();
        $this->assertStringContainsString('"layer":"company_name"', $firstSnap->variants);

        $this->actingAs($user)
            ->post('/onboarding/categories', ['categories' => ['certificate']])
            ->assertOk();

        // The first (pending) showcase is gone; a new one reflects the new pick.
        $this->assertNull(Snap::query()->find($firstSnap->id));

        $secondSnap = Snap::query()->where('metadata->onboarding', 'showcase')->firstOrFail();
        $this->assertNotEquals($firstSnap->id, $secondSnap->id);
    }

    public function test_completed_showcase_is_preserved_on_repick(): void
    {
        Bus::fake();
        $this->seed(TemplateSeeder::class);

        $company = $this->company();
        $user = User::factory()->needsOnboarding()->create(['company_id' => $company->id]);

        $this->actingAs($user)
            ->post('/onboarding/categories', ['categories' => ['social']])
            ->assertOk();

        $completed = Snap::query()->where('metadata->onboarding', 'showcase')->firstOrFail();
        $completed->forceFill(['status' => Snap::STATUS_COMPLETED])->save();

        $this->actingAs($user)
            ->post('/onboarding/categories', ['categories' => ['certificate']])
            ->assertOk();

        $this->assertNotNull(Snap::query()->find($completed->id), 'Completed showcase must not be deleted.');
        $this->assertEquals(2, Snap::query()->where('metadata->onboarding', 'showcase')->count());
    }

    public function test_finish_marks_onboarding_completed_and_records_event(): void
    {
        $user = User::factory()->needsOnboarding()->create();

        $this->actingAs($user)->post('/onboarding/finish')->assertOk();

        $this->assertNotNull($user->fresh()->onboarding_completed_at);
        $this->assertTrue(UserEvent::existsFor($user->company_id, UserEvent::ONBOARDING_COMPLETED));
    }

    public function test_finish_is_idempotent_for_already_onboarded_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/onboarding/finish')->assertOk();

        // No event recorded because the guard short-circuits before the event fires.
        $this->assertFalse(UserEvent::existsFor($user->company_id, UserEvent::ONBOARDING_COMPLETED));
    }

    public function test_invites_dedup_skips_existing_members_and_pending_invites(): void
    {
        Mail::fake();

        $company = $this->company();
        $inviter = User::factory()->needsOnboarding()->create(['company_id' => $company->id]);
        User::factory()->create(['company_id' => $company->id, 'email' => 'member@co.test']);
        CompanyInvitation::query()->create([
            'company_id' => $company->id,
            'email' => 'pending@co.test',
            'token' => str_repeat('a', 40),
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($inviter)
            ->post('/onboarding/invites', ['emails' => [
                'member@co.test',
                'pending@co.test',
                'fresh@co.test',
                'fresh@co.test',
            ]])
            ->assertOk();

        $this->assertEquals(2, CompanyInvitation::query()->where('company_id', $company->id)->count());
        Mail::assertQueued(CompanyInvitationMail::class, 1);
    }

    /**
     * The wizard maps `emails.N` back to the field at position N of the
     * filled fields. Pin the key shape that it reads.
     */
    public function test_an_invalid_invite_email_returns_an_error_for_its_position(): void
    {
        $user = User::factory()->create([
            'company_id' => $this->company()->id,
            'onboarding_completed_at' => null,
        ]);

        $this->actingAs($user)
            ->postJson('/onboarding/invites', ['emails' => ['ok@example.com', 'not-an-email']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['emails.1'])
            ->assertJsonMissingValidationErrors(['emails.0', 'emails']);
    }

    public function test_invites_endpoint_caps_at_25(): void
    {
        $user = User::factory()->needsOnboarding()->create();
        $emails = collect(range(1, 26))->map(fn ($i) => "u{$i}@co.test")->all();

        $this->actingAs($user)
            ->postJson('/onboarding/invites', ['emails' => $emails])
            ->assertStatus(422);
    }

    public function test_generate_snap_queues_an_async_snap(): void
    {
        Bus::fake();
        $this->seed(TemplateSeeder::class);

        $company = $this->company();
        $user = User::factory()->create(['company_id' => $company->id]);

        $template = Template::query()
            ->whereNull('user_id')
            ->where('title', 'like', 'Atomic Diner — %')
            ->firstOrFail();

        $response = $this->actingAs($user)
            ->postJson("/templates/{$template->uuid}/generate", ['fields' => []]);

        $response->assertOk()->assertJsonStructure(['snap_uuid', 'status_url']);

        $snap = Snap::query()->where('company_id', $company->id)->first();
        $this->assertNotNull($snap);
        $this->assertTrue((bool) $snap->is_async, 'Generated snap must be async so it records one render on completion.');

        Bus::assertDispatched(ProcessSnap::class);
    }

    public function test_status_endpoint_returns_pending_until_complete(): void
    {
        $company = $this->company();
        $user = User::factory()->create(['company_id' => $company->id]);

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        $snap = $template->snaps()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'variants' => json_encode([]),
            'type' => Snap::TYPE_IMAGE,
            'is_async' => true,
            'status' => Snap::STATUS_PENDING,
        ]);

        $this->actingAs($user)
            ->getJson("/snaps/{$snap->uuid}/status")
            ->assertOk()
            ->assertJson(['status' => Snap::STATUS_PENDING, 'image_url' => null]);

        $snap->forceFill(['status' => Snap::STATUS_COMPLETED])->save();

        $this->actingAs($user)
            ->getJson("/snaps/{$snap->uuid}/status")
            ->assertOk()
            ->assertJsonPath('status', Snap::STATUS_COMPLETED);
    }

    public function test_status_endpoint_scopes_to_caller_company(): void
    {
        $companyA = $this->company();
        $companyB = $this->company();

        $userA = User::factory()->create(['company_id' => $companyA->id]);
        $userB = User::factory()->create(['company_id' => $companyB->id]);

        $template = Template::factory()->create([
            'user_id' => $userA->id,
            'company_id' => $companyA->id,
        ]);

        $snap = $template->snaps()->create([
            'user_id' => $userA->id,
            'company_id' => $companyA->id,
            'variants' => json_encode([]),
            'type' => Snap::TYPE_IMAGE,
            'is_async' => true,
            'status' => Snap::STATUS_PENDING,
        ]);

        $this->actingAs($userB)
            ->getJson("/snaps/{$snap->uuid}/status")
            ->assertStatus(404);
    }

    public function test_categories_step_degrades_when_showcase_template_missing(): void
    {
        Bus::fake();

        // Simulate a deploy where the showcase template was never seeded.
        Template::query()->where('title', OnboardingSnapDispatcher::SHOWCASE_TITLE)->delete();

        $company = $this->company();
        $user = User::factory()->needsOnboarding()->create(['company_id' => $company->id]);

        $this->actingAs($user)
            ->post('/onboarding/categories', ['categories' => ['social']])
            ->assertOk();

        $this->assertEquals(0, Snap::query()->where('metadata->onboarding', 'showcase')->count());
    }
}
