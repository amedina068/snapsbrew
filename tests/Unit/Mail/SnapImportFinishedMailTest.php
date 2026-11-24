<?php

namespace Tests\Unit\Mail;

use App\Mail\SnapImportFinishedMail;
use App\Models\Company;
use App\Models\SnapImport;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SnapImportFinishedMailTest extends TestCase
{
    use RefreshDatabase;

    private function makeImport(string $status): SnapImport
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $template = Template::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        return SnapImport::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'template_id' => $template->id,
            'status' => $status,
        ]);
    }

    public function test_completed_subject()
    {
        $import = $this->makeImport(SnapImport::STATUS_COMPLETED);

        $built = (new SnapImportFinishedMail($import))->build();

        $this->assertSame('Your snap import is complete', $built->subject);
    }

    public function test_failed_subject()
    {
        $import = $this->makeImport(SnapImport::STATUS_FAILED);

        $built = (new SnapImportFinishedMail($import))->build();

        $this->assertSame('Your snap import failed', $built->subject);
    }

    public function test_canceled_subject()
    {
        $import = $this->makeImport(SnapImport::STATUS_CANCELED);

        $built = (new SnapImportFinishedMail($import))->build();

        $this->assertSame('Your snap import was canceled', $built->subject);
    }

    /**
     * Guards the SwiftMailer -> Symfony Mailer markdown rendering: the email
     * must still render to HTML containing the template title and status.
     */
    public function test_renders_html_with_template_title_and_status()
    {
        $import = $this->makeImport(SnapImport::STATUS_COMPLETED);
        $title = $import->template->title;

        $html = (new SnapImportFinishedMail($import))->render();

        $this->assertStringContainsString($title, $html);
        $this->assertStringContainsString('Completed', $html);
    }

    public function test_renders_without_throwing_when_template_is_deleted()
    {
        $import = $this->makeImport(SnapImport::STATUS_COMPLETED);
        $import->template->delete();
        $import->refresh();

        $html = (new SnapImportFinishedMail($import))->render();

        $this->assertIsString($html);
        $this->assertStringContainsString('your template', $html);
    }
}
