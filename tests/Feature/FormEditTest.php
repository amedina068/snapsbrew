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
 * Edit and update of an embedded form, for a template form and for a
 * collection form.
 *
 * The template controller once compared `formable_type` (a model class name)
 * with `Form::SCOPE_TEMPLATE` (the string `template`), so edit and update of
 * every template form returned 404.
 */
class FormEditTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
        $this->actingAs($this->user);
    }

    private function template(): Template
    {
        return Template::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'layers' => json_encode([
                ['key' => 'headline', 'type' => 'text', 'properties' => []],
                ['key' => 'subhead', 'type' => 'text', 'properties' => []],
            ]),
        ]);
    }

    private function templateForm(Template $template): Form
    {
        return Form::factory()->forTemplate($template)->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'title' => 'Original title',
            'type' => 'image',
            'available_layers' => json_encode([
                'scope' => Form::SCOPE_TEMPLATE,
                'layers' => ['headline'],
            ]),
        ]);
    }

    private function collectionForm(TemplateCollection $collection, Template $template): Form
    {
        return Form::factory()->forCollection($collection)->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'title' => 'Original title',
            'type' => 'image',
            'available_layers' => json_encode([
                'scope' => Form::SCOPE_COLLECTION,
                'layers' => [
                    ['template_uuid' => $template->uuid, 'layer_keys' => ['headline']],
                ],
            ]),
        ]);
    }

    private function collectionWith(Template $template): TemplateCollection
    {
        $collection = TemplateCollection::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);
        $collection->templates()->attach($template->id);

        return $collection;
    }

    public function test_edit_renders_for_template_form(): void
    {
        $template = $this->template();
        $form = $this->templateForm($template);

        $this->get("/templates/{$template->uuid}/forms/{$form->uuid}/edit")
            ->assertOk()
            ->assertViewIs('forms.edit')
            ->assertViewHas('selectedLayers', ['headline']);
    }

    public function test_update_persists_changes_for_template_form(): void
    {
        $template = $this->template();
        $form = $this->templateForm($template);

        $this->patch("/templates/{$template->uuid}/forms/{$form->uuid}", [
            'title' => 'New title',
            'type' => 'pdf',
            'form_layers' => ['headline' => '1', 'subhead' => '1'],
        ])->assertRedirect("templates/{$template->uuid}/forms/{$form->uuid}");

        $fresh = $form->fresh();
        $this->assertSame('New title', $fresh->title);
        $this->assertSame('pdf', $fresh->type);
        $this->assertSame(Form::SCOPE_TEMPLATE, $fresh->scope());
        $this->assertSame(['headline', 'subhead'], $fresh->configuredLayers());
    }

    public function test_edit_and_update_return_404_for_template_form_with_collection_scope(): void
    {
        $template = $this->template();
        $form = $this->templateForm($template);
        $form->update(['available_layers' => json_encode(['scope' => Form::SCOPE_COLLECTION, 'layers' => []])]);

        $this->get("/templates/{$template->uuid}/forms/{$form->uuid}/edit")->assertNotFound();

        $this->patch("/templates/{$template->uuid}/forms/{$form->uuid}", [
            'title' => 'New title',
            'type' => 'pdf',
            'form_layers' => ['headline' => '1'],
        ])->assertNotFound();
    }

    public function test_template_routes_return_404_for_collection_form(): void
    {
        $template = $this->template();
        $form = $this->collectionForm($this->collectionWith($template), $template);

        $this->get("/templates/{$template->uuid}/forms/{$form->uuid}/edit")->assertNotFound();
    }

    public function test_edit_renders_for_collection_form(): void
    {
        $template = $this->template();
        $collection = $this->collectionWith($template);
        $form = $this->collectionForm($collection, $template);

        $this->get("/collections/{$collection->uuid}/forms/{$form->uuid}/edit")
            ->assertOk()
            ->assertViewIs('template-collections.forms.edit')
            ->assertViewHas('selectedLayers', [$template->uuid => ['headline']]);
    }

    public function test_update_persists_changes_for_collection_form(): void
    {
        $template = $this->template();
        $collection = $this->collectionWith($template);
        $form = $this->collectionForm($collection, $template);

        $this->patch("/collections/{$collection->uuid}/forms/{$form->uuid}", [
            'title' => 'New title',
            'type' => 'pdf',
            'form_layers' => [$template->uuid => ['subhead' => '1']],
        ])->assertRedirect("collections/{$collection->uuid}/forms/{$form->uuid}");

        $fresh = $form->fresh();
        $this->assertSame('New title', $fresh->title);
        $this->assertSame(Form::SCOPE_COLLECTION, $fresh->scope());
        $this->assertSame(['subhead'], $fresh->configuredLayers()[0]['layer_keys']);
    }

    public function test_the_template_form_edit_page_checks_the_stored_layers(): void
    {
        $template = $this->template();
        $form = $this->templateForm($template);

        $html = $this->get("/templates/{$template->uuid}/forms/{$form->uuid}/edit")->getContent();

        $this->assertMatchesRegularExpression('/id="layer_headline"\s+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="layer_subhead"\s+checked/', $html);
    }

    public function test_a_template_form_with_no_layer_says_so_and_keeps_the_title(): void
    {
        $template = $this->template();

        $this->from("/templates/{$template->uuid}/forms/new")
            ->post("/templates/{$template->uuid}/forms", ['title' => 'Kept title', 'type' => 'image'])
            ->assertRedirect("/templates/{$template->uuid}/forms/new")
            ->assertSessionHasErrors(['form_layers' => 'Select at least one layer.']);

        $this->get("/templates/{$template->uuid}/forms/new")
            ->assertSee('Select at least one layer.')
            ->assertSee('value="Kept title"', false)
            ->assertDontSee('You have to select at least one layer');
    }

    public function test_a_template_form_title_error_names_the_title(): void
    {
        $template = $this->template();

        $this->from("/templates/{$template->uuid}/forms/new")
            ->post("/templates/{$template->uuid}/forms", [
                'title' => str_repeat('a', 256),
                'type' => 'image',
                'form_layers' => ['headline' => '1'],
            ])
            ->assertSessionHasErrors('title');

        $this->get("/templates/{$template->uuid}/forms/new")
            ->assertSee('id="title-error"', false)
            ->assertSee('aria-describedby="title-error"', false);
    }

    public function test_a_collection_form_title_error_shows_next_to_the_title(): void
    {
        $template = $this->template();
        $collection = $this->collectionWith($template);

        $this->from("/collections/{$collection->uuid}/forms/new")
            ->post("/collections/{$collection->uuid}/forms", [
                'title' => '',
                'type' => 'image',
                'form_layers' => [$template->uuid => ['headline' => '1']],
            ])
            ->assertSessionHasErrors('title');

        $this->get("/collections/{$collection->uuid}/forms/new")
            ->assertSee('id="title-error"', false)
            ->assertSee('<legend class="text-sm font-semibold text-gray-800">Templates and layers</legend>', false);
    }
}
