<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The public POST /api/v1/tools/screenshot-creator forwarded its `url` field
 * straight into Browsershot with only a `string` validation rule. The SafeUrl
 * guard rejects dangerous targets (file://, RFC1918, loopback, link-local /
 * cloud metadata, non-http schemes) with 422 before anything is persisted or
 * rendered. Validation runs before the insert, so these assertions hold
 * regardless of the controller's downstream behavior.
 */
class ScreenshotCreatorSsrfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.free_tools.enabled' => true]);
    }

    private function postScreenshot(string $url)
    {
        return $this->postJson('/api/v1/tools/screenshot-creator', [
            'url' => $url,
            'full_page' => false,
            'mobile' => false,
        ]);
    }

    #[DataProvider('dangerousUrls')]
    public function test_ssrf_targets_are_rejected(string $url)
    {
        $response = $this->postScreenshot($url);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
        $this->assertDatabaseCount('screenshots', 0);
    }

    public static function dangerousUrls(): array
    {
        return [
            'local file' => ['file:///etc/passwd'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'loopback' => ['http://127.0.0.1/'],
            'private 10/8' => ['http://10.0.0.5/'],
            'private 192.168' => ['http://192.168.1.1/'],
            'non-http scheme' => ['gopher://example.com/'],
            'not a url' => ['definitely-not-a-url'],
        ];
    }
}
