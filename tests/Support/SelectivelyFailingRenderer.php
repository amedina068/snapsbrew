<?php

namespace Tests\Support;

use App\Contracts\SnapRenderer;
use RuntimeException;

/**
 * Renders like FakeRenderer, except for documents that hold a marker string.
 *
 * A batch test needs one item to fail while its siblings succeed. The variant
 * text reaches the document markup, so the marker in a spreadsheet cell decides
 * which item throws.
 */
class SelectivelyFailingRenderer implements SnapRenderer
{
    public const MARKER = 'RENDER_MUST_FAIL';

    public function screenshotBase64(string $html, int $width, int $height, string $format = 'png', bool $transparent = false): string
    {
        $this->failOnMarker($html);

        return FakeRenderer::FAKE_PNG;
    }

    public function pdfBase64(string $html, int $width, int $height, $pages = null): string
    {
        $this->failOnMarker($html);

        return base64_encode('%PDF-1.4 fake');
    }

    private function failOnMarker(string $html): void
    {
        if (str_contains($html, self::MARKER)) {
            throw new RuntimeException('render exploded on purpose');
        }
    }
}
