<?php

namespace App\Contracts;

/**
 * Renders a snap's HTML document into raw image/PDF bytes.
 *
 * Extracted from Snap so the headless-Chrome (Browsershot) call sits behind a
 * bindable seam — tests bind a fake and never launch Chrome.
 *
 * The first argument is the full document HTML (built by Snap::renderDocument),
 * not a URL. The renderer needs no HTTP route of the app, and a remote render
 * service can produce the bytes.
 */
interface SnapRenderer
{
    public function screenshotBase64(string $html, int $width, int $height, string $format = 'png', bool $transparent = false): string;

    public function pdfBase64(string $html, int $width, int $height, $pages = null): string;
}
