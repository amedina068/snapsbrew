<?php

namespace App\Services;

use App\Contracts\PageScreenshotter;
use App\Contracts\SnapRenderer;
use Spatie\Browsershot\Browsershot;

/**
 * Production SnapRenderer: drives Spatie Browsershot (headless Chrome).
 *
 * Holds the exact options the Snap model used before the seam was extracted —
 * node/npm binaries and the optional remote Browsershot instance URL.
 */
class BrowsershotRenderer implements PageScreenshotter, SnapRenderer
{
    public function screenshotBase64(string $html, int $width, int $height, string $format = 'png', bool $transparent = false): string
    {
        $browsershot = $this->configure(Browsershot::html($html))
            ->preventUnsuccessfulResponse()
            ->windowSize($width, $height)
            ->setScreenshotType($format === 'jpeg' ? 'jpeg' : ($format === 'webp' ? 'webp' : 'png'))
            ->waitUntilNetworkIdle();

        // png/webp can carry an alpha channel; omitting the default white page
        // background lets the layers' own transparency show through.
        if ($transparent && $format !== 'jpeg') {
            $browsershot->transparentBackground();
        }

        return $browsershot->base64Screenshot();
    }

    public function screenshotUrlBase64(string $url, int $width, int $height, bool $fullPage): string
    {
        $browsershot = $this->configure(Browsershot::url($url))
            ->windowSize($width, $height)
            ->waitUntilNetworkIdle()
            ->deviceScaleFactor(2);

        if ($fullPage) {
            $browsershot->fullPage();
        }

        return $browsershot->base64Screenshot();
    }

    public function pdfBase64(string $html, int $width, int $height, $pages = null): string
    {
        $browsershot = $this->configure(Browsershot::html($html))
            ->showBackground()
            ->margins(0, 0, 0, 0)
            ->paperSize($width, $height, 'px');

        if ($pages !== null) {
            $browsershot->pages($pages);
        }

        return $browsershot->base64Pdf();
    }

    /**
     * Every render reads the same binaries and the same remote Chrome. A host
     * with Node outside the default path renders nothing without them.
     */
    private function configure(Browsershot $browsershot): Browsershot
    {
        $browsershot
            ->setNodeBinary(config('services.browsershot.node_path'))
            ->setNpmBinary(config('services.browsershot.npm_path'));

        if ($remoteInstance = config('services.browsershot.instance')) {
            $browsershot->setOption('remoteInstanceUrl', $remoteInstance);
        }

        return $browsershot;
    }
}
