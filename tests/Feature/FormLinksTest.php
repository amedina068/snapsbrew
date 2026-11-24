<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Form;
use App\Models\Template;
use App\Models\TemplateCollection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The embedded forms guide promises two links for each form: the hosted page
 * and the submit URL for a custom UI. The form page must show both.
 */
class FormLinksTest extends TestCase
{
    use RefreshDatabase;

    private const LINKS = [
        'signed_url' => 'https://snapsbrew.test/embedded-form/form-uuid?signature=page',
        'submit_signed_url' => 'https://snapsbrew.test/api/v1/embedded-forms/form-uuid?signature=submit',
    ];

    public function test_the_template_form_page_shows_the_form_link_and_the_submit_link()
    {
        $user = User::factory()->create(['company_id' => Company::factory()]);
        $template = Template::factory()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'layers' => json_encode([]),
        ]);
        $form = Form::factory()->forTemplate($template)->create(self::LINKS + [
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'available_layers' => json_encode(['scope' => Form::SCOPE_TEMPLATE, 'layers' => []]),
        ]);

        $this->actingAs($user)
            ->get("/templates/{$template->uuid}/forms/{$form->uuid}")
            ->assertOk()
            ->assertSee(self::LINKS['signed_url'])
            ->assertSee(self::LINKS['submit_signed_url']);
    }

    public function test_the_collection_form_page_shows_the_form_link_and_the_submit_link()
    {
        $user = User::factory()->create(['company_id' => Company::factory()]);
        $collection = TemplateCollection::factory()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
        ]);
        $form = Form::factory()->forCollection($collection)->create(self::LINKS + [
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'available_layers' => json_encode(['scope' => Form::SCOPE_COLLECTION, 'layers' => []]),
        ]);

        $this->actingAs($user)
            ->get("/collections/{$collection->uuid}/forms/{$form->uuid}")
            ->assertOk()
            ->assertSee(self::LINKS['signed_url'])
            ->assertSee(self::LINKS['submit_signed_url']);
    }
}
