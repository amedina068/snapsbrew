<?php

namespace Tests\Feature\Api;

use App\Contracts\SnapRenderer;
use App\Models\Company;
use App\Models\Form;
use App\Models\Template;
use App\Models\User;
use App\Support\ApiDocs\ApiSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

class EmbeddedFormApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_signature_returns_404()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $template = Template::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);
        $form = Form::factory()->forTemplate($template)->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $this->postJson("/api/v1/embedded-forms/{$form->uuid}?signature=invalid")
            ->assertNotFound();
    }

    /**
     * The controller passes the resource to `response()->json()`, which does
     * not wrap it in `data`. The documented example must say the same.
     */
    public function test_a_template_form_returns_the_documented_snap_with_no_data_wrapper()
    {
        Storage::fake('public');
        $this->app->instance(SnapRenderer::class, new FakeRenderer);

        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $template = Template::factory()->create(['user_id' => $user->id, 'company_id' => $company->id]);
        $form = Form::factory()->forTemplate($template)->create(['company_id' => $company->id, 'user_id' => $user->id]);

        $response = $this->postJson(URL::signedRoute('submitSignedEmbeddedForm', ['formUuid' => $form->uuid]))
            ->assertCreated()
            ->assertJsonMissingPath('data');

        $endpoint = collect(ApiSpec::endpoints())->firstWhere('id', 'embedded-forms.store');
        $example = collect($endpoint['responses'])->firstWhere('status', 201)['example'];

        $this->assertEqualsCanonicalizing(array_keys($example), array_keys($response->json()));
    }
}
