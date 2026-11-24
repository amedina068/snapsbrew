<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Snap;
use App\Models\SnapImport;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SnapImportControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeam(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);

        return [$company, $user];
    }

    public function test_index_renders_imports_for_template()
    {
        [$company, $user] = $this->actingTeam();

        $template = Template::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        SnapImport::factory()->count(2)->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'template_id' => $template->id,
        ]);

        $this->get("/templates/{$template->uuid}/imports")
            ->assertOk()
            ->assertViewIs('templates.snap-imports.index');
    }

    public function test_create_renders_upload_form()
    {
        [$company, $user] = $this->actingTeam();

        $template = Template::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $this->get("/templates/{$template->uuid}/imports/create")
            ->assertOk()
            ->assertViewIs('templates.snap-imports.create');
    }

    public function test_cannot_view_other_company_template_imports()
    {
        $this->actingTeam();

        $otherUser = User::factory()->create();
        $template = Template::factory()->create([
            'company_id' => $otherUser->company_id,
            'user_id' => $otherUser->id,
        ]);

        $this->get("/templates/{$template->uuid}/imports")->assertNotFound();
    }

    public function test_status_returns_progress_snapshot()
    {
        [$company, $user] = $this->actingTeam();

        $template = Template::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $import = SnapImport::factory()->processing()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'template_id' => $template->id,
            'total_rows' => 5,
        ]);

        $this->getJson("/imports/{$import->uuid}/status")
            ->assertOk()
            ->assertJsonStructure([
                'import' => ['uuid', 'status', 'total_rows', 'processed_rows'],
            ]);
    }

    public function test_cancel_marks_import_and_pending_snaps_canceled()
    {
        Bus::fake();

        [$company, $user] = $this->actingTeam();

        $template = Template::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $import = SnapImport::factory()->processing()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'template_id' => $template->id,
            'total_rows' => 2,
        ]);

        $pending = Snap::factory()->create([
            'company_id' => $company->id,
            'template_id' => $template->id,
            'user_id' => $user->id,
            'snap_import_id' => $import->id,
            'status' => Snap::STATUS_PENDING,
        ]);

        $this->postJson("/imports/{$import->uuid}/cancel")
            ->assertOk();

        $this->assertSame(SnapImport::STATUS_CANCELED, $import->fresh()->status);
        $this->assertSame(Snap::STATUS_CANCELED, $pending->fresh()->status);
    }
}
