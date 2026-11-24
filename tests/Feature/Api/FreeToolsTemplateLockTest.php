<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Browsershot\Browsershot;
use Tests\TestCase;

/**
 * The free tools take no token. Each tool renders only the template that the
 * operator names in the configuration, with a fixed list of layer keys.
 */
class FreeToolsTemplateLockTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_PNG = 'iVBORw0KGgoAAAANSUhEUgAAABQAAAAUCAIAAAAC64paAAAACXBIWXMAAA7EAAAOxAGV'
        .'Kw4bAAAAG0lEQVQ4jWP8z0A+YKJA76jmUc2jmkc1U0EzACKcASfaaIZkAAAAAElFTkSuQmCC';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.free_tools.enabled' => true]);
    }

    private function fakeBrowsershot(): void
    {
        $browsershot = Mockery::mock('overload:'.Browsershot::class);
        $fluent = [
            'html', 'url', 'setRemoteInstance', 'setOption', 'setNodeBinary', 'setNpmBinary',
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

    private function templateWithLayers(string $layers = '[]'): Template
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        return Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'layers' => $layers,
        ]);
    }

    public function test_a_tool_renders_the_configured_template_and_ignores_the_template_uuid_of_the_request()
    {
        Storage::fake('public');
        $this->fakeBrowsershot();

        $toolTemplate = $this->templateWithLayers();
        $otherCompanyTemplate = $this->templateWithLayers();
        config(['services.free_tools.templates.testimonial' => $toolTemplate->uuid]);

        $this->postJson('/api/v1/tools/testimonial-image-creator', [
            'template_uuid' => $otherCompanyTemplate->uuid,
            'author' => 'John Smith',
            'testimonial' => 'Great product!',
        ])->assertOk();

        $this->assertSame(1, $toolTemplate->snaps()->count());
        $this->assertSame(0, $otherCompanyTemplate->snaps()->count());
    }

    public static function templateTools(): array
    {
        return [
            'certificate' => ['/api/v1/tools/certificate-creator', 'certificate'],
            'testimonial' => ['/api/v1/tools/testimonial-image-creator', 'testimonial'],
            'twitter graph' => ['/api/v1/tools/twitter-graph-image-creator', 'twitter_graph'],
        ];
    }

    #[DataProvider('templateTools')]
    public function test_a_tool_without_a_configured_template_returns_503_and_renders_nothing(string $uri, string $tool)
    {
        $template = $this->templateWithLayers();
        config(["services.free_tools.templates.$tool" => null]);

        $this->postJson($uri, [
            'template_uuid' => $template->uuid,
            'image' => UploadedFile::fake()->image('graph.png'),
        ])->assertStatus(503);

        $this->assertSame(0, $template->snaps()->count());
    }

    public function test_the_twitter_graph_tool_changes_only_its_fixed_layer_keys()
    {
        Storage::fake('public');
        $this->fakeBrowsershot();
        User::factory()->create();

        $template = $this->templateWithLayers(
            '[{"key":"brand","type":"text","properties":[{"name":"textContent","value":"ORIGINALBRAND"}]},'
            .'{"key":"title","type":"text","properties":[{"name":"textContent","value":"TITLE"}]}]'
        );
        config(['services.free_tools.templates.twitter_graph' => $template->uuid]);

        $this->postJson('/api/v1/tools/twitter-graph-image-creator', [
            'title' => 'My Tweet',
            'brand' => 'INJECTEDBRAND',
            'image' => UploadedFile::fake()->image('graph.png'),
        ])->assertOk();

        $content = $template->snaps()->sole()->content;
        $this->assertStringContainsString('My Tweet', $content);
        $this->assertStringContainsString('ORIGINALBRAND', $content);
        $this->assertStringNotContainsString('INJECTEDBRAND', $content);
    }

    public function test_the_twitter_graph_tool_refuses_an_image_over_the_free_tool_cap()
    {
        $template = $this->templateWithLayers();
        config(['services.free_tools.templates.twitter_graph' => $template->uuid]);

        $response = $this->postJson('/api/v1/tools/twitter-graph-image-creator', [
            'title' => 'My Tweet',
            'image' => UploadedFile::fake()->image('graph.png')->size(3000),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('image');
        $this->assertStringContainsString('2048', $response->json('errors.image.0'));
    }

    public function test_the_tools_have_a_strict_limit_for_each_ip()
    {
        $limit = config('services.free_tools.requests_per_minute_per_ip');
        $this->assertIsInt($limit);
        $this->assertLessThanOrEqual(10, $limit);

        for ($i = 0; $i < $limit; $i++) {
            $this->postJson('/api/v1/tools/screenshot-creator', [])->assertUnprocessable();
        }

        $this->postJson('/api/v1/tools/screenshot-creator', [])->assertStatus(429);
        $this->postJson('/api/v1/tools/certificate-creator', [])->assertStatus(429);
    }
}
