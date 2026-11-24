<?php

namespace Tests\Unit;

use App\Support\ImagePurposeGuide;
use App\Support\PlatformGuide;
use App\Support\TemplateSizes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `PlatformGuide` and `ImagePurposeGuide` store preset NAMES and let
 * `TemplateSizes` own the pixel numbers. These tests are the guard on that
 * arrangement: a preset renamed or removed in `TemplateSizes` fails here
 * instead of silently dropping a size out of the guidance an agent reads.
 */
class GuidanceCatalogTest extends TestCase
{
    public function test_every_platform_in_the_size_catalog_has_a_guide()
    {
        $this->assertSame(
            array_keys(TemplateSizes::PLATFORMS),
            PlatformGuide::keys(),
            'Every platform offering size presets must also offer publishing guidance, in the same order.'
        );
    }

    public function test_every_platform_start_here_preset_exists()
    {
        foreach (PlatformGuide::catalog() as $guide) {
            $this->assertNotNull(
                TemplateSizes::describe($guide['start_here']),
                "Platform `{$guide['platform']}` starts at unknown preset `{$guide['start_here']}`."
            );
        }
    }

    public function test_every_platform_start_here_preset_belongs_to_that_platform()
    {
        foreach (PlatformGuide::catalog() as $guide) {
            $owned = array_column($guide['sizes'], 'size');

            $this->assertContains(
                $guide['start_here'],
                $owned,
                "Platform `{$guide['platform']}` starts at `{$guide['start_here']}`, which belongs to another platform."
            );
        }
    }

    public function test_every_platform_safe_zone_names_a_real_preset()
    {
        foreach (PlatformGuide::catalog() as $guide) {
            foreach ($guide['ui_safe_zones'] as $zone) {
                $preset = TemplateSizes::describe($zone['size']);

                $this->assertNotNull(
                    $preset,
                    "Platform `{$guide['platform']}` declares a safe zone for unknown preset `{$zone['size']}`."
                );

                $this->assertLessThan(
                    $preset['height'],
                    $zone['top_px'] + $zone['bottom_px'],
                    "The safe zone for `{$zone['size']}` leaves no vertical room."
                );

                $this->assertLessThan(
                    $preset['width'],
                    $zone['left_px'] + $zone['right_px'],
                    "The safe zone for `{$zone['size']}` leaves no horizontal room."
                );
            }
        }
    }

    public function test_every_platform_guide_carries_rules_and_anti_patterns()
    {
        foreach (PlatformGuide::catalog() as $guide) {
            $this->assertNotEmpty($guide['rules'], "Platform `{$guide['platform']}` has no rules.");
            $this->assertNotEmpty($guide['avoid'], "Platform `{$guide['platform']}` has no anti-patterns.");
            $this->assertNotEmpty($guide['sizes'], "Platform `{$guide['platform']}` has no size presets.");
        }
    }

    #[DataProvider('platformAliases')]
    public function test_platform_aliases_resolve(string $input, ?string $expected)
    {
        $this->assertSame($expected, PlatformGuide::resolve($input));
    }

    /**
     * @return array<int, array{0: string, 1: string|null}>
     */
    public static function platformAliases(): array
    {
        return [
            ['instagram', 'instagram'],
            ['Insta', 'instagram'],
            ['IG', 'instagram'],
            ['instagram story', 'instagram'],
            ['twitter', 'x'],
            ['X (Twitter)', 'x'],
            ['fb', 'facebook'],
            ['open graph', 'web'],
            ['youtube shorts', 'youtube'],
            ['a post for LinkedIn', 'linkedin'],
            ['', null],
            ['myspace', null],
        ];
    }

    public function test_every_purpose_names_at_least_one_real_preset()
    {
        foreach (ImagePurposeGuide::catalog() as $purpose) {
            $this->assertNotEmpty(
                $purpose['sizes'],
                "Purpose `{$purpose['purpose']}` resolved to no size presets — a name in its `sizes` list is stale."
            );
        }
    }

    public function test_no_purpose_drops_a_size_during_hydration()
    {
        foreach (ImagePurposeGuide::index() as $listed) {
            $hydrated = ImagePurposeGuide::find($listed['purpose']);

            $this->assertCount(
                count($listed['sizes']),
                $hydrated['sizes'],
                "Purpose `{$listed['purpose']}` names a preset that `TemplateSizes` does not define."
            );
        }
    }

    public function test_every_purpose_carries_rules_a_recipe_and_a_checklist()
    {
        foreach (ImagePurposeGuide::catalog() as $purpose) {
            $this->assertNotEmpty($purpose['rules'], "Purpose `{$purpose['purpose']}` has no rules.");
            $this->assertNotEmpty($purpose['avoid'], "Purpose `{$purpose['purpose']}` has no anti-patterns.");
            $this->assertNotEmpty($purpose['checklist'], "Purpose `{$purpose['purpose']}` has no checklist.");
            $this->assertNotEmpty($purpose['layer_recipe'], "Purpose `{$purpose['purpose']}` has no layer recipe.");
        }
    }

    public function test_every_purpose_cross_reference_names_a_real_platform()
    {
        foreach (ImagePurposeGuide::catalog() as $purpose) {
            foreach ($purpose['also_read'] as $platform) {
                $this->assertContains(
                    $platform,
                    PlatformGuide::keys(),
                    "Purpose `{$purpose['purpose']}` points at unknown platform `{$platform}`."
                );
            }
        }
    }

    public function test_the_link_preview_purpose_carries_the_meta_tags()
    {
        $guide = ImagePurposeGuide::find('link_preview');

        $this->assertStringContainsString('og:image', $guide['meta_tags']['html']);
        $this->assertStringContainsString('og:image:width', $guide['meta_tags']['html']);
        $this->assertStringContainsString('summary_large_image', $guide['meta_tags']['html']);
    }

    #[DataProvider('purposeAliases')]
    public function test_purpose_aliases_resolve(string $input, ?string $expected)
    {
        $this->assertSame($expected, ImagePurposeGuide::resolve($input));
    }

    /**
     * @return array<int, array{0: string, 1: string|null}>
     */
    public static function purposeAliases(): array
    {
        return [
            ['link_preview', 'link_preview'],
            ['link preview', 'link_preview'],
            ['og:image', 'link_preview'],
            ['meta tag', 'link_preview'],
            ['Open Graph', 'link_preview'],
            ['gallery', 'carousel'],
            ['carousel', 'carousel'],
            ['avatar', 'profile_picture'],
            ['YouTube thumbnail', 'video_thumbnail'],
            ['product photo', 'product_shot'],
            ['newsletter', 'email_header'],
            ['', null],
            ['billboard', null],
        ];
    }

    public function test_template_sizes_describes_one_preset_in_the_catalog_shape()
    {
        $described = TemplateSizes::describe('instagram_story');

        $this->assertSame('instagram_story', $described['size']);
        $this->assertSame(1080, $described['width']);
        $this->assertSame(1920, $described['height']);
        $this->assertSame('9:16', $described['aspect_ratio']);
        $this->assertArrayHasKey('safe_margin_px', $described);

        $this->assertNull(TemplateSizes::describe('myspace_bulletin'));
        $this->assertNull(TemplateSizes::describe(null));
    }

    public function test_template_sizes_returns_only_one_platforms_presets()
    {
        $presets = TemplateSizes::forPlatform('pinterest');

        $this->assertNotEmpty($presets);
        $this->assertSame(
            ['pinterest_pin', 'pinterest_square', 'pinterest_long_pin'],
            array_column($presets, 'size')
        );
        $this->assertSame([], TemplateSizes::forPlatform('myspace'));
    }
}
