<?php

namespace Tests\Feature\Api;

use App\Contracts\SnapRenderer;
use App\Models\Company;
use App\Models\Form;
use App\Models\Template;
use App\Models\TemplateCollection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

/**
 * The submit URL of an embedded form is public. A guest controls every layer
 * value, so an image layer accepts only an uploaded image file, and the text
 * and color values pass the same rules as the `variants` of the REST API.
 */
class EmbeddedFormUploadTest extends TestCase
{
    use RefreshDatabase;

    private FakeRenderer $renderer;

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->renderer = new FakeRenderer;
        $this->app->instance(SnapRenderer::class, $this->renderer);

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
    }

    private function template(): Template
    {
        return Template::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'layers' => json_encode([
                ['key' => 'logo', 'type' => 'image', 'properties' => [['name' => 'backgroundImage', 'value' => '']]],
                ['key' => 'bg', 'type' => 'empty', 'properties' => [['name' => 'backgroundColor', 'value' => '#fff']]],
                ['key' => 'headline', 'type' => 'text', 'properties' => [['name' => 'textContent', 'value' => 'Hello']]],
            ]),
        ]);
    }

    private function templateFormUrl(): string
    {
        $form = Form::factory()->forTemplate($this->template())->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'available_layers' => json_encode(['layers' => ['logo', 'bg', 'headline']]),
        ]);

        return URL::signedRoute('submitSignedEmbeddedForm', ['formUuid' => $form->uuid]);
    }

    /** @return array{0: string, 1: string} the submit URL and the request key prefix */
    private function collectionFormUrl(): array
    {
        $template = $this->template();
        $collection = TemplateCollection::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);
        $collection->templates()->attach($template);

        $form = Form::factory()->forCollection($collection)->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'available_layers' => json_encode([
                'scope' => Form::SCOPE_COLLECTION,
                'layers' => [['template_uuid' => $template->uuid, 'layer_keys' => ['logo', 'bg']]],
            ]),
        ]);

        return [URL::signedRoute('submitSignedEmbeddedForm', ['formUuid' => $form->uuid]), $template->uuid.'__'];
    }

    private function serverFile(): string
    {
        $path = storage_path('framework/testing/embedded-form-'.uniqid().'.key');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, 'SERVER-ONLY');

        return $path;
    }

    public function test_a_template_form_refuses_a_server_path_as_an_image_value()
    {
        $path = $this->serverFile();

        try {
            $this->postJson($this->templateFormUrl(), ['layers' => ['logo' => $path]])
                ->assertStatus(422)
                ->assertJsonValidationErrors('layers.logo');

            $this->assertFileExists($path);
            $this->assertSame(0, Media::query()->count());
            $this->assertSame([], $this->renderer->renderedDocuments);
        } finally {
            @unlink($path);
        }
    }

    public function test_a_collection_form_refuses_a_server_path_as_an_image_value()
    {
        [$url, $prefix] = $this->collectionFormUrl();
        $path = $this->serverFile();

        try {
            $this->postJson($url, ['layers' => [$prefix.'logo' => $path]])
                ->assertStatus(422)
                ->assertJsonValidationErrors('layers.'.$prefix.'logo');

            $this->assertFileExists($path);
            $this->assertSame(0, Media::query()->count());
        } finally {
            @unlink($path);
        }
    }

    public function test_an_image_layer_refuses_a_file_that_is_not_a_raster_image()
    {
        $url = $this->templateFormUrl();

        foreach ([
            'x.html' => '<script>alert(1)</script>',
            'x.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        ] as $name => $body) {
            $this->post($url, ['layers' => ['logo' => UploadedFile::fake()->createWithContent($name, $body)]], ['Accept' => 'application/json'])
                ->assertStatus(422)
                ->assertJsonValidationErrors('layers.logo');
        }

        $this->assertSame(0, Media::query()->count());
    }

    public function test_an_image_upload_is_stored_under_a_random_name_with_the_detected_extension()
    {
        $this->post($this->templateFormUrl(), [
            'layers' => ['logo' => UploadedFile::fake()->image('My Logo.png', 10, 10)],
        ], ['Accept' => 'application/json'])->assertCreated();

        $media = $this->user->fresh()->getMedia('imagesMedia')->sole();
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.png$/', $media->file_name);
        $this->assertTrue(Storage::disk('public')->exists($media->getPathRelativeToRoot()));
    }

    public function test_a_collection_form_stores_an_image_upload()
    {
        [$url, $prefix] = $this->collectionFormUrl();

        $this->post($url, [
            'layers' => [$prefix.'logo' => UploadedFile::fake()->image('logo.jpg', 10, 10)],
        ], ['Accept' => 'application/json'])->assertCreated();

        $media = $this->user->fresh()->getMedia('imagesMedia')->sole();
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.jpg$/', $media->file_name);
    }

    public function test_a_color_layer_refuses_a_css_url()
    {
        $this->postJson($this->templateFormUrl(), ['layers' => ['bg' => 'url(http://10.0.0.5:8080/ping)']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('layers.bg');

        $this->assertSame([], $this->renderer->renderedDocuments);
    }

    public function test_a_collection_color_layer_refuses_a_css_url()
    {
        [$url, $prefix] = $this->collectionFormUrl();

        $this->postJson($url, ['layers' => [$prefix.'bg' => 'url(http://10.0.0.5:8080/ping)']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('layers.'.$prefix.'bg');
    }

    public function test_valid_text_and_color_values_still_render()
    {
        $this->postJson($this->templateFormUrl(), ['layers' => ['bg' => '#ff0000', 'headline' => 'Summer sale']])
            ->assertCreated();

        $document = end($this->renderer->renderedDocuments);
        $this->assertStringContainsString('#ff0000', $document);
        $this->assertStringContainsString('Summer sale', $document);
    }

    public function test_a_text_layer_refuses_a_non_string_value()
    {
        $this->postJson($this->templateFormUrl(), ['layers' => ['headline' => ['nested' => 'x']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('layers.headline');
    }
}
