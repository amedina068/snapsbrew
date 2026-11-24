<?php

namespace Tests\Feature;

use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "Generate a snap" page. The welcome dialog says "That is your first
 * snap", so the page opens it only while the company has no snap yet.
 */
class SnapGeneratorPageTest extends TestCase
{
    use RefreshDatabase;

    private function pageFor(User $user): string
    {
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        return $this->actingAs($user)
            ->get("/templates/{$template->uuid}/generate")
            ->assertOk()
            ->getContent();
    }

    public function test_a_company_with_no_snap_gets_the_welcome_dialog(): void
    {
        $user = User::factory()->create();
        $user->company->forceFill(['first_snap_at' => null])->save();

        $this->assertStringContainsString(':first-snap="true"', $this->pageFor($user));
    }

    public function test_a_company_that_made_a_snap_does_not_get_the_welcome_dialog_again(): void
    {
        $user = User::factory()->create();
        $user->company->forceFill(['first_snap_at' => now()])->save();

        $this->assertStringContainsString(':first-snap="false"', $this->pageFor($user));
    }
}
