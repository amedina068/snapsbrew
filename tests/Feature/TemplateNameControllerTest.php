<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateNameControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUser(): User
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_rename_updates_title()
    {
        $user = $this->authenticatedUser();
        $template = Template::factory()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'title' => 'Original',
        ]);

        $response = $this->patchJson("/templates/{$template->uuid}/rename", [
            'title' => 'Renamed',
        ]);

        $response->assertOk();
        $this->assertSame('Renamed', $template->fresh()->title);
    }

    public function test_rename_validates_required_title()
    {
        $user = $this->authenticatedUser();
        $template = Template::factory()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
        ]);

        $this->patchJson("/templates/{$template->uuid}/rename", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');
    }

    public function test_cannot_rename_other_company_template()
    {
        $this->authenticatedUser();

        $other = User::factory()->create();
        $template = Template::factory()->create([
            'company_id' => $other->company_id,
            'user_id' => $other->id,
        ]);

        $this->patchJson("/templates/{$template->uuid}/rename", [
            'title' => 'Hacked',
        ])->assertNotFound();
    }
}
