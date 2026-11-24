<?php

namespace Tests\Feature\Api;

use App\Contracts\SnapRenderer;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

/**
 * A shape layer carries inline SVG that the renderer puts in an HTML page. An
 * HTML-only element inside it closes the SVG, and Chrome loads its URL.
 */
class ShapeLayerSvgTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_shape_with_an_html_element_never_reaches_the_render()
    {
        Storage::fake('public');
        $renderer = new FakeRenderer;
        $this->app->instance(SnapRenderer::class, $renderer);

        $user = User::factory()->create(['company_id' => Company::factory()->create()->id]);
        $token = $user->createToken('t', ['api_requests'])->plainTextToken;

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><embed src="http://10.0.0.5:8080/admin" width="900" height="600"/></svg>';

        $this->withToken($token)->postJson('/api/v1/templates', [
            'title' => 'Shape', 'width' => 1000, 'height' => 700,
            'layers' => [['key' => 's', 'type' => 'shape', 'properties' => [['name' => 'inlineSvg', 'value' => $svg]]]],
        ]);

        $this->assertNotEmpty($renderer->renderedDocuments);

        foreach ($renderer->renderedDocuments as $document) {
            $this->assertStringNotContainsString('<embed', $document);
            $this->assertStringNotContainsString('10.0.0.5', $document);
        }
    }
}
