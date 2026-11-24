<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\Form;
use App\Models\Screenshot;
use App\Models\Snap;
use App\Models\Template;
use App\Models\TemplateCollection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    use RefreshDatabase;

    private function createCompanyWithUser(string $role = User::ROLE_CUSTOMER): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create([
            'company_id' => $company->id,
            'role' => $role,
        ]);

        return [$company, $user];
    }

    public function test_company_has_many_users()
    {
        $company = Company::factory()->create();
        User::factory()->count(3)->create(['company_id' => $company->id]);

        $this->assertCount(3, $company->users);
    }

    public function test_company_has_many_templates()
    {
        [$company, $user] = $this->createCompanyWithUser();
        Template::factory()->count(2)->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $this->assertCount(2, $company->templates);
    }

    public function test_company_has_many_snaps()
    {
        [$company, $user] = $this->createCompanyWithUser();
        $template = Template::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);
        Snap::factory()->count(3)->create([
            'company_id' => $company->id,
            'template_id' => $template->id,
        ]);

        $this->assertCount(3, $company->snaps);
    }

    public function test_company_has_many_screenshots()
    {
        [$company, $user] = $this->createCompanyWithUser();
        Screenshot::factory()->count(2)->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $this->assertCount(2, $company->screenshots);
    }

    public function test_company_has_many_forms()
    {
        [$company, $user] = $this->createCompanyWithUser();
        $template = Template::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);
        Form::factory()->count(2)->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'formable_id' => $template->id,
            'formable_type' => Template::class,
        ]);

        $this->assertCount(2, $company->forms);
    }

    public function test_company_has_many_template_collections()
    {
        [$company, $user] = $this->createCompanyWithUser();
        TemplateCollection::factory()->count(2)->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $this->assertCount(2, $company->templateCollections);
    }

    public function test_company_has_many_invitations()
    {
        [$company, $user] = $this->createCompanyWithUser();
        CompanyInvitation::factory()->count(2)->create([
            'company_id' => $company->id,
            'invited_by' => $user->id,
        ]);

        $this->assertCount(2, $company->invitations);
    }

    public function test_company_has_uuid()
    {
        $company = Company::factory()->create();

        $this->assertNotNull($company->uuid);
        $this->assertIsString($company->uuid);
    }
}
