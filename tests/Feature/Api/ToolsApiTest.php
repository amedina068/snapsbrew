<?php

namespace Tests\Feature\Api;

use App\Jobs\RemoveFreeToolSnap;
use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use App\Services\TemplateVersioning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Spatie\Browsershot\Browsershot;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class ToolsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.free_tools.enabled' => true]);
    }

    /**
     * A valid 20x20 PNG, base64 encoded (decodable by GD for media conversions).
     */
    private const FAKE_PNG = 'iVBORw0KGgoAAAANSUhEUgAAABQAAAAUCAIAAAAC64paAAAACXBIWXMAAA7EAAAOxAGV'
        .'Kw4bAAAAG0lEQVQ4jWP8z0A+YKJA76jmUc2jmkc1U0EzACKcASfaaIZkAAAAAElFTkSuQmCC';

    /**
     * Make Browsershot return stub media instead of rendering a real page.
     */
    private function fakeBrowsershot(): void
    {
        $browsershot = Mockery::mock('overload:'.Browsershot::class);
        $fluent = [
            'html', 'url', 'setRemoteInstance', 'setOption', 'setNodeBinary', 'setNpmBinary', 'setNodeModulePath', 'setChromePath', 'noSandbox',
            'windowSize', 'waitUntilNetworkIdle', 'deviceScaleFactor',
            'fullPage', 'preventUnsuccessfulResponse', 'showBackground',
            'margins', 'paperSize', 'pages',
            'setScreenshotType', 'transparentBackground',
        ];
        foreach ($fluent as $method) {
            $browsershot->shouldReceive($method)->andReturnSelf();
        }
        $browsershot->shouldReceive('base64Screenshot')->andReturn(self::FAKE_PNG);
        $browsershot->shouldReceive('base64Pdf')->andReturn(base64_encode('%PDF-1.4 fake'));
    }

    private function createTemplate(): Template
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        config(['services.free_tools.templates' => [
            'certificate' => $template->uuid,
            'testimonial' => $template->uuid,
            'twitter_graph' => $template->uuid,
        ]]);

        return $template;
    }

    public function test_certificate_creator_generates_a_pdf_snap()
    {
        Storage::fake('public');
        Bus::fake();
        $this->fakeBrowsershot();

        $template = $this->createTemplate();

        $response = $this->postJson('/api/v1/tools/certificate-creator', [
            'template_uuid' => $template->uuid,
            'awardee' => 'Jane Doe',
            'awarder' => 'Acme Corp',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['image_path']);
        Bus::assertDispatched(RemoveFreeToolSnap::class);
    }

    public function test_certificate_creator_writes_the_request_text_into_the_layers()
    {
        Storage::fake('public');
        Bus::fake();
        $this->fakeBrowsershot();

        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'layers' => '[{"key":"awardee","type":"text","properties":[{"name":"textContent","value":"DEFAULTAWARDEE"}]}]',
        ]);

        config(['services.free_tools.templates.certificate' => $template->uuid]);

        $this->postJson('/api/v1/tools/certificate-creator', [
            'awardee' => 'Jane Doe',
        ])->assertStatus(200);

        $content = $template->snaps()->latest()->first()->content;
        $this->assertStringContainsString('Jane Doe', $content);
        $this->assertStringNotContainsString('DEFAULTAWARDEE', $content);
    }

    public function test_testimonial_image_creator_generates_an_image_snap()
    {
        Storage::fake('public');
        $this->fakeBrowsershot();

        $template = $this->createTemplate();

        $response = $this->postJson('/api/v1/tools/testimonial-image-creator', [
            'template_uuid' => $template->uuid,
            'author' => 'John Smith',
            'testimonial' => 'Great product!',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['image_path']);
    }

    public function test_free_tool_renders_published_layers_not_draft()
    {
        // T-801: the free-tool render surfaces must honour the F8 seam — a
        // published template whose draft is later edited still renders the
        // published version through the public tool.
        Storage::fake('public');
        $this->fakeBrowsershot();

        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'layers' => '[{"key":"brand","type":"text","properties":[{"name":"textContent","value":"PUBLISHEDBRAND"}]}]',
        ]);

        config(['services.free_tools.templates.testimonial' => $template->uuid]);

        app(TemplateVersioning::class)->publish($template->fresh());

        // Edit the draft AFTER publishing.
        $template->fresh()->forceFill([
            'layers' => '[{"key":"brand","type":"text","properties":[{"name":"textContent","value":"DRAFTBRAND"}]}]',
        ])->save();

        $this->postJson('/api/v1/tools/testimonial-image-creator', [
            'template_uuid' => $template->uuid,
            'author' => 'John Smith',
            'testimonial' => 'Great product!',
        ])->assertStatus(200);

        $content = $template->snaps()->latest()->first()->content;
        $this->assertStringContainsString('PUBLISHEDBRAND', $content);
        $this->assertStringNotContainsString('DRAFTBRAND', $content);
    }

    public function test_twitter_graph_image_creator_generates_an_image_snap()
    {
        Storage::fake('public');
        $this->fakeBrowsershot();

        $template = $this->createTemplate();

        $response = $this->postJson('/api/v1/tools/twitter-graph-image-creator', [
            'template_uuid' => $template->uuid,
            'title' => 'My Tweet',
            'website' => 'example.com',
            'image' => UploadedFile::fake()->image('graph.png'),
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['image_path']);
    }

    public function test_twitter_graph_image_creator_stores_the_image_under_a_random_name()
    {
        Storage::fake('public');
        $this->fakeBrowsershot();

        $template = $this->createTemplate();

        $this->postJson('/api/v1/tools/twitter-graph-image-creator', [
            'template_uuid' => $template->uuid,
            'title' => 'My Tweet',
            'image' => UploadedFile::fake()->image('graph.png'),
        ])->assertStatus(200);

        $media = Media::query()->where('collection_name', 'imagesMedia')->sole();
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.png$/', $media->file_name);
    }

    public function test_twitter_graph_image_creator_requires_an_image()
    {
        $template = $this->createTemplate();

        $this->postJson('/api/v1/tools/twitter-graph-image-creator', [
            'template_uuid' => $template->uuid,
            'title' => 'My Tweet',
        ])->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_twitter_graph_image_creator_rejects_non_image_upload()
    {
        $template = $this->createTemplate();

        $response = $this->postJson('/api/v1/tools/twitter-graph-image-creator', [
            'template_uuid' => $template->uuid,
            'title' => 'My Tweet',
            'image' => UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf'),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('image');
    }

    public function test_screenshot_creator_stores_the_screenshot_for_the_free_tools_owner()
    {
        Storage::fake('public');
        $this->fakeBrowsershot();

        $owner = User::factory()->create();
        config(['services.free_tools.user_id' => $owner->id]);

        $response = $this->postJson('/api/v1/tools/screenshot-creator', [
            'url' => 'https://example.com',
            'full_page' => false,
            'mobile' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['image_path']);
        $this->assertDatabaseHas('screenshots', [
            'url' => 'https://example.com',
            'user_id' => $owner->id,
            'company_id' => $owner->company_id,
            'width' => 480,
            'height' => 1080,
        ]);
    }

    public function test_screenshot_creator_renders_through_browserless_when_it_is_the_render_driver()
    {
        Storage::fake('public');
        config([
            'services.render.driver' => 'browserless',
            'services.browserless.url' => 'http://browserless:3000',
            'services.browserless.token' => null,
        ]);
        Http::fake([
            'browserless:3000/screenshot*' => Http::response(base64_decode(self::FAKE_PNG), 200),
        ]);

        $owner = User::factory()->create();
        config(['services.free_tools.user_id' => $owner->id]);

        $response = $this->postJson('/api/v1/tools/screenshot-creator', [
            'url' => 'https://example.com',
            'full_page' => true,
            'mobile' => false,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['image_path']);
        Http::assertSent(fn ($request) => $request['url'] === 'https://example.com'
            && $request['viewport'] === ['width' => 1330, 'height' => 780, 'deviceScaleFactor' => 2]
            && $request['options']['fullPage'] === true);
    }

    public function test_screenshot_creator_returns_503_when_the_free_tools_owner_does_not_exist()
    {
        config(['services.free_tools.user_id' => 999999]);

        $response = $this->postJson('/api/v1/tools/screenshot-creator', [
            'url' => 'https://example.com',
            'full_page' => false,
            'mobile' => false,
        ]);

        $response->assertStatus(503);
        $this->assertDatabaseCount('screenshots', 0);
    }

    public function test_screenshot_creator_requires_url()
    {
        $response = $this->postJson('/api/v1/tools/screenshot-creator', [
            'full_page' => true,
            'mobile' => false,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
    }

    public function test_screenshot_creator_requires_full_page_and_mobile_flags()
    {
        $response = $this->postJson('/api/v1/tools/screenshot-creator', [
            'url' => 'https://example.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['full_page', 'mobile']);
    }
}
