<?php

namespace Tests\Unit\Rules;

use App\Rules\ValidLayers;
use Tests\TestCase;

class ValidLayersTest extends TestCase
{
    private function failures(array $layers): array
    {
        $failures = [];
        (new ValidLayers)->validate('layers', $layers, function ($message) use (&$failures) {
            $failures[] = $message;
        });

        return $failures;
    }

    private function layerWithProperties(int $count, string $name): array
    {
        $properties = [];
        for ($i = 0; $i < $count; $i++) {
            $properties[] = ['name' => $name, 'value' => 12];
        }

        return ['key' => 'layer_1', 'properties' => $properties];
    }

    private function layers(int $count): array
    {
        $layers = [];
        for ($i = 1; $i <= $count; $i++) {
            $layers[] = ['key' => "layer_{$i}", 'properties' => []];
        }

        return $layers;
    }

    public function test_a_payload_over_the_layer_cap_is_rejected()
    {
        $failures = $this->failures($this->layers(ValidLayers::MAX_LAYERS + 1));

        $this->assertCount(1, $failures);
        $this->assertStringContainsString(
            'A template can hold at most 200 layers; this one has 201.',
            $failures[0]
        );
    }

    public function test_a_payload_at_the_layer_cap_passes()
    {
        $this->assertEmpty($this->failures($this->layers(ValidLayers::MAX_LAYERS)));
    }

    public function test_a_layer_over_the_property_cap_is_rejected()
    {
        $failures = $this->failures([$this->layerWithProperties(ValidLayers::MAX_PROPERTIES_PER_LAYER + 1, 'width')]);

        $this->assertCount(1, $failures);
        $this->assertStringContainsString(
            'Layer `layer_1` has 101 properties; the limit is 100.',
            $failures[0]
        );
    }

    public function test_a_layer_at_the_property_cap_passes()
    {
        $this->assertEmpty($this->failures([$this->layerWithProperties(ValidLayers::MAX_PROPERTIES_PER_LAYER, 'width')]));
    }

    public function test_duplicate_property_names_count_toward_the_property_cap()
    {
        // ValidLayers allows duplicate names and SafeLayers scans every property,
        // so the cap counts raw entries. A dedupe before the count would leave
        // that scan unbounded.
        $failures = $this->failures([$this->layerWithProperties(ValidLayers::MAX_PROPERTIES_PER_LAYER + 1, 'fontSize')]);

        $this->assertCount(1, $failures);
        $this->assertStringContainsString(
            'Layer `layer_1` has 101 properties; the limit is 100.',
            $failures[0]
        );
    }

    public function test_color_property_rejects_css_injection()
    {
        $layers = [[
            'key' => 'layer_1',
            'properties' => [
                ['name' => 'fontColor', 'value' => 'red;background:url(//evil)'],
            ],
        ]];

        $this->assertNotEmpty($this->failures($layers));
    }

    public function test_gradient_step_color_rejects_injection()
    {
        $layers = [[
            'key' => 'layer_1',
            'properties' => [
                ['name' => 'backgroundGradientColor', 'value' => [
                    'degrees' => 90,
                    'steps' => [
                        ['color' => 'red}body{background:red', 'percentage' => 0],
                        ['color' => '#ffffff', 'percentage' => 100],
                    ],
                ]],
            ],
        ]];

        $this->assertNotEmpty($this->failures($layers));
    }

    public function test_radial_gradient_color_rejects_injection()
    {
        $layers = [[
            'key' => 'layer_1',
            'properties' => [
                ['name' => 'backgroundGradientColor', 'value' => [
                    'from' => 'expression(alert(1))',
                    'to' => '#ffffff',
                ]],
            ],
        ]];

        $this->assertNotEmpty($this->failures($layers));
    }

    public function test_shadow_color_rejects_injection()
    {
        $layers = [[
            'key' => 'layer_1',
            'properties' => [
                ['name' => 'shadow', 'value' => [
                    'x' => 2, 'y' => 4, 'blur' => 6, 'spread' => 1,
                    'color' => 'red}body{background:red',
                ]],
            ],
        ]];

        $this->assertNotEmpty($this->failures($layers));
    }

