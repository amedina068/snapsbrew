<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FreeToolsSwitchTest extends TestCase
{
    use RefreshDatabase;

    public static function toolRoutes(): array
    {
        return [
            'certificate' => ['/api/v1/tools/certificate-creator'],
            'twitter graph' => ['/api/v1/tools/twitter-graph-image-creator'],
            'testimonial' => ['/api/v1/tools/testimonial-image-creator'],
            'screenshot' => ['/api/v1/tools/screenshot-creator'],
        ];
    }

    #[DataProvider('toolRoutes')]
    public function test_the_tools_are_not_found_when_disabled(string $uri)
    {
        config(['services.free_tools.enabled' => false]);

        $this->postJson($uri, [])->assertNotFound();
    }

    // ToolsApiTest covers the other three tools with the switch on. They need
    // their templates in the database, so an empty request returns 404 there.
    public function test_the_screenshot_tool_validates_when_enabled()
    {
        config(['services.free_tools.enabled' => true]);

        $this->postJson('/api/v1/tools/screenshot-creator', [])->assertUnprocessable();
    }

    public function test_the_tools_are_disabled_by_default()
    {
        $this->assertFalse(config('services.free_tools.enabled'));
    }
}
