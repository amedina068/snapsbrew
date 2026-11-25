<?php

namespace App\Services;

use App\Contracts\PageScreenshotter;
use App\Contracts\SnapRenderer;
use App\Rendering\RenderDocument;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\Process\ExecutableFinder;

/**
 * SnapRenderer that drives Spatie Browsershot (Node, Puppeteer and Chrome).
 *
 * With BROWSERSHOT_INSTANCE set, each render opens a tab in a Chrome that is
 * already running, for example the Chromium service of the image on
 * `http://127.0.0.1:9222`. Without it, or when that Chrome does not answer,
 * Browsershot starts a new Chrome for each render, which is much slower.
 */
class BrowsershotRenderer implements PageScreenshotter, SnapRenderer
{
    public function screenshotBase64(string $html, int $width, int $height, string $format = 'png', bool $transparent = false): string
    {
        $browsershot = $this->configure(Browsershot::html(app(RenderDocument::class)->prepare($html)))
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
        $browsershot = $this->configure(Browsershot::html(app(RenderDocument::class)->prepare($html)))
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
        $browsershot->setNodeBinary($this->nodeBinary());

        // NODE_PATH comes from `npm root -g` unless the app knows where
        // Puppeteer is. That npm call costs up to one second per render.
        if ($modules = $this->nodeModulePath()) {
            $browsershot->setNodeModulePath($modules);
        } elseif ($npm = $this->binary('services.browsershot.npm_path', 'npm')) {
            $browsershot->setNpmBinary($npm);
        }

        if ($chrome = config('services.browsershot.chrome_path')) {
            $browsershot->setChromePath($chrome);
        }

        if ($this->noSandbox()) {
            $browsershot->noSandbox();
        }

        if ($remoteInstance = $this->remoteInstanceUrl()) {
            $browsershot->setOption('remoteInstanceUrl', $remoteInstance);
        }

        return $browsershot;
    }

    private function nodeBinary(): string
    {
        return $this->binary('services.browsershot.node_path', 'node') ?? 'node';
    }

    /** The configured binary, else the first one on PATH or in /usr/local/bin. */
    private function binary(string $key, string $name): ?string
    {
        return config($key) ?: (new ExecutableFinder)->find($name, null, ['/usr/local/bin', '/usr/bin']);
    }

    /** The node_modules folder that holds Puppeteer, or null when it is not known. */
    private function nodeModulePath(): ?string
    {
        $path = rtrim((string) config('services.browsershot.node_modules_path'), '/');

        return $path !== '' && is_dir("{$path}/puppeteer") ? $path : null;
    }

    /**
     * Chrome cannot start its sandbox in a container or as root. The sandbox
     * is on by default everywhere else. BROWSERSHOT_NO_SANDBOX decides when set.
     */
    private function noSandbox(): bool
    {
        $configured = config('services.browsershot.no_sandbox');

        if ($configured !== null && $configured !== '') {
            return filter_var($configured, FILTER_VALIDATE_BOOL);
        }

        return file_exists('/.dockerenv')
            || file_exists('/run/.containerenv')
            || (function_exists('posix_geteuid') && posix_geteuid() === 0);
    }

    /** Puppeteer needs a URL. Accept `host:port` too. */
    private function remoteInstanceUrl(): ?string
    {
        $instance = trim((string) config('services.browsershot.instance'));

        if ($instance === '') {
            return null;
        }

        return preg_match('~^https?://~i', $instance) ? $instance : "http://{$instance}";
    }
}
