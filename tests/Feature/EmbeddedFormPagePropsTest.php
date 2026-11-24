<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Form;
use App\Models\Template;
use App\Models\TemplateCollection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The embedded form page is public. Its HTML must carry only what the form
 * widget reads: the form title, the submit URL and the configured layers.
 * It must not carry the account of the form owner or the stored rows of the
 * templates behind the form.
 */
class EmbeddedFormPagePropsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        $this->owner = User::factory()->create([
            'company_id' => $company->id,
            'email' => 'owner-private@example.test',
            'name' => 'Private Owner Name',
        ]);
    }

    private function template(string $hiddenText): Template
    {
        return Template::factory()->create([
            'user_id' => $this->owner->id,
            'company_id' => $this->owner->company_id,
            'layers' => json_encode([
                ['key' => 'headline', 'type' => 'text', 'properties' => [['name' => 'textContent', 'value' => 'Public default']]],
                ['key' => 'internal', 'type' => 'text', 'properties' => [['name' => 'textContent', 'value' => $hiddenText]]],
            ]),
        ]);
    }

    private function page(Form $form): string
    {
        return $this->get(URL::signedRoute('signedEmbeddedForm', ['formUuid' => $form->uuid]))
            ->assertOk()
            ->getContent();
    }

    private function assertNoPrivateData(string $html, string $hiddenText): void
    {
        $this->assertStringNotContainsString('owner-private@example.test', $html);
        $this->assertStringNotContainsString('Private Owner Name', $html);
        $this->assertStringNotContainsString($hiddenText, $html);
        $this->assertStringNotContainsString('company_id', $html);
        $this->assertStringNotContainsString('available_layers', $html);
    }

    public function test_a_template_form_page_shows_only_the_widget_fields()
    {
        $template = $this->template('Layer text that is not on the form');
        $form = Form::factory()->forTemplate($template)->create([
            'company_id' => $this->owner->company_id,
            'user_id' => $this->owner->id,
            'title' => 'Make your card',
            'available_layers' => json_encode(['layers' => ['headline']]),
        ]);

        $html = $this->page($form);

        $this->assertNoPrivateData($html, 'Layer text that is not on the form');
        $this->assertStringContainsString('Make your card', $html);
        $this->assertStringContainsString('Public default', $html);
        $this->assertStringContainsString('submit_signed_url', $html);
    }

    public function test_a_collection_form_page_shows_only_the_widget_fields()
    {
        $template = $this->template('Collection layer that is not on the form');
        $collection = TemplateCollection::create([
            'user_id' => $this->owner->id,
            'company_id' => $this->owner->company_id,
            'name' => 'Collection',
        ]);
        $collection->templates()->attach($template->id);

        $form = Form::factory()->forCollection($collection)->create([
            'company_id' => $this->owner->company_id,
            'user_id' => $this->owner->id,
            'title' => 'Make your set',
            'available_layers' => json_encode(['layers' => [
                ['template_uuid' => $template->uuid, 'layer_keys' => ['headline']],
            ]]),
        ]);

        $html = $this->page($form);

        $this->assertNoPrivateData($html, 'Collection layer that is not on the form');
        $this->assertStringContainsString('Make your set', $html);
        $this->assertStringContainsString('Public default', $html);
    }
}
