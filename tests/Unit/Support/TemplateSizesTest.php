<?php

namespace Tests\Unit\Support;

use App\Models\Template;
use App\Support\TemplateSizes;
use Tests\TestCase;

class TemplateSizesTest extends TestCase
{
    public function test_every_preset_has_the_full_shape()
    {
        foreach (TemplateSizes::all() as $size) {
            $this->assertArrayHasKey('name', $size);
            $this->assertArrayHasKey('value', $size);
            $this->assertArrayHasKey('width', $size);
            $this->assertArrayHasKey('height', $size);
            $this->assertArrayHasKey('platform', $size);
            $this->assertArrayHasKey($size['platform'], TemplateSizes::PLATFORMS);
        }
    }

    public function test_preset_values_are_unique()
    {
        $values = array_column(TemplateSizes::all(), 'value');

        $this->assertSame(array_unique($values), $values);
    }

    public function test_no_preset_exceeds_the_maximum_dimension()
    {
        foreach (TemplateSizes::all() as $size) {
            $this->assertGreaterThan(0, $size['width']);
            $this->assertGreaterThan(0, $size['height']);
            $this->assertLessThanOrEqual(Template::MAX_DIMENSION, $size['width']);
            $this->assertLessThanOrEqual(Template::MAX_DIMENSION, $size['height']);
        }
    }

    public function test_template_model_reads_the_same_catalog()
    {
        $this->assertSame(TemplateSizes::ALL, Template::DEFAULT_SIZES);
    }

    public function test_find_returns_the_preset_and_ignores_custom()
    {
        $this->assertSame(1920, TemplateSizes::find('instagram_story')['height']);
        $this->assertNull(TemplateSizes::find('custom'));
        $this->assertNull(TemplateSizes::find(null));
        $this->assertNull(TemplateSizes::find('does_not_exist'));
    }

    public function test_resolve_value_maps_dimensions_to_a_preset_or_custom()
    {
        $this->assertSame('instagram_square_post', TemplateSizes::resolveValue(1080, 1080));
        $this->assertSame('youtube_thumbnail', TemplateSizes::resolveValue(1280, 720));
        $this->assertSame('custom', TemplateSizes::resolveValue(801, 601));
    }

    public function test_values_include_custom_only_when_asked()
    {
        $this->assertContains('custom', TemplateSizes::values());
        $this->assertNotContains('custom', TemplateSizes::values(withCustom: false));
    }

    public function test_grouped_keeps_every_preset()
    {
        $grouped = TemplateSizes::grouped();
        $count = array_sum(array_map('count', $grouped));

        $this->assertCount(count(TemplateSizes::all()), TemplateSizes::all());
        $this->assertSame(count(TemplateSizes::all()), $count);
        $this->assertArrayHasKey('Instagram', $grouped);
    }

    public function test_catalog_adds_aspect_ratio_and_safe_margin()
    {
        $story = collect(TemplateSizes::catalog())->firstWhere('size', 'instagram_story');

        $this->assertSame('9:16', $story['aspect_ratio']);
        $this->assertSame('Instagram', $story['platform']);
        $this->assertGreaterThan(0, $story['safe_margin_px']);
    }

    public function test_aspect_ratio_falls_back_to_a_decimal_ratio()
    {
        $this->assertSame('16:9', TemplateSizes::aspectRatio(1920, 1080));
        $this->assertSame('1:1', TemplateSizes::aspectRatio(1080, 1080));
        $this->assertSame('1.79:1', TemplateSizes::aspectRatio(1200, 670));
    }
}
