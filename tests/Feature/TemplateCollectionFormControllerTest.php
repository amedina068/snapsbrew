<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Form;
use App\Models\Template;
use App\Models\TemplateCollection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateCollectionFormControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeam(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);

        return [$company, $user];
    }

    public function test_index_renders_forms_for_collection()
    {
        [$company, $user] = $this->actingTeam();

        $collection = TemplateCollection::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $this->get("/collections/{$collection->uuid}/forms")
            ->assertOk()
            ->assertViewIs('template-collections.forms.index');
    }

    public function test_create_redirects_when_collection_has_no_layers()
    {
        [$company, $user] = $this->actingTeam();

        $collection = TemplateCollection::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $template = Template::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'layers' => json_encode([]),
        ]);
        $collection->templates()->attach($template->id);

        $this->get("/collections/{$collection->uuid}/forms/new")
            ->assertRedirect("collections/{$collection->uuid}/forms");
    }

    public function test_show_returns_404_when_form_scope_is_template()
    {
        [$company, $user] = $this->actingTeam();

        $collection = TemplateCollection::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $form = Form::factory()->forCollection($collection)->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'available_layers' => json_encode([
                'scope' => Form::SCOPE_TEMPLATE,
                'layers' => [],
            ]),
        ]);

        $this->get("/collections/{$collection->uuid}/forms/{$form->uuid}")
            ->assertNotFound();
    }

    public function test_show_renders_when_form_scope_is_collection()
    {
        [$company, $user] = $this->actingTeam();

        $collection = TemplateCollection::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $form = Form::factory()->forCollection($collection)->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'available_layers' => json_encode([
                'scope' => Form::SCOPE_COLLECTION,
                'layers' => [],
            ]),
        ]);

        $this->get("/collections/{$collection->uuid}/forms/{$form->uuid}")
            ->assertOk()
            ->assertViewIs('template-collections.forms.show');
    }

    public function test_edit_renders_for_collection_form()
    {
        [$company, $user] = $this->actingTeam();

        $collection = TemplateCollection::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $template = Template::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'layers' => json_encode([
                ['key' => 'headline', 'type' => 'text', 'properties' => []],
            ]),
        ]);
        $collection->templates()->attach($template->id);

        $form = Form::factory()->forCollection($collection)->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'available_layers' => json_encode([
                'scope' => Form::SCOPE_COLLECTION,
                'layers' => [
                    ['template_uuid' => $template->uuid, 'layer_keys' => ['headline']],
                ],
            ]),
        ]);

        $this->get("/collections/{$collection->uuid}/forms/{$form->uuid}/edit")
            ->assertOk()
            ->assertViewIs('template-collections.forms.edit');
    }

    public function test_edit_returns_404_for_template_scoped_form()
    {
        [$company, $user] = $this->actingTeam();

        $collection = TemplateCollection::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $form = Form::factory()->forCollection($collection)->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'available_layers' => json_encode([
                'scope' => Form::SCOPE_TEMPLATE,
                'layers' => [],
            ]),
        ]);

        $this->get("/collections/{$collection->uuid}/forms/{$form->uuid}/edit")
            ->assertNotFound();
    }

    public function test_update_persists_changes_for_collection_form()
    {
        [$company, $user] = $this->actingTeam();

        $collection = TemplateCollection::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $template = Template::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'layers' => json_encode([
                ['key' => 'headline', 'type' => 'text', 'properties' => []],
                ['key' => 'subhead', 'type' => 'text', 'properties' => []],
            ]),
        ]);
        $collection->templates()->attach($template->id);

        $form = Form::factory()->forCollection($collection)->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'title' => 'Original title',
            'type' => 'image',
            'available_layers' => json_encode([
                'scope' => Form::SCOPE_COLLECTION,
                'layers' => [
                    ['template_uuid' => $template->uuid, 'layer_keys' => ['headline']],
                ],
            ]),
        ]);

        $this->patch("/collections/{$collection->uuid}/forms/{$form->uuid}", [
            'title' => 'New title',
            'type' => 'pdf',
            'form_layers' => [
                $template->uuid => [
                    'headline' => '1',
                    'subhead' => '1',
                ],
            ],
        ])->assertRedirect("collections/{$collection->uuid}/forms/{$form->uuid}");

        $fresh = $form->fresh();
        $this->assertSame('New title', $fresh->title);
        $this->assertSame('pdf', $fresh->type);

        $envelope = json_decode($fresh->available_layers, true);
        $this->assertSame(Form::SCOPE_COLLECTION, $envelope['scope']);
        $this->assertCount(1, $envelope['layers']);
        $this->assertEqualsCanonicalizing(
            ['headline', 'subhead'],
            $envelope['layers'][0]['layer_keys']
        );
    }

    public function test_update_validates_required_fields()
    {
        [$company, $user] = $this->actingTeam();

        $collection = TemplateCollection::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $form = Form::factory()->forCollection($collection)->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $this->from("/collections/{$collection->uuid}/forms/{$form->uuid}/edit")
            ->patch("/collections/{$collection->uuid}/forms/{$form->uuid}", [])
            ->assertSessionHasErrors(['title', 'form_layers', 'type']);
    }

    public function test_update_returns_404_for_other_company_form()
    {
        $this->actingTeam();

        $other = User::factory()->create();
        $otherCollection = TemplateCollection::factory()->create([
            'company_id' => $other->company_id,
            'user_id' => $other->id,
        ]);
        $otherForm = Form::factory()->forCollection($otherCollection)->create([
            'company_id' => $other->company_id,
            'user_id' => $other->id,
        ]);

        $this->patch("/collections/{$otherCollection->uuid}/forms/{$otherForm->uuid}", [
            'title' => 'Hacked',
            'type' => 'image',
            'form_layers' => ['x' => ['y' => '1']],
        ])->assertNotFound();
    }

    public function test_store_validates_required_fields()
    {
        [$company, $user] = $this->actingTeam();

        $collection = TemplateCollection::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $this->from("/collections/{$collection->uuid}/forms/new")
            ->post("/collections/{$collection->uuid}/forms", [])
            ->assertSessionHasErrors(['title', 'form_layers', 'type']);
    }
}
