<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\Form;
use App\Models\Template;
use App\Models\TemplateCollection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormTest extends TestCase
{
    use RefreshDatabase;

    public function test_form_belongs_to_user()
    {
        $user = User::factory()->create();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);
        $form = Form::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'formable_id' => $template->id,
            'formable_type' => Template::class,
        ]);

        $this->assertInstanceOf(User::class, $form->user);
    }

    public function test_form_belongs_to_company()
    {
        $form = Form::factory()->create();

        $this->assertInstanceOf(Company::class, $form->company);
    }

    public function test_form_morphs_to_template()
    {
        $user = User::factory()->create();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);
        $form = Form::factory()->forTemplate($template)->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $this->assertInstanceOf(Template::class, $form->formable);
        $this->assertEquals($template->id, $form->formable->id);
    }

    public function test_form_morphs_to_collection()
    {
        $user = User::factory()->create();
        $collection = TemplateCollection::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);
        $form = Form::factory()->forCollection($collection)->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $this->assertInstanceOf(TemplateCollection::class, $form->formable);
    }

    public function test_is_for_template()
    {
        $form = Form::factory()->create(['formable_type' => Template::class]);

        $this->assertTrue($form->isForTemplate());
        $this->assertFalse($form->isForCollection());
    }

    public function test_is_for_collection()
    {
        $user = User::factory()->create();
        $collection = TemplateCollection::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);
        $form = Form::factory()->forCollection($collection)->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $this->assertTrue($form->isForCollection());
        $this->assertFalse($form->isForTemplate());
    }

    public function test_scope_for_formable()
    {
        $user = User::factory()->create();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);
        $otherTemplate = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        Form::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'formable_id' => $template->id,
            'formable_type' => Template::class,
        ]);
        Form::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'formable_id' => $otherTemplate->id,
            'formable_type' => Template::class,
        ]);

        $forms = Form::forFormable($template)->get();

        $this->assertCount(1, $forms);
        $this->assertEquals($template->id, $forms->first()->formable_id);
    }

    public function test_scope_constants()
    {
        $this->assertEquals('template', Form::SCOPE_TEMPLATE);
        $this->assertEquals('collection', Form::SCOPE_COLLECTION);
    }

    public function test_scope_reads_envelope_for_template_form()
    {
        $form = Form::factory()->create([
            'available_layers' => json_encode([
                'scope' => Form::SCOPE_TEMPLATE,
                'layers' => ['headline'],
            ]),
        ]);

        $this->assertSame(Form::SCOPE_TEMPLATE, $form->scope());
    }

    public function test_scope_reads_envelope_for_collection_form()
    {
        $user = User::factory()->create();
        $collection = TemplateCollection::factory()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
        ]);

        $form = Form::factory()->forCollection($collection)->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'available_layers' => json_encode([
                'scope' => Form::SCOPE_COLLECTION,
                'layers' => [],
            ]),
        ]);

        $this->assertSame(Form::SCOPE_COLLECTION, $form->scope());
    }

    public function test_scope_falls_back_to_formable_type_when_envelope_missing_scope()
    {
        $form = Form::factory()->create([
            'available_layers' => json_encode(['layers' => ['headline']]),
        ]);

        $this->assertSame(Form::SCOPE_TEMPLATE, $form->scope());
    }

    public function test_configured_layers_returns_empty_array_for_template_form_with_no_layers()
    {
        $form = Form::factory()->create([
            'available_layers' => json_encode([
                'scope' => Form::SCOPE_TEMPLATE,
                'layers' => [],
            ]),
        ]);

        $this->assertSame([], $form->configuredLayers());
    }

    public function test_configured_layers_returns_per_template_config_for_collection_form()
    {
        $user = User::factory()->create();
        $collection = TemplateCollection::factory()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
        ]);

        $form = Form::factory()->forCollection($collection)->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'available_layers' => json_encode([
                'scope' => Form::SCOPE_COLLECTION,
                'layers' => [
                    ['template_uuid' => 'a', 'layer_keys' => ['headline', 'subhead']],
                    ['template_uuid' => 'b', 'layer_keys' => ['logo']],
                ],
            ]),
        ]);

        $this->assertSame([
            ['template_uuid' => 'a', 'layer_keys' => ['headline', 'subhead']],
            ['template_uuid' => 'b', 'layer_keys' => ['logo']],
        ], $form->configuredLayers());
    }

    public function test_configured_layers_returns_flat_keys_for_template_form()
    {
        $form = Form::factory()->create([
            'available_layers' => json_encode([
                'scope' => Form::SCOPE_TEMPLATE,
                'layers' => ['headline', 'subhead'],
            ]),
        ]);

        $this->assertSame(['headline', 'subhead'], $form->configuredLayers());
    }

    public function test_form_has_uuid()
    {
        $form = Form::factory()->create();

        $this->assertNotNull($form->uuid);
    }
}
