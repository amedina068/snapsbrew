<?php

namespace App\Support;

/**
 * Single source of truth for the canvas size presets Snapsbrew offers.
 *
 * Every surface that shows or resolves a preset reads this class:
 * `Template::DEFAULT_SIZES` (which aliases `ALL`), the template screens, the
 * editor size modal, the `list-template-sizes` MCP tool, and the canvas
 * presets in `App\Support\DesignGuide`.
 *
 * `value` is written to `templates.size` and is filtered on, so a value is
 * permanent. Add a preset, or change its `name`, `width`, or `height` — but do
 * not rename a `value` that is already in the database.
 */
class TemplateSizes
{
    /**
     * Platform keys in display order, with their labels.
     *
     * @var array<string, string>
     */
    public const PLATFORMS = [
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
        'x' => 'X (Twitter)',
        'linkedin' => 'LinkedIn',
        'threads' => 'Threads',
        'pinterest' => 'Pinterest',
        'tiktok' => 'TikTok',
        'youtube' => 'YouTube',
        'snapchat' => 'Snapchat',
        'whatsapp' => 'WhatsApp',
        'web' => 'Web & email',
        'ads' => 'Display ads',
        'print' => 'Print (300 dpi)',
    ];

    /**
     * The preset catalog. Order inside a platform goes from the most used size
     * to the least used one.
     *
     * @var array<int, array{name: string, value: string, width: int, height: int, platform: string}>
     */
    public const ALL = [
        // Instagram
        ['name' => 'Instagram Story (1080x1920)', 'value' => 'instagram_story', 'width' => 1080, 'height' => 1920, 'platform' => 'instagram'],
        ['name' => 'Instagram Reel (1080x1920)', 'value' => 'instagram_reel', 'width' => 1080, 'height' => 1920, 'platform' => 'instagram'],
        ['name' => 'Instagram Portrait Post (1080x1350)', 'value' => 'instagram_post', 'width' => 1080, 'height' => 1350, 'platform' => 'instagram'],
        ['name' => 'Instagram Square Post (1080x1080)', 'value' => 'instagram_square_post', 'width' => 1080, 'height' => 1080, 'platform' => 'instagram'],
        ['name' => 'Instagram Landscape Post (1080x566)', 'value' => 'instagram_landscape_post', 'width' => 1080, 'height' => 566, 'platform' => 'instagram'],
        ['name' => 'Instagram Profile Picture (320x320)', 'value' => 'instagram_profile', 'width' => 320, 'height' => 320, 'platform' => 'instagram'],

        // Facebook
        ['name' => 'Facebook Portrait Post (1080x1350)', 'value' => 'facebook_portrait', 'width' => 1080, 'height' => 1350, 'platform' => 'facebook'],
        ['name' => 'Facebook Square Post (1200x1200)', 'value' => 'facebook_card', 'width' => 1200, 'height' => 1200, 'platform' => 'facebook'],
        ['name' => 'Facebook Feed Link (1200x630)', 'value' => 'facebook_feed', 'width' => 1200, 'height' => 630, 'platform' => 'facebook'],
        ['name' => 'Facebook Story (1080x1920)', 'value' => 'facebook_story', 'width' => 1080, 'height' => 1920, 'platform' => 'facebook'],
        ['name' => 'Facebook Page Cover (1640x624)', 'value' => 'facebook_cover', 'width' => 1640, 'height' => 624, 'platform' => 'facebook'],
        ['name' => 'Facebook Event Cover (1920x1005)', 'value' => 'facebook_event_cover', 'width' => 1920, 'height' => 1005, 'platform' => 'facebook'],

        // X (Twitter)
        ['name' => 'X Summary Card (1200x670)', 'value' => 'twitter_card', 'width' => 1200, 'height' => 670, 'platform' => 'x'],
        ['name' => 'X Post Image (1600x900)', 'value' => 'x_post', 'width' => 1600, 'height' => 900, 'platform' => 'x'],
        ['name' => 'X Vertical Post (1080x1350)', 'value' => 'x_portrait', 'width' => 1080, 'height' => 1350, 'platform' => 'x'],
        ['name' => 'X Header (1500x500)', 'value' => 'x_header', 'width' => 1500, 'height' => 500, 'platform' => 'x'],

        // LinkedIn
        ['name' => 'LinkedIn Feed (1200x627)', 'value' => 'linkedin_feed', 'width' => 1200, 'height' => 627, 'platform' => 'linkedin'],
        ['name' => 'LinkedIn Square Post (1200x1200)', 'value' => 'linkedin_square', 'width' => 1200, 'height' => 1200, 'platform' => 'linkedin'],
        ['name' => 'LinkedIn Portrait Post (1080x1350)', 'value' => 'linkedin_portrait', 'width' => 1080, 'height' => 1350, 'platform' => 'linkedin'],
        ['name' => 'LinkedIn Profile Banner (1584x396)', 'value' => 'linkedin_banner', 'width' => 1584, 'height' => 396, 'platform' => 'linkedin'],

        // Threads
        ['name' => 'Threads Portrait Post (1080x1350)', 'value' => 'threads_post', 'width' => 1080, 'height' => 1350, 'platform' => 'threads'],
        ['name' => 'Threads Square Post (1080x1080)', 'value' => 'threads_square', 'width' => 1080, 'height' => 1080, 'platform' => 'threads'],

        // Pinterest
        ['name' => 'Pinterest Pin (1000x1500)', 'value' => 'pinterest_pin', 'width' => 1000, 'height' => 1500, 'platform' => 'pinterest'],
        ['name' => 'Pinterest Square Pin (1000x1000)', 'value' => 'pinterest_square', 'width' => 1000, 'height' => 1000, 'platform' => 'pinterest'],
        ['name' => 'Pinterest Tall Pin (1000x2100)', 'value' => 'pinterest_long_pin', 'width' => 1000, 'height' => 2100, 'platform' => 'pinterest'],

        // TikTok
        ['name' => 'TikTok Video (1080x1920)', 'value' => 'tiktok_vertical', 'width' => 1080, 'height' => 1920, 'platform' => 'tiktok'],
        ['name' => 'TikTok Photo Post (1080x1440)', 'value' => 'tiktok_photo', 'width' => 1080, 'height' => 1440, 'platform' => 'tiktok'],

        // YouTube
        ['name' => 'YouTube Thumbnail (1280x720)', 'value' => 'youtube_thumbnail', 'width' => 1280, 'height' => 720, 'platform' => 'youtube'],
        ['name' => 'YouTube Shorts (1080x1920)', 'value' => 'youtube_shorts', 'width' => 1080, 'height' => 1920, 'platform' => 'youtube'],
        ['name' => 'YouTube Channel Banner (2560x1440)', 'value' => 'youtube_banner', 'width' => 2560, 'height' => 1440, 'platform' => 'youtube'],

        // Snapchat
        ['name' => 'Snapchat Story (1080x1920)', 'value' => 'snapchat_story', 'width' => 1080, 'height' => 1920, 'platform' => 'snapchat'],

        // WhatsApp
        ['name' => 'WhatsApp Status (1080x1920)', 'value' => 'whatsapp_status', 'width' => 1080, 'height' => 1920, 'platform' => 'whatsapp'],

        // Web & email
        ['name' => 'Open Graph / Link Preview (1200x630)', 'value' => 'open_graph', 'width' => 1200, 'height' => 630, 'platform' => 'web'],
        ['name' => 'Blog Header (1600x900)', 'value' => 'blog_header', 'width' => 1600, 'height' => 900, 'platform' => 'web'],
        ['name' => 'Presentation Slide (1920x1080)', 'value' => 'presentation_slide', 'width' => 1920, 'height' => 1080, 'platform' => 'web'],
        ['name' => 'Email Header (600x200)', 'value' => 'email_header', 'width' => 600, 'height' => 200, 'platform' => 'web'],

        // Display ads
        ['name' => 'Medium Rectangle Ad (300x250)', 'value' => 'ad_medium_rectangle', 'width' => 300, 'height' => 250, 'platform' => 'ads'],
        ['name' => 'Leaderboard Ad (728x90)', 'value' => 'ad_leaderboard', 'width' => 728, 'height' => 90, 'platform' => 'ads'],
        ['name' => 'Large Leaderboard Ad (970x250)', 'value' => 'ad_billboard', 'width' => 970, 'height' => 250, 'platform' => 'ads'],
        ['name' => 'Half Page Ad (300x600)', 'value' => 'ad_half_page', 'width' => 300, 'height' => 600, 'platform' => 'ads'],
        ['name' => 'Wide Skyscraper Ad (160x600)', 'value' => 'ad_skyscraper', 'width' => 160, 'height' => 600, 'platform' => 'ads'],

        // Print (300 dpi)
        ['name' => 'A4 Portrait (2480x3508)', 'value' => 'print_a4_portrait', 'width' => 2480, 'height' => 3508, 'platform' => 'print'],
        ['name' => 'A4 Landscape (3508x2480)', 'value' => 'print_a4_landscape', 'width' => 3508, 'height' => 2480, 'platform' => 'print'],
        ['name' => 'A5 Portrait (1748x2480)', 'value' => 'print_a5_portrait', 'width' => 1748, 'height' => 2480, 'platform' => 'print'],
        ['name' => 'US Letter Portrait (2550x3300)', 'value' => 'print_letter_portrait', 'width' => 2550, 'height' => 3300, 'platform' => 'print'],
        ['name' => 'Business Card (1050x600)', 'value' => 'print_business_card', 'width' => 1050, 'height' => 600, 'platform' => 'print'],
    ];

