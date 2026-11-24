<?php

namespace Tests\Unit\Services;

use App\Services\BrowsershotRenderer;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;
use Throwable;

/**
 * A fake `node` binary records each call, so the test reads the command that
 * Browsershot really runs. No Chrome starts.
 *
 * The test loads the real Browsershot class. Other tests replace that class
 * with an `overload:` mock, which fails when the class is already loaded. So
 * this test runs in its own process.
 */
class BrowsershotRendererTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/browsershot-renderer-test-'.uniqid();
        File::ensureDirectoryExists($this->directory);
        File::put("{$this->directory}/node", "#!/bin/sh\necho \"\$@\" >> \"{$this->directory}/calls.log\"\n");
        chmod("{$this->directory}/node", 0755);

        config()->set('services.browsershot.node_path', "{$this->directory}/node");
        config()->set('services.browsershot.npm_path', "{$this->directory}/npm");
        config()->set('services.browsershot.instance', 'http://chrome:9222');
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
        try {
            (new BrowsershotRenderer)->pdfBase64('<p>Hi</p>', 100, 100);
        } catch (Throwable) {
            // The fake binary prints no PDF. Only the recorded calls matter.
        }

        $calls = File::get("{$this->directory}/calls.log");

        $this->assertStringContainsString("{$this->directory}/npm root -g", $calls);
        $this->assertStringContainsString('remoteInstanceUrl', $calls);
        $this->assertStringContainsString('chrome:9222', $calls);
    }
}