    public function test_valid_gradient_and_shadow_colors_pass()
    {
        $layers = [[
            'key' => 'layer_1',
            'properties' => [
                ['name' => 'backgroundGradientColor', 'value' => [
                    'degrees' => 90,
                    'steps' => [
                        ['color' => '#000000', 'percentage' => 0],
                        ['color' => 'rgba(255,255,255,0.5)', 'percentage' => 100],
                    ],
                ]],
                ['name' => 'shadow', 'value' => [
                    'x' => 2, 'y' => 4, 'blur' => 6, 'spread' => 1, 'color' => 'rgba(0,0,0,0.5)',
                ]],
            ],
        ]];

        $this->assertEmpty($this->failures($layers));
    }

    public function test_non_numeric_dimension_rejected()
    {
        $layers = [[
            'key' => 'layer_1',
            'properties' => [
                ['name' => 'width', 'value' => '16px;}body{background:url(//evil)'],
            ],
        ]];

        $this->assertNotEmpty($this->failures($layers));
    }

    public function test_numeric_dimensions_pass()
    {
        $layers = [[
            'key' => 'layer_1',
            'properties' => [
                ['name' => 'width', 'value' => 200],
                ['name' => 'fontSize', 'value' => '16px'],
                ['name' => 'rotate', 'value' => 45],
                ['name' => 'positionX', 'value' => -10.5],
            ],
        ]];

        $this->assertEmpty($this->failures($layers));
    }

    public function test_null_dimension_is_allowed()
    {
        // null = "unset / auto": an image layer's height follows its width and
        // aspect ratio. The editor emits this for auto-sized layers.
        $layers = [[
            'key' => 'layer_1',
            'properties' => [
                ['name' => 'height', 'value' => null],
                ['name' => 'width', 'value' => 450],
            ],
        ]];

        $this->assertEmpty($this->failures($layers));
    }

    public function test_blend_mode_rejects_non_keyword()
    {
        $layers = [[
            'key' => 'l',
            'properties' => [
                ['name' => 'mixBlendMode', 'value' => 'multiply;}body{background:red'],
            ],
        ]];

        $this->assertNotEmpty($this->failures($layers));
    }

    public function test_valid_blend_mode_and_filters_pass()
    {
        $layers = [[
            'key' => 'l',
            'properties' => [
                ['name' => 'mixBlendMode', 'value' => 'multiply'],
                ['name' => 'filterBlur', 'value' => 2],
                ['name' => 'filterBrightness', 'value' => 1.5],
            ],
        ]];

        $this->assertEmpty($this->failures($layers));
    }

    public function test_non_numeric_filter_rejected()
    {
        $layers = [[
            'key' => 'l',
            'properties' => [
                ['name' => 'filterBlur', 'value' => '2px);}body{'],
            ],
        ]];

        $this->assertNotEmpty($this->failures($layers));
    }

    public function test_unknown_barcode_format_rejected()
    {
        $layers = [[
            'key' => 'bc',
            'properties' => [
                ['name' => 'barcodeFormat', 'value' => 'PDF417; rm -rf'],
            ],
        ]];

        $this->assertNotEmpty($this->failures($layers));
    }

    public function test_allowed_barcode_format_passes()
    {
        $layers = [[
            'key' => 'bc',
            'properties' => [
                ['name' => 'barcodeFormat', 'value' => 'EAN-13'],
                ['name' => 'barcodeContent', 'value' => '5901234123457'],
            ],
        ]];

        $this->assertEmpty($this->failures($layers));
    }

    public function test_valid_color_property_passes()
    {
        $layers = [[
            'key' => 'layer_1',
            'properties' => [
                ['name' => 'fontColor', 'value' => '#ff0000'],
                ['name' => 'backgroundColor', 'value' => 'rgba(0,0,0,0.5)'],
                ['name' => 'borderColor', 'value' => 'red'],
            ],
        ]];

        $this->assertEmpty($this->failures($layers));
    }
}
