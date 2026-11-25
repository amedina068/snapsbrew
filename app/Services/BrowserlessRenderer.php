<?php

namespace App\Services;

use App\Contracts\PageScreenshotter;
use App\Contracts\SnapRenderer;
use App\Rendering\RenderDocument;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * SnapRenderer backed by a warm browserless pool (ghcr.io/browserless/chromium).
 *
 * Instead of cold-launching node + Chrome per render (BrowsershotRenderer), this
 * POSTs the document HTML to a long-lived browserless service that holds a warm
 * browser and manages its own concurrency/timeout/health. browserless returns
 * the raw image/PDF bytes, which we base64-encode to satisfy the seam contract.
 *
 * Asset reachability: RenderDocument embeds the Google fonts before the post.
 * The other assets (custom @font-face media URLs, image layers) must be
 * fetchable from the browserless container's network. S3 media works. For the
 * public disk, set RENDER_ASSET_URL to an app address that browserless can
 * reach, for example `http://web:8080`.
 */
class BrowserlessRenderer implements PageScreenshotter, SnapRenderer
{
    public const MAX_ATTEMPTS = 3;

    public const RETRY_BACKOFF_MICROSECONDS = 200_000;

    public function screenshotBase64(string $html, int $width, int $height, string $format = 'png', bool $transparent = false): string
    {
        $type = in_array($format, ['jpeg', 'webp'], true) ? $format : 'png';

        $payload = [
            'html' => app(RenderDocument::class)->prepare($html),
            'viewport' => ['width' => $width, 'height' => $height],
            'options' => [
                'type' => $type,
                'omitBackground' => $transparent && $type !== 'jpeg',
            ],
            'gotoOptions' => ['waitUntil' => 'networkidle0'],
        ];

        return base64_encode($this->post('screenshot', $payload));
    }

    public function screenshotUrlBase64(string $url, int $width, int $height, bool $fullPage): string
    {
        $payload = [
            'url' => $url,
            'viewport' => ['width' => $width, 'height' => $height, 'deviceScaleFactor' => 2],
            'options' => [
                'type' => 'png',
                'fullPage' => $fullPage,
            ],
            'gotoOptions' => ['waitUntil' => 'networkidle0'],
        ];

        return base64_encode($this->post('screenshot', $payload));
    }

    public function pdfBase64(string $html, int $width, int $height, $pages = null): string
    {
        $options = [
            'width' => $width.'px',
            'height' => $height.'px',
            'printBackground' => true,
            'margin' => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0],
        ];

        if ($pages !== null) {
            $options['pageRanges'] = (string) $pages;
        }

        $payload = [
            'html' => app(RenderDocument::class)->prepare($html),
            'gotoOptions' => ['waitUntil' => 'networkidle0'],
            'options' => $options,
        ];

        return base64_encode($this->post('pdf', $payload));
    }

    /**
     * POST to a browserless endpoint with a retry-and-give-up loop. Returns the
     * raw response body (image/PDF bytes). Throws after the final attempt so the
     * caller marks the snap FAILED rather than persisting empty media.
     */
    private function post(string $endpoint, array $payload): string
    {
        $url = $this->endpoint($endpoint);
        $timeout = (int) config('services.browserless.timeout', 15);

        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return Http::timeout($timeout)
                    ->asJson()
                    ->post($url, $payload)
                    ->throw()
                    ->body();
            } catch (Throwable $exception) {
                $lastException = $exception;

                if ($attempt < self::MAX_ATTEMPTS && ! app()->runningUnitTests()) {
                    usleep(self::RETRY_BACKOFF_MICROSECONDS * $attempt);
                }
            }
        }

        $message = $this->redact((string) optional($lastException)->getMessage());

        Log::warning('Failed to render snap via browserless.', [
            'endpoint' => $endpoint,
            'attempts' => self::MAX_ATTEMPTS,
            'message' => $message,
        ]);

        // The last exception is not chained. Its message names the request
        // URL with the token, and the queue worker reports the whole chain.
        throw new RuntimeException(
            "browserless {$endpoint} render failed after ".self::MAX_ATTEMPTS.' attempts: '
                .class_basename($lastException).': '.$message
        );
    }

    /** Remove the token from a message, in its plain and its URL-encoded form. */
    private function redact(string $message): string
    {
        $token = (string) config('services.browserless.token');

        if ($token === '') {
            return $message;
        }

        return str_replace([$token, urlencode($token), rawurlencode($token)], '[redacted]', $message);
    }

    /** Build the endpoint URL, appending the auth token when configured. */
    private function endpoint(string $endpoint): string
    {
        $base = rtrim((string) config('services.browserless.url'), '/');

        if (! $base) {
            throw new RuntimeException('services.browserless.url is not configured.');
        }

        $url = $base.'/'.$endpoint;

        if ($token = config('services.browserless.token')) {
            $url .= '?token='.urlencode((string) $token);
        }

        return $url;
    }
}
