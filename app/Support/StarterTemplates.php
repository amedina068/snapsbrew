<?php

namespace App\Support;

/**
 * The starter templates that a new account can add with one command.
 *
 * Each definition is plain data: a stable key, a title, a size preset from
 * TemplateSizes and the layers in the editor shape. StarterTemplateInstaller
 * writes them through the same services as the editor and the MCP tools.
 *
 * The designs obey the house rules of DesignGuide: content inside the safe
 * margin, one alignment per design, at most two font families, at most four
 * colors and one accent. The fonts are Google Fonts under the SIL Open Font
 * License, from the catalog in public/json/google-fonts.json. The renderer
 * loads only the regular weight of a family, so the headlines use families
 * whose regular weight is a display weight. No design uses an image.
 */
final class StarterTemplates
{
    public const TITLE_PREFIX = 'Starter: ';

    /**
     * @return list<array{key: string, title: string, size: string, layers: list<array<string, mixed>>}>
     */
    public static function all(): array
    {
        return [
            self::linkPreview(),
            self::quoteCard(),
            self::certificate(),
            self::productLaunch(),
            self::blogHeader(),
            self::youtubeThumbnail(),
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    /** An Open Graph link preview: dark, editorial, left-aligned. */
    private static function linkPreview(): array
    {
        $p = 'link-preview';
        $ink = '#11131a';
        $paper = '#f6f3ee';
        $coral = '#ff6b4a';

        return self::definition($p, 'Link preview', 'open_graph', [
            self::rect($p, 'background', 'Background', [0, 0, 1200, 630, 0], ['backgroundColor' => $ink]),
            self::pattern($p, 'texture', 'Texture', [0, 0, 1200, 630, 1], 'topography', $paper, 0.05),
            self::rect($p, 'accent_bar', 'Accent bar', [80, 96, 64, 8, 4], ['backgroundColor' => $coral]),
            self::text($p, 'eyebrow', 'Eyebrow', [80, 128, 900, 32, 4], 'ENGINEERING BLOG', 'Inter', 22, 32, $coral, ['letterSpacing' => 4]),
            self::text($p, 'headline', 'Headline', [80, 186, 1000, 280, 4], 'How we cut the render time of our images in half', 'DM Serif Display', 80, 92, $paper),
            self::rect($p, 'divider', 'Divider', [80, 502, 1040, 2, 4], ['backgroundColor' => $paper, 'opacity' => 0.15]),
            self::text($p, 'site', 'Site', [80, 530, 600, 40, 5], 'yourdomain.com', 'Inter', 26, 40, $paper, ['opacity' => 0.75]),
            self::text($p, 'read_time', 'Read time', [720, 530, 400, 40, 5], '6 min read', 'Inter', 26, 40, $paper, ['opacity' => 0.75, 'textAlignment' => 'right']),
        ]);
    }

    /** A square quote card: warm gradient, centered. */
    private static function quoteCard(): array
    {
        $p = 'quote-card';
        $brown = '#2b1d14';
        $accent = '#e4572e';

        return self::definition($p, 'Quote card', 'instagram_square_post', [
            self::rect($p, 'background', 'Background', [0, 0, 1080, 1080, 0], [
                'backgroundType' => 'linear',
                'backgroundGradientColor' => ['degrees' => 160, 'steps' => [
                    ['color' => '#fbf4e6', 'percentage' => 0],
                    ['color' => '#f3d9bd', 'percentage' => 100],
                ]],
            ]),
            self::text($p, 'quote_mark', 'Quote mark', [120, 120, 840, 200, 4], "\u{201C}", 'DM Serif Display', 280, 300, $accent, ['textAlignment' => 'center']),
            self::text($p, 'quote', 'Quote', [120, 340, 840, 360, 4], 'Great design is invisible until the day that it is missing.', 'DM Serif Display', 68, 84, $brown, ['textAlignment' => 'center']),
            self::rect($p, 'divider', 'Divider', [508, 748, 64, 4, 4], ['backgroundColor' => $brown, 'opacity' => 0.35]),
            self::text($p, 'author', 'Author', [120, 788, 840, 44, 5], 'Jordan Lee', 'Inter', 34, 44, $brown, ['textAlignment' => 'center']),
            self::text($p, 'role', 'Role', [120, 836, 840, 40, 5], 'Product designer', 'Inter', 26, 40, $brown, ['textAlignment' => 'center', 'opacity' => 0.65]),
        ]);
    }

    /** A landscape A4 certificate at 300 dpi: paper, double frame, centered. */
    private static function certificate(): array
    {
        $p = 'certificate';
        $paper = '#fbf8f1';
        $navy = '#1f2a44';
        $gold = '#b08d57';

        return self::definition($p, 'Certificate', 'print_a4_landscape', [
            self::rect($p, 'background', 'Background', [0, 0, 3508, 2480, 0], ['backgroundColor' => $paper]),
            self::rect($p, 'outer_frame', 'Outer frame', [120, 120, 3268, 2240, 1], self::frame($navy, 10)),
            self::rect($p, 'inner_frame', 'Inner frame', [170, 170, 3168, 2140, 1], self::frame($gold, 4)),
            self::text($p, 'eyebrow', 'Eyebrow', [254, 420, 3000, 90, 4], 'CERTIFICATE OF COMPLETION', 'Inter', 64, 90, $gold, ['textAlignment' => 'center', 'letterSpacing' => 18]),
            self::text($p, 'intro', 'Intro', [254, 620, 3000, 120, 4], 'This certificate is presented to', 'Playfair Display', 84, 120, $navy, ['textAlignment' => 'center']),
            self::text($p, 'recipient', 'Recipient', [254, 800, 3000, 300, 4], 'Alex Morgan', 'Playfair Display', 240, 300, $navy, ['textAlignment' => 'center']),
            self::rect($p, 'name_rule', 'Name rule', [1104, 1150, 1300, 4, 4], ['backgroundColor' => $gold]),
            self::text($p, 'description', 'Description', [654, 1250, 2200, 200, 4], 'for the completion of the Advanced Product Design course, with distinction.', 'Inter', 60, 96, $navy, ['textAlignment' => 'center', 'opacity' => 0.8]),
            self::text($p, 'date', 'Date', [504, 1800, 1000, 110, 4], 'November 17, 2026', 'Playfair Display', 72, 110, $navy, ['textAlignment' => 'center']),
            self::rect($p, 'date_rule', 'Date rule', [554, 1930, 900, 3, 4], ['backgroundColor' => $navy]),
            self::text($p, 'date_label', 'Date label', [504, 1960, 1000, 60, 4], 'DATE', 'Inter', 40, 60, $navy, ['textAlignment' => 'center', 'letterSpacing' => 10, 'opacity' => 0.7]),
            self::text($p, 'signature', 'Signature', [2004, 1800, 1000, 110, 4], 'Sam Rivera', 'Playfair Display', 72, 110, $navy, ['textAlignment' => 'center']),
            self::rect($p, 'signature_rule', 'Signature rule', [2054, 1930, 900, 3, 4], ['backgroundColor' => $navy]),
            self::text($p, 'signature_label', 'Signature label', [2004, 1960, 1000, 60, 4], 'INSTRUCTOR', 'Inter', 40, 60, $navy, ['textAlignment' => 'center', 'letterSpacing' => 10, 'opacity' => 0.7]),
            self::rect($p, 'seal', 'Seal', [1574, 1700, 360, 360, 5], ['backgroundColor' => $gold] + self::radius(180)),
            self::text($p, 'seal_text', 'Seal text', [1574, 1700, 360, 360, 6], '2026', 'Playfair Display', 96, 360, $paper, ['textAlignment' => 'center']),
        ]);
    }

    /** A portrait product launch post: deep violet, left-aligned. */
    private static function productLaunch(): array
    {
        $p = 'product-launch';
        $white = '#ffffff';
        $yellow = '#ffd166';
        $night = '#1b1440';

        return self::definition($p, 'Product launch', 'instagram_post', [
            self::rect($p, 'background', 'Background', [0, 0, 1080, 1350, 0], [
                'backgroundType' => 'linear',
                'backgroundGradientColor' => ['degrees' => 155, 'steps' => [
                    ['color' => $night, 'percentage' => 0],
                    ['color' => '#3b1d78', 'percentage' => 100],
                ]],
            ]),
            self::rect($p, 'glow', 'Glow', [430, -260, 900, 900, 1], ['backgroundColor' => '#7b5cff', 'opacity' => 0.25] + self::radius(450)),
            self::pattern($p, 'texture', 'Texture', [0, 0, 1080, 1350, 1], 'polkaDots', $white, 0.06),
            self::rect($p, 'badge', 'Badge', [96, 120, 156, 56, 5], ['backgroundColor' => $yellow] + self::radius(28)),
            self::text($p, 'badge_text', 'Badge text', [96, 120, 156, 56, 6], 'NEW', 'Inter', 24, 56, $night, ['textAlignment' => 'center', 'letterSpacing' => 4]),
            self::text($p, 'headline', 'Headline', [96, 520, 888, 400, 4], 'Say hello to Version 2.0', 'DM Serif Display', 132, 140, $white),
            self::text($p, 'subhead', 'Subhead', [96, 940, 888, 120, 4], 'Faster renders, smarter templates, a new editor.', 'Inter', 38, 56, $white, ['opacity' => 0.8]),
            self::rect($p, 'cta', 'Call to action', [96, 1150, 360, 84, 5], self::frame($white, 3) + self::radius(42)),
            self::text($p, 'cta_text', 'Call to action text', [96, 1150, 360, 84, 6], 'Available today', 'Inter', 30, 84, $white, ['textAlignment' => 'center']),
            self::text($p, 'brand', 'Brand', [584, 1172, 400, 40, 5], 'yourbrand.com', 'Inter', 26, 40, $white, ['textAlignment' => 'right', 'opacity' => 0.6]),
        ]);
    }

    /** A blog post header: light, editorial, left-aligned. */
    private static function blogHeader(): array
    {
        $p = 'blog-header';
        $mist = '#eef2ee';
        $green = '#1f6f5c';
        $ink = '#14211d';

        return self::definition($p, 'Blog header', 'blog_header', [
            self::rect($p, 'background', 'Background', [0, 0, 1600, 900, 0], ['backgroundColor' => $mist]),
            self::rect($p, 'circle', 'Circle', [1040, 90, 720, 720, 2], ['backgroundColor' => $green] + self::radius(360)),
            self::pattern($p, 'circle_texture', 'Circle texture', [1040, 90, 720, 720, 3], 'diagonalStripes', $mist, 0.18, self::radius(360)),
            self::text($p, 'category', 'Category', [112, 230, 800, 36, 4], 'DESIGN SYSTEMS', 'Inter', 24, 36, $green, ['letterSpacing' => 4]),
            self::text($p, 'title', 'Title', [112, 286, 860, 340, 4], 'A practical guide to color tokens', 'DM Serif Display', 104, 112, $ink),
            self::text($p, 'byline', 'Byline', [112, 660, 860, 44, 4], 'By Riley Chen  ·  8 min read', 'Inter', 30, 44, $ink, ['opacity' => 0.7]),
        ]);
    }

    /** A YouTube thumbnail: loud yellow, huge type, readable at small size. */
    private static function youtubeThumbnail(): array
    {
        $p = 'youtube-thumbnail';
        $yellow = '#ffcf1f';
        $black = '#111111';
        $red = '#e63946';
        $white = '#ffffff';

        return self::definition($p, 'YouTube thumbnail', 'youtube_thumbnail', [
            self::rect($p, 'background', 'Background', [0, 0, 1280, 720, 0], ['backgroundColor' => $yellow]),
            self::rect($p, 'badge', 'Badge', [64, 64, 250, 60, 5], ['backgroundColor' => $red] + self::radius(8)),
            self::text($p, 'badge_text', 'Badge text', [64, 64, 250, 60, 6], 'EPISODE 12', 'Inter', 26, 60, $white, ['textAlignment' => 'center', 'letterSpacing' => 2]),
            self::text($p, 'headline', 'Headline', [64, 150, 560, 516, 4], 'STOP DOING THIS', 'Anton', 190, 172, $black),
            self::rect($p, 'circle', 'Circle', [700, 140, 480, 480, 2], ['backgroundColor' => $black] + self::radius(240)),
            self::text($p, 'circle_text', 'Circle text', [700, 140, 480, 480, 4], '10X', 'Anton', 210, 480, $yellow, ['textAlignment' => 'center']),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $layers
     * @return array{key: string, title: string, size: string, layers: list<array<string, mixed>>}
     */
    private static function definition(string $key, string $name, string $size, array $layers): array
    {
        return [
            'key' => $key,
            'title' => self::TITLE_PREFIX.$name,
            'size' => $size,
            'layers' => $layers,
        ];
    }

    /**
     * A shape layer: a box with a fill, a border or both.
     *
     * @param  array{0: int, 1: int, 2: int, 3: int, 4: int}  $box  x, y, width, height, zIndex
     * @param  array<string, mixed>  $properties
     */
    private static function rect(string $prefix, string $key, string $title, array $box, array $properties): array
    {
        return self::layer($prefix, $key, $title, 'empty', $box, ['backgroundType' => 'color'] + $properties);
    }

    /**
     * A full-area SVG pattern at a low opacity.
     *
     * @param  array{0: int, 1: int, 2: int, 3: int, 4: int}  $box
     * @param  array<string, mixed>  $properties
     */
    private static function pattern(string $prefix, string $key, string $title, array $box, string $design, string $color, float $opacity, array $properties = []): array
    {
        return self::layer($prefix, $key, $title, 'svg', $box, [
            'backgroundType' => 'svg',
            'backgroundSvg' => ['design' => $design, 'color' => $color],
            'opacity' => $opacity,
        ] + $properties);
    }

    /**
     * A text layer with a transparent box. The line height is in px. When the
     * line height equals the box height, one line of text is centered in the
     * box vertically.
     *
     * @param  array{0: int, 1: int, 2: int, 3: int, 4: int}  $box
     * @param  array<string, mixed>  $properties
     */
    private static function text(string $prefix, string $key, string $title, array $box, string $content, string $font, int $size, int $lineHeight, string $color, array $properties = []): array
    {
        return self::layer($prefix, $key, $title, 'text', $box, [
            'textContent' => $content,
            'fontFamily' => $font,
            'fontSize' => $size,
            'lineHeight' => $lineHeight,
            'fontColor' => $color,
            'textAlignment' => $properties['textAlignment'] ?? 'left',
            'backgroundType' => 'color',
            'backgroundColor' => 'rgba(0,0,0,0)',
        ] + $properties);
    }

    /** @return array<string, mixed> */
    private static function frame(string $color, int $width): array
    {
        return [
            'backgroundColor' => 'rgba(0,0,0,0)',
            'borderStyle' => 'solid',
            'borderWidth' => $width,
            'borderColor' => $color,
        ];
    }

    /** @return array<string, int> */
    private static function radius(int $radius): array
    {
        return [
            'borderRadiusLeftTop' => $radius,
            'borderRadiusRightTop' => $radius,
            'borderRadiusRightBottom' => $radius,
            'borderRadiusLeftBottom' => $radius,
        ];
    }

    /**
     * @param  array{0: int, 1: int, 2: int, 3: int, 4: int}  $box
     * @param  array<string, mixed>  $properties
     */
    private static function layer(string $prefix, string $key, string $title, string $type, array $box, array $properties): array
    {
        [$x, $y, $width, $height, $zIndex] = $box;

        $all = [
            'positionX' => $x,
            'positionY' => $y,
            'width' => $width,
            'height' => $height,
            'zIndex' => $zIndex,
            'rotate' => 0,
        ] + $properties;

        return [
            'id' => "starter-{$prefix}-{$key}",
            'key' => $key,
            'type' => $type,
            'title' => $title,
            'locked' => false,
            'visible' => true,
            'properties' => array_map(
                fn (string $name, mixed $value) => ['name' => $name, 'value' => $value],
                array_keys($all),
                array_values($all),
            ),
        ];
    }
}