    /**
     * The value stored on a template that matches no preset.
     */
    public const CUSTOM = 'custom';

    /**
     * @return array<int, array{name: string, value: string, width: int, height: int, platform: string}>
     */
    public static function all(): array
    {
        return self::ALL;
    }

    /**
     * Every preset value, plus `custom`. Use it to validate a `size` input.
     *
     * @return array<int, string>
     */
    public static function values(bool $withCustom = true): array
    {
        $values = array_column(self::ALL, 'value');

        return $withCustom ? array_merge($values, [self::CUSTOM]) : $values;
    }

    /**
     * The preset with this value, or null.
     *
     * @return array{name: string, value: string, width: int, height: int, platform: string}|null
     */
    public static function find(?string $value): ?array
    {
        if ($value === null || $value === '' || $value === self::CUSTOM) {
            return null;
        }

        foreach (self::ALL as $size) {
            if ($size['value'] === $value) {
                return $size;
            }
        }

        return null;
    }

    /**
     * The first preset with these dimensions, or null. Several presets share a
     * shape (1080x1920 is a Story, a Reel and a TikTok), so this returns the
     * one listed first.
     *
     * @return array{name: string, value: string, width: int, height: int, platform: string}|null
     */
    public static function matchDimensions(?int $width, ?int $height): ?array
    {
        foreach (self::ALL as $size) {
            if ($size['width'] === $width && $size['height'] === $height) {
                return $size;
            }
        }

        return null;
    }

