<?php

namespace Tests\Unit\Services;

use App\Rendering\RenderDocument;
use App\Services\BrowsershotRenderer;
use Illuminate\Support\Facades\File;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;
use Throwable;

/**
 * A fake `node` binary records each call and the NODE_PATH it gets, so the
 * test reads the command that Browsershot really runs. No Chrome starts.
 *
 * The test loads the real Browsershot class. Other tests replace that class
 * with an `overload:` mock, which fails when the class is already loaded. So
 * each test runs in its own process.
 */
class BrowsershotRendererTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/browsershot-renderer-test-'.uniqid();
        File::ensureDirectoryExists($this->directory);
        File::put("{$this->directory}/node", "#!/bin/sh\necho \"NODE_PATH=\$NODE_PATH \$@\" >> \"{$this->directory}/calls.log\"\n");
        chmod("{$this->directory}/node", 0755);

        config()->set('services.browsershot.node_path', "{$this->directory}/node");
        config()->set('services.browsershot.npm_path', "{$this->directory}/npm");
        config()->set('services.browsershot.node_modules_path', "{$this->directory}/no-modules-here");
        config()->set('services.browsershot.instance', 'http://chrome:9222');
        config()->set('services.browsershot.no_sandbox', false);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_pdf_render_uses_the_configured_node_npm_and_remote_instance()
    {
        $calls = $this->renderPdf();

        $this->assertStringContainsString("{$this->directory}/npm root -g", $calls);
        $this->assertStringContainsString('remoteInstanceUrl', $calls);
        $this->assertStringContainsString('chrome:9222', $calls);
        $this->assertStringNotContainsString('--no-sandbox', $calls);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_known_node_modules_path_skips_npm_root()
    {
        File::ensureDirectoryExists("{$this->directory}/node_modules/puppeteer");
        config()->set('services.browsershot.node_modules_path', "{$this->directory}/node_modules");

        $calls = $this->renderPdf();

        $this->assertStringNotContainsString('root -g', $calls);
        $this->assertStringContainsString("NODE_PATH={$this->directory}/node_modules ", $calls);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_no_sandbox_adds_the_chrome_flag()
    {
        config()->set('services.browsershot.no_sandbox', true);

        $this->assertStringContainsString('--no-sandbox', $this->renderPdf());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_an_instance_without_a_scheme_gets_http()
    {
        config()->set('services.browsershot.instance', '127.0.0.1:9222');

        $this->assertStringContainsString('"remoteInstanceUrl":"http:\/\/127.0.0.1:9222"', $this->renderPdf());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_html_is_prepared_before_it_goes_to_chrome()
    {
        $document = Mockery::mock(RenderDocument::class);
        $document->shouldReceive('prepare')->once()->with('<p>Hi</p>')->andReturn('<p>Prepared</p>');
        $this->app->instance(RenderDocument::class, $document);

        $this->renderPdf();
    }

    private function renderPdf(): string
    {
        try {
            (new BrowsershotRenderer)->pdfBase64('<p>Hi</p>', 100, 100);
        } catch (Throwable) {
            // The fake binary prints no PDF. Only the recorded calls matter.
        }

        return File::get("{$this->directory}/calls.log");
    }
}
