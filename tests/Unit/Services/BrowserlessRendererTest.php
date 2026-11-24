<?php

namespace Tests\Unit\Services;

use App\Contracts\PageScreenshotter;
use App\Contracts\SnapRenderer;
use App\Services\BrowserlessRenderer;
use App\Services\BrowsershotRenderer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class BrowserlessRendererTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.browserless.url', 'http://browserless:3000');
        config()->set('services.browserless.token', 'secret-token');
    }

    public function test_render_driver_config_selects_the_bound_renderer()
    {
        config()->set('services.render.driver', 'browserless');
        $this->assertInstanceOf(BrowserlessRenderer::class, app(SnapRenderer::class));

        config()->set('services.render.driver', 'browsershot');
        $this->assertInstanceOf(BrowsershotRenderer::class, app(SnapRenderer::class));
    }

    public function test_render_driver_config_selects_the_bound_page_screenshotter()
    {
        config()->set('services.render.driver', 'browserless');
        $this->assertInstanceOf(BrowserlessRenderer::class, app(PageScreenshotter::class));

        config()->set('services.render.driver', 'browsershot');
        $this->assertInstanceOf(BrowsershotRenderer::class, app(PageScreenshotter::class));
    }

    public function test_url_screenshot_posts_the_url_and_returns_base64_of_response_bytes()
    {
        Http::fake([
            'browserless:3000/screenshot*' => Http::response('PNGBYTES', 200),
        ]);

        $base64 = (new BrowserlessRenderer)
            ->screenshotUrlBase64('https://example.com', 1330, 780, true);

        $this->assertSame(base64_encode('PNGBYTES'), $base64);

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'http://browserless:3000/screenshot')
                && str_contains($request->url(), 'token=secret-token')
                && $request['url'] === 'https://example.com'
                && ! isset($request['html'])
                && $request['viewport'] === ['width' => 1330, 'height' => 780, 'deviceScaleFactor' => 2]
                && $request['options']['type'] === 'png'
                && $request['options']['fullPage'] === true
                && $request['gotoOptions']['waitUntil'] === 'networkidle0';
        });
    }

    public function test_screenshot_posts_html_and_returns_base64_of_response_bytes()
    {
        Http::fake([
            'browserless:3000/screenshot*' => Http::response('PNGBYTES', 200),
        ]);

        $base64 = (new BrowserlessRenderer)
            ->screenshotBase64('<html>doc</html>', 1200, 630, 'png', false);

        $this->assertSame(base64_encode('PNGBYTES'), $base64);

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'http://browserless:3000/screenshot')
                && str_contains($request->url(), 'token=secret-token')
                && $request['html'] === '<html>doc</html>'
                && $request['viewport'] === ['width' => 1200, 'height' => 630]
                && $request['options']['type'] === 'png'
                && $request['options']['omitBackground'] === false
                && $request['gotoOptions']['waitUntil'] === 'networkidle0';
        });
    }

    public function test_transparent_png_sets_omit_background()
    {
        Http::fake(['browserless:3000/screenshot*' => Http::response('X', 200)]);

        (new BrowserlessRenderer)->screenshotBase64('<html></html>', 100, 100, 'png', true);

        Http::assertSent(fn ($request) => $request['options']['omitBackground'] === true);
    }

    public function test_jpeg_never_omits_background_even_when_transparent_requested()
    {
        Http::fake(['browserless:3000/screenshot*' => Http::response('X', 200)]);

        (new BrowserlessRenderer)->screenshotBase64('<html></html>', 100, 100, 'jpeg', true);

        Http::assertSent(function ($request) {
            return $request['options']['type'] === 'jpeg'
                && $request['options']['omitBackground'] === false;
        });
    }

    public function test_pdf_posts_dimensions_and_page_ranges()
    {
        Http::fake(['browserless:3000/pdf*' => Http::response('%PDF-1.4', 200)]);

        $base64 = (new BrowserlessRenderer)->pdfBase64('<html>cert</html>', 800, 1100, 1);

        $this->assertSame(base64_encode('%PDF-1.4'), $base64);

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'http://browserless:3000/pdf')
                && $request['options']['width'] === '800px'
                && $request['options']['height'] === '1100px'
                && $request['options']['printBackground'] === true
                && $request['options']['pageRanges'] === '1';
        });
    }

    public function test_retries_then_throws_after_max_attempts()
    {
        Http::fake(['browserless:3000/screenshot*' => Http::response('boom', 500)]);

        $this->expectException(RuntimeException::class);

        try {
            (new BrowserlessRenderer)->screenshotBase64('<html></html>', 100, 100);
        } finally {
            Http::assertSentCount(BrowserlessRenderer::MAX_ATTEMPTS);
        }
    }

    /**
     * The token travels in the query string. A connection error names the
     * full request URL, and the failed job reports the exception chain to
     * the log. Neither the log line nor the exception may hold the token.
     */
    public function test_a_connection_error_does_not_leak_the_token()
    {
        Log::spy();
        Http::fake(function () {
            throw new ConnectionException('cURL error 7: Failed to connect (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for http://browserless:3000/screenshot?token=secret-token');
        });

        try {
            (new BrowserlessRenderer)->screenshotBase64('<html></html>', 100, 100);
            $this->fail('The renderer did not throw.');
        } catch (RuntimeException $exception) {
            for ($link = $exception; $link !== null; $link = $link->getPrevious()) {
                $this->assertStringNotContainsString('secret-token', $link->getMessage());
            }
            $this->assertStringContainsString('cURL error 7', $exception->getMessage());
        }

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
            return ! str_contains(json_encode($context), 'secret-token')
                && str_contains($context['message'], 'cURL error 7');
        })->once();
    }

    public function test_missing_url_config_throws()
    {
        config()->set('services.browserless.url', null);

        $this->expectException(RuntimeException::class);

        (new BrowserlessRenderer)->screenshotBase64('<html></html>', 100, 100);
    }

    /**
     * Horizon kills a job after its timeout. With the old default of 60
     * seconds for each attempt, attempts 2 and 3 never ran.
     */
    public function test_three_attempts_at_the_default_timeout_fit_in_the_job_timeout()
    {
        $default = $this->defaultConfig('services.php', 'BROWSERLESS_TIMEOUT')['browserless']['timeout'];

        $backoffSeconds = array_sum(array_map(
            fn ($attempt) => BrowserlessRenderer::RETRY_BACKOFF_MICROSECONDS * $attempt / 1_000_000,
            range(1, BrowserlessRenderer::MAX_ATTEMPTS - 1),
        ));

        $this->assertSame(15, $default);
        $this->assertLessThan(
            config('horizon.defaults.supervisor-1.timeout'),
            BrowserlessRenderer::MAX_ATTEMPTS * $default + $backoffSeconds,
        );
    }

    /**
     * Read a config file with one environment variable unset, so the test
     * sees the default of the file and not the value of the local .env.
     */
    private function defaultConfig(string $file, string $variable): array
    {
        $saved = [getenv($variable), $_ENV[$variable] ?? null, $_SERVER[$variable] ?? null];
        putenv($variable);
        unset($_ENV[$variable], $_SERVER[$variable]);

        try {
            return require config_path($file);
        } finally {
            if ($saved[0] !== false) {
                putenv("{$variable}={$saved[0]}");
            }
            if ($saved[1] !== null) {
                $_ENV[$variable] = $saved[1];
            }
            if ($saved[2] !== null) {
                $_SERVER[$variable] = $saved[2];
            }
        }
    }
}
