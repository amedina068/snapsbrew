<?php

namespace Tests\Support;

use App\Contracts\SnapRenderer;
use RuntimeException;

/**
 * SnapRenderer test double that always fails — used to prove the render paths
 * clean up transient snaps/media when Chrome throws.
 */
class ThrowingRenderer implements SnapRenderer
{
    public function screenshotBase64(string $html, int $width, int $height, string $format = 'png', bool $transparent = false): string
    {
        throw new RuntimeException('render failed');
    }

    public function pdfBase64(string $html, int $width, int $height, $pages = null): string
    {
        throw new RuntimeException('render failed');
    }
}