    /**
     * The `templates.size` value for these dimensions. Returns `custom` when no
     * preset matches.
     */
    public static function resolveValue(?int $width, ?int $height): string
    {
        return self::matchDimensions($width, $height)['value'] ?? self::CUSTOM;
    }

    /**
     * Presets grouped by platform label, in `PLATFORMS` order. Use it to build a
     * select with option groups.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::PLATFORMS as $platform => $label) {
            $sizes = array_values(array_filter(
                self::ALL,
                fn (array $size) => $size['platform'] === $platform
            ));

            if ($sizes !== []) {
                $grouped[$label] = $sizes;
            }
        }

        return $grouped;
    }

    /**
     * The catalog an MCP agent reads: every preset with its aspect ratio and the
     * margin to keep content away from the edges.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function catalog(): array
    {
        return array_map(fn (array $size) => self::describeSize($size), self::ALL);
    }

    /**
     * One catalog entry by preset value, or null. Use it when a guidance
     * catalog names a preset and needs its dimensions — the guidance stores the
     * name, this class owns the numbers.
     *
     * @return array<string, mixed>|null
     */
    public static function describe(?string $value): ?array
    {
        $size = self::find($value);

        return $size === null ? null : self::describeSize($size);
    }

    /**
     * Every catalog entry for one platform key (for example `instagram`), in
     * catalog order.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forPlatform(string $platform): array
    {
        return array_values(array_map(
            fn (array $size) => self::describeSize($size),
            array_filter(self::ALL, fn (array $size) => $size['platform'] === $platform)
        ));
    }

    /**
     * The shape every catalog reader sees: the preset plus the two numbers
     * derived from its dimensions.
     *
     * @param  array{name: string, value: string, width: int, height: int, platform: string}  $size
     * @return array<string, mixed>
     */
    private static function describeSize(array $size): array
    {
        return [
            'size' => $size['value'],
            'name' => $size['name'],
            'platform' => self::PLATFORMS[$size['platform']] ?? $size['platform'],
            'width' => $size['width'],
            'height' => $size['height'],
            'aspect_ratio' => self::aspectRatio($size['width'], $size['height']),
            'safe_margin_px' => self::safeMargin($size['width'], $size['height']),
        ];
    }

    /**
     * The inset that keeps text, logos and buttons away from the canvas edges.
     * A 9:16 canvas gets a larger margin because the platform draws its own
     * controls over the top and the bottom of the screen.
     */
    public static function safeMargin(int $width, int $height): int
    {
        $shortEdge = min($width, $height);
        $ratio = $height > 0 ? $width / $height : 1;

        $factor = $ratio <= 0.6 ? 0.09 : 0.075;

        return max(16, (int) round($shortEdge * $factor));
    }

    /**
     * The aspect ratio in `w:h` form, for example `9:16`. A ratio that does not
     * reduce to small numbers is written against 1, for example `1.91:1`.
     */
    public static function aspectRatio(int $width, int $height): string
    {
        $divisor = self::greatestCommonDivisor($width, $height);

        if ($divisor === 0 || $height === 0) {
            return "{$width}:{$height}";
        }

        $reducedWidth = (int) ($width / $divisor);
        $reducedHeight = (int) ($height / $divisor);

        if ($reducedWidth <= 20 && $reducedHeight <= 20) {
            return "{$reducedWidth}:{$reducedHeight}";
        }

        return $width >= $height
            ? round($width / $height, 2).':1'
            : '1:'.round($height / $width, 2);
    }

    private static function greatestCommonDivisor(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return abs($a);
    }
}
