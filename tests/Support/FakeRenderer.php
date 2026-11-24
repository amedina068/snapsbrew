<?php

namespace Tests\Support;

use App\Contracts\SnapRenderer;

/**
 * Hermetic SnapRenderer for tests — never launches Chrome.
 *
 * Returns a valid 20x20 base64 PNG (decodable by GD for media conversions),
 * matching the FAKE_PNG used in ToolsApiTest. Records the document HTML it was
 * asked to render so tests can assert on the markup (e.g. watermark presence is
 * checked on the stored snap content, not here).
 */
class FakeRenderer implements SnapRenderer
{
    public const FAKE_PNG = 'iVBORw0KGgoAAAANSUhEUgAAABQAAAAUCAIAAAAC64paAAAACXBIWXMAAA7EAAAOxAGV'
        .'Kw4bAAAAG0lEQVQ4jWP8z0A+YKJA76jmUc2jmkc1U0EzACKcASfaaIZkAAAAAElFTkSuQmCC';

    /** @var array<int, string> */
    public array $renderedDocuments = [];

    /** @var array<int, array{format: string, transparent: bool, width: int, height: int}> */
    public array $screenshotCalls = [];

    public function screenshotBase64(string $html, int $width, int $height, string $format = 'png', bool $transparent = false): string
    {
        $this->renderedDocuments[] = $html;
        $this->screenshotCalls[] = [
            'format' => $format,
            'transparent' => $transparent,
            'width' => $width,
            'height' => $height,
        ];

        return self::FAKE_PNG;
    }

    public function pdfBase64(string $html, int $width, int $height, $pages = null): string
    {
        $this->renderedDocuments[] = $html;

        return base64_encode('%PDF-1.4 fake');
    }
}
