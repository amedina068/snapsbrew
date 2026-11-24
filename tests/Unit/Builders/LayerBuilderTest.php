<?php

namespace Tests\Unit\Builders;

use App\Builders\LayerBuilder;
use Tests\TestCase;

class LayerBuilderTest extends TestCase
{
    public function test_shape_only_renders_a_safe_inline_svg(): void
    {
        $safe = (new LayerBuilder(['type' => 'shape', 'properties' => [['name' => 'inlineSvg', 'value' => '<svg><path d="M0 0"/></svg>']]]))->build();
        $unsafe = (new LayerBuilder(['type' => 'shape', 'properties' => [['name' => 'inlineSvg', 'value' => '<svg><script>alert(1)</script></svg>']]]))->build();

        $this->assertStringContainsString('<path', $safe);
        $this->assertStringNotContainsString('<script', $unsafe);
    }

    public function test_transform_origin_and_clipping_are_explicit(): void
    {
        $html = (new LayerBuilder(['properties' => [
            ['name' => 'rotate', 'value' => 15],
            ['name' => 'transformOrigin', 'value' => 'top left'],
            ['name' => 'clipContent', 'value' => true],
        ]]))->build();

        $this->assertStringContainsString('transform-origin:top left', $html);
        $this->assertStringContainsString('overflow:hidden', $html);
    }

    public function test_normalized_effects_emit_bounded_blur_and_stroke(): void
    {
        $html = (new LayerBuilder(['properties' => [[
            'name' => 'effects', 'value' => ['blur' => 3, 'stroke' => ['width' => 2, 'style' => 'dashed', 'color' => '#123456']],
        ]]]))->build();

        $this->assertStringContainsString('filter:blur(3px)', $html);
        $this->assertStringContainsString('border:2px dashed #123456', $html);
    }

    public function test_typed_typography_is_bounded_in_renderer(): void
    {
        $html = (new LayerBuilder(['properties' => [
            ['name' => 'fontWeight', 'value' => 1200],
            ['name' => 'fontStyle', 'value' => 'italic'],
            ['name' => 'letterSpacing', 'value' => 8],
        ]]))->build();

        $this->assertStringContainsString('font-weight:900', $html);
        $this->assertStringContainsString('font-style:italic', $html);
        $this->assertStringContainsString('letter-spacing:8px', $html);
    }

    public function test_image_crop_and_mask_are_emitted_with_bounded_css(): void
    {
        $layer = [
            'properties' => [
                ['name' => 'backgroundImage', 'value' => 'https://cdn.example.test/photo.png'],
                ['name' => 'imageFit', 'value' => 'contain'],
                ['name' => 'imagePositionX', 'value' => 120],
                ['name' => 'imagePositionY', 'value' => -2],
                ['name' => 'imageMask', 'value' => 'circle'],
            ],
        ];

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('background-size: contain', $html);
        $this->assertStringContainsString('background-position:100% 0%', $html);
        $this->assertStringContainsString('border-radius:50%', $html);
    }

    public function test_manual_image_focal_point_wins_over_crop_position(): void
    {
        $html = (new LayerBuilder(['properties' => [
            ['name' => 'backgroundImage', 'value' => 'https://cdn.example.test/photo.png'],
            ['name' => 'imagePositionX', 'value' => 10],
            ['name' => 'imagePositionY', 'value' => 20],
            ['name' => 'imageFocalX', 'value' => 70],
            ['name' => 'imageFocalY', 'value' => 80],
        ]]))->build();

        $this->assertStringContainsString('background-position:70% 80%', $html);
    }

    private function makeLayer(array $properties = [], string $type = 'text', string $key = 'layer_1'): array
    {
        return [
            'key' => $key,
            'type' => $type,
            'properties' => $properties,
        ];
    }

    public function test_build_returns_div()
    {
        $layer = $this->makeLayer([
            ['name' => 'width', 'value' => 200],
            ['name' => 'height', 'value' => 100],
        ]);

        $builder = new LayerBuilder($layer);
        $html = $builder->build();

        $this->assertStringStartsWith('<div style="', $html);
        $this->assertStringEndsWith('</div>', $html);
    }

    public function test_default_styles_included()
    {
        $layer = $this->makeLayer();
        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('box-sizing:border-box', $html);
        $this->assertStringContainsString('position: absolute', $html);
        $this->assertStringContainsString('background-repeat:no-repeat', $html);
        $this->assertStringContainsString('background-position:center', $html);
    }

    public function test_dimension_properties_in_pixels()
    {
        $layer = $this->makeLayer([
            ['name' => 'width', 'value' => 300],
            ['name' => 'height', 'value' => 150],
            ['name' => 'positionX', 'value' => 50],
            ['name' => 'positionY', 'value' => 75],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('width:300px', $html);
        $this->assertStringContainsString('height:150px', $html);
        $this->assertStringContainsString('left:50px', $html);
        $this->assertStringContainsString('top:75px', $html);
    }

    public function test_text_content()
    {
        $layer = $this->makeLayer([
            ['name' => 'textContent', 'value' => 'Hello World'],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('Hello World', $html);
    }

    public function test_font_family()
    {
        $layer = $this->makeLayer([
            ['name' => 'fontFamily', 'value' => 'Open Sans'],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString("font-family:'Open Sans'", $html);
    }

    public function test_font_size_in_pixels()
    {
        $layer = $this->makeLayer([
            ['name' => 'fontSize', 'value' => 24],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('font-size:24px', $html);
    }

    public function test_font_color()
    {
        $layer = $this->makeLayer([
            ['name' => 'fontColor', 'value' => '#ff0000'],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('color:#ff0000', $html);
    }

    public function test_z_index()
    {
        $layer = $this->makeLayer([
            ['name' => 'zIndex', 'value' => 5],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('z-index:5', $html);
    }

    public function test_text_alignment()
    {
        $layer = $this->makeLayer([
            ['name' => 'textAlignment', 'value' => 'center'],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('text-align:center', $html);
    }

    public function test_border_radius()
    {
        $layer = $this->makeLayer([
            ['name' => 'borderRadiusLeftTop', 'value' => 10],
            ['name' => 'borderRadiusRightTop', 'value' => 20],
            ['name' => 'borderRadiusRightBottom', 'value' => 30],
            ['name' => 'borderRadiusLeftBottom', 'value' => 40],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('border-top-left-radius:10px', $html);
        $this->assertStringContainsString('border-top-right-radius:20px', $html);
        $this->assertStringContainsString('border-bottom-right-radius:30px', $html);
        $this->assertStringContainsString('border-bottom-left-radius:40px', $html);
    }

    public function test_border_style()
    {
        $layer = $this->makeLayer([
            ['name' => 'borderStyle', 'value' => 'solid'],
            ['name' => 'borderWidth', 'value' => 2],
            ['name' => 'borderColor', 'value' => '#000000'],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('border-style:solid', $html);
        $this->assertStringContainsString('border-width:2px', $html);
        $this->assertStringContainsString('border-color:#000000', $html);
    }

    public function test_rotation()
    {
        $layer = $this->makeLayer([
            ['name' => 'rotate', 'value' => 45],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('transform: rotate(45deg)', $html);
    }

    public function test_rotate_and_flip_aggregate_to_single_transform()
    {
        // F2/T-201: rotate already maps to `transform`. Adding flip as a separate
        // transform declaration would let the last one win and silently drop
        // rotate. All transform contributors must compose into ONE declaration.
        $layer = $this->makeLayer([
            ['name' => 'rotate', 'value' => 45],
            ['name' => 'flipHorizontal', 'value' => true],
            ['name' => 'flipVertical', 'value' => true],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertSame(1, substr_count($html, 'transform:'), 'expected exactly one transform declaration');
        $this->assertStringContainsString('transform: rotate(45deg) scaleX(-1) scaleY(-1);', $html);
    }

    public function test_flip_only_emits_single_transform_without_rotate()
    {
        $layer = $this->makeLayer([
            ['name' => 'flipHorizontal', 'value' => true],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertSame(1, substr_count($html, 'transform:'));
        $this->assertStringContainsString('transform: scaleX(-1);', $html);
        $this->assertStringNotContainsString('rotate(', $html);
    }

    public function test_opacity()
    {
        $layer = $this->makeLayer([
            ['name' => 'opacity', 'value' => 0.5],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('opacity:0.5;', $html);
    }

    public function test_filter_properties_compose_into_single_declaration()
    {
        $layer = $this->makeLayer([
            ['name' => 'filterBlur', 'value' => 2],
            ['name' => 'filterBrightness', 'value' => 1.5],
            ['name' => 'filterGrayscale', 'value' => 1],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertSame(1, substr_count($html, 'filter:'), 'expected exactly one filter declaration');
        $this->assertStringContainsString('blur(2px)', $html);
        $this->assertStringContainsString('brightness(1.5)', $html);
        $this->assertStringContainsString('grayscale(1)', $html);
    }

    public function test_unset_filters_emit_no_filter_declaration()
    {
        $layer = $this->makeLayer([
            ['name' => 'width', 'value' => 100],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringNotContainsString('filter:', $html);
    }

    public function test_blend_mode_emitted()
    {
        $layer = $this->makeLayer([
            ['name' => 'mixBlendMode', 'value' => 'multiply'],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('mix-blend-mode:multiply', $html);
    }

    public function test_shadow()
    {
        $layer = $this->makeLayer([
            ['name' => 'shadow', 'value' => [
                'x' => 2,
                'y' => 4,
                'blur' => 6,
                'spread' => 1,
                'color' => 'rgba(0,0,0,0.5)',
            ]],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('box-shadow:2px 4px 6px 1px rgba(0,0,0,0.5)', $html);
    }

    public function test_line_height()
    {
        $layer = $this->makeLayer([
            ['name' => 'lineHeight', 'value' => 32],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('line-height:32px', $html);
    }

    public function test_background_image()
    {
        $layer = $this->makeLayer([
            ['name' => 'backgroundImage', 'value' => 'https://example.com/image.png'],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString("background-image: url('https://example.com/image.png')", $html);
        $this->assertStringContainsString('background-size: cover', $html);
    }

    public function test_background_image_with_custom_size()
    {
        $layer = $this->makeLayer([
            ['name' => 'backgroundImage', 'value' => 'https://example.com/image.png'],
            ['name' => 'backgroundSize', 'value' => 'contain'],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('background-size: contain', $html);
    }

    public function test_background_color()
    {
        $layer = $this->makeLayer([
            ['name' => 'backgroundColor', 'value' => '#336699'],
            ['name' => 'backgroundType', 'value' => 'color'],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('background: #336699', $html);
    }

    public function test_overwrite_text()
    {
        $layer = $this->makeLayer([
            ['name' => 'textContent', 'value' => 'Original'],
        ]);

        $overwrite = collect([
            ['text' => 'Replaced'],
        ]);

        $html = (new LayerBuilder($layer))->overwrite($overwrite)->build();

        $this->assertStringContainsString('Replaced', $html);
        $this->assertStringNotContainsString('Original', $html);
    }

    public function test_overwrite_image_url()
    {
        $layer = $this->makeLayer([
            ['name' => 'backgroundImage', 'value' => 'https://example.com/old.png'],
        ]);

        $overwrite = collect([
            ['image_url' => 'https://example.com/new.png'],
        ]);

        $html = (new LayerBuilder($layer))->overwrite($overwrite)->build();

        $this->assertStringContainsString('new.png', $html);
        $this->assertStringNotContainsString('old.png', $html);
    }

    public function test_overwrite_background_color()
    {
        $layer = $this->makeLayer([
            ['name' => 'backgroundColor', 'value' => '#000000'],
            ['name' => 'backgroundType', 'value' => 'color'],
        ]);

        $overwrite = collect([
            ['background_color' => '#ffffff'],
        ]);

        $html = (new LayerBuilder($layer))->overwrite($overwrite)->build();

        $this->assertStringContainsString('background: #ffffff', $html);
    }

    public function test_qr_code_placeholder_when_no_content()
    {
        $layer = $this->makeLayer([
            ['name' => 'width', 'value' => 200],
            ['name' => 'height', 'value' => 200],
            ['name' => 'qrContentType', 'value' => 'url'],
        ], 'qr_code');

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('QR CODE', $html);
    }

    public function test_qr_code_with_content()
    {
        $layer = $this->makeLayer([
            ['name' => 'width', 'value' => 200],
            ['name' => 'height', 'value' => 200],
            ['name' => 'qrContent', 'value' => 'https://example.com'],
            ['name' => 'qrContentType', 'value' => 'url'],
        ], 'qr_code');

        $html = (new LayerBuilder($layer))->build();

        $this->assertTrue(
            str_contains($html, '<img src="data:image/svg+xml;base64,') ||
            str_contains($html, 'QR CODE'),
            'Expected QR code SVG image or placeholder'
        );
    }

    public function test_qr_code_overwrite_content()
    {
        $layer = $this->makeLayer([
            ['name' => 'width', 'value' => 200],
            ['name' => 'height', 'value' => 200],
            ['name' => 'qrContent', 'value' => 'https://old.com'],
            ['name' => 'qrContentType', 'value' => 'url'],
        ], 'qr_code');

        $originalHtml = (new LayerBuilder($layer))->build();

        $overwrite = collect([
            ['qr_content' => 'https://new.com'],
        ]);

        $overwrittenHtml = (new LayerBuilder($layer))->overwrite($overwrite)->build();

        $this->assertNotSame($originalHtml, $overwrittenHtml);
    }

    public function test_background_color_without_background_type_does_not_crash()
    {
        // MCP-created layers may omit backgroundType; backgroundColor never
        // needs it, so this must render rather than fault on a null offset.
        $layer = $this->makeLayer([
            ['name' => 'backgroundColor', 'value' => '#336699'],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('background: #336699', $html);
    }

    public function test_background_gradient_without_background_type_does_not_crash()
    {
        // No backgroundType present → defaults to the linear builder instead of
        // throwing UnhandledMatchError.
        $layer = $this->makeLayer([
            ['name' => 'backgroundGradientColor', 'value' => [
                'degrees' => 90,
                'steps' => [
                    ['color' => '#000000', 'percentage' => 0],
                    ['color' => '#ffffff', 'percentage' => 100],
                ],
            ]],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('linear-gradient(90deg', $html);
        // Stops need a space between color and percentage, else the CSS is invalid.
        $this->assertStringContainsString('#000000 0%', $html);
        $this->assertStringContainsString('#ffffff 100%', $html);
    }

    public function test_background_gradient_radial_uses_radial_builder()
    {
        $layer = $this->makeLayer([
            ['name' => 'backgroundType', 'value' => 'radial'],
            ['name' => 'backgroundGradientColor', 'value' => [
                'from' => '#000000',
                'to' => '#ffffff',
            ]],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('radial-gradient(#000000, #ffffff)', $html);
        $this->assertStringNotContainsString('linear-gradient', $html);
    }

    public function test_background_gradient_with_malformed_value_does_not_crash()
    {
        $layer = $this->makeLayer([
            ['name' => 'backgroundGradientColor', 'value' => null],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('<div style="', $html);
    }

    public function test_background_property_without_value_does_not_crash()
    {
        $layer = $this->makeLayer([
            ['name' => 'backgroundColor'],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('<div style="', $html);
    }

    public function test_font_family_in_style_is_escaped()
    {
        // S2: fontFamily lands in font-family:'<v>' inside the style attribute.
        // A crafted value must not close the declaration or inject CSS.
        $layer = $this->makeLayer([
            ['name' => 'fontFamily', 'value' => "'; color:red; font-family:"],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringNotContainsString('color:red', $html);
    }

    public function test_shadow_color_in_style_is_escaped()
    {
        // S2: shadow.color is concatenated raw into box-shadow today.
        $layer = $this->makeLayer([
            ['name' => 'shadow', 'value' => [
                'x' => 2, 'y' => 4, 'blur' => 6, 'spread' => 1,
                'color' => 'rgba(0,0,0);color:red;a',
            ]],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringNotContainsString('color:red', $html);
    }

    public function test_font_color_in_style_is_escaped()
    {
        // S2: fontColor flows through the generic value branch into color:<v>;.
        $layer = $this->makeLayer([
            ['name' => 'fontColor', 'value' => 'red;background:url(//evil)'],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringNotContainsString('//evil', $html);
    }

    public function test_image_url_in_style_is_escaped()
    {
        // S2: backgroundImage lands in url('<v>'); a quote+paren must not break
        // out and start a new declaration.
        $layer = $this->makeLayer([
            ['name' => 'backgroundImage', 'value' => "x'); background:url(//evil"],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringNotContainsString('//evil', $html);
    }

    public function test_barcode_layer_renders_svg_data_uri()
    {
        $layer = $this->makeLayer([
            ['name' => 'width', 'value' => 300],
            ['name' => 'height', 'value' => 100],
            ['name' => 'barcodeContent', 'value' => '5901234123457'],
            ['name' => 'barcodeFormat', 'value' => 'EAN-13'],
        ], 'barcode');

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);
        $this->assertStringContainsString('<img', $html);
    }

    public function test_barcode_content_variant_overrides()
    {
        $layer = $this->makeLayer([
            ['name' => 'width', 'value' => 300],
            ['name' => 'height', 'value' => 100],
            ['name' => 'barcodeContent', 'value' => '12345678'],
            ['name' => 'barcodeFormat', 'value' => 'Code128'],
        ], 'barcode');

        $original = (new LayerBuilder($layer))->build();
        $overwritten = (new LayerBuilder($layer))
            ->overwrite(collect([['barcode_content' => '87654321']]))
            ->build();

        $this->assertNotSame($original, $overwritten);
    }

    public function test_invalid_barcode_content_renders_placeholder_not_exception()
    {
        // EAN-13 requires 12-13 digits; letters make picqer throw. The builder
        // must catch and render a placeholder, never 500.
        $layer = $this->makeLayer([
            ['name' => 'width', 'value' => 300],
            ['name' => 'height', 'value' => 100],
            ['name' => 'barcodeContent', 'value' => 'NOT-A-VALID-EAN'],
            ['name' => 'barcodeFormat', 'value' => 'EAN-13'],
        ], 'barcode');

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsStringIgnoringCase('barcode', $html);
        $this->assertStringNotContainsString('data:image/svg+xml;base64,', $html);
    }

    public function test_autoresize_layer_emits_data_attr_and_no_fixed_font_size()
    {
        // F4/T-301: when autoResize is on, the layer gets data-autoresize +
        // overflow:hidden and DROPS the fixed font-size (the head script owns it,
        // so a baked-in size would be the wrong starting point).
        $layer = $this->makeLayer([
            ['name' => 'textContent', 'value' => 'Fit me'],
            ['name' => 'fontSize', 'value' => 40],
            ['name' => 'autoResize', 'value' => true],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('data-autoresize', $html);
        $this->assertStringContainsString('overflow:hidden', $html);
        $this->assertStringNotContainsString('font-size:40px', $html);
    }

    public function test_non_autoresize_layer_keeps_font_size()
    {
        $layer = $this->makeLayer([
            ['name' => 'textContent', 'value' => 'Static'],
            ['name' => 'fontSize', 'value' => 40],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('font-size:40px', $html);
        $this->assertStringNotContainsString('data-autoresize', $html);
    }

    public function test_numeric_style_value_cannot_inject_css()
    {
        // S2/T-010: the numeric style branches (fontSize, positionX, dimensions,
        // rotate) concatenated the raw value into the inline style — a string
        // payload closed the attribute and injected a page-level rule.
        foreach (['fontSize', 'positionX', 'width', 'rotate'] as $name) {
            $layer = $this->makeLayer([
                ['name' => $name, 'value' => '16px;}body{background:url(//evil);x:1'],
            ]);

            $html = (new LayerBuilder($layer))->build();

            $this->assertStringNotContainsString('}body{', $html, "{$name} leaked a CSS block");
            $this->assertStringNotContainsString('//evil', $html, "{$name} leaked a url()");
        }
    }

    public function test_gradient_step_color_is_escaped()
    {
        // S2/T-006: backgroundGradientColor routes through the gradient builders,
        // which bypassed escapeCssValue — a step color could close the
        // linear-gradient() declaration and inject a new rule.
        $layer = $this->makeLayer([
            ['name' => 'backgroundGradientColor', 'value' => [
                'degrees' => 90,
                'steps' => [
                    ['color' => 'red);}body{background:url(//evil);(', 'percentage' => 0],
                    ['color' => '#ffffff', 'percentage' => 100],
                ],
            ]],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringNotContainsString('}body{', $html);
        $this->assertStringNotContainsString('//evil', $html);
    }

    public function test_radial_gradient_from_to_is_escaped()
    {
        $layer = $this->makeLayer([
            ['name' => 'backgroundType', 'value' => 'radial'],
            ['name' => 'backgroundGradientColor', 'value' => [
                'from' => 'red);}body{background:red',
                'to' => '#ffffff',
            ]],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringNotContainsString('}body{', $html);
    }

    public function test_qr_placeholder_content_type_is_escaped()
    {
        // S1/T-007: buildQrPlaceholder emitted contentType raw into HTML.
        $layer = $this->makeLayer([
            ['name' => 'width', 'value' => 200],
            ['name' => 'height', 'value' => 200],
            ['name' => 'qrContentType', 'value' => '</span><script>alert(1)</script>'],
        ], 'qr_code');

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringNotContainsStringIgnoringCase('<script>', $html);
        $this->assertStringContainsString('&lt;', $html);
    }

    public function test_text_content_is_html_escaped()
    {
        // S1: textContent is concatenated raw into the Browsershot HTML today —
        // a stored-XSS vector executing inside headless Chrome.
        $layer = $this->makeLayer([
            ['name' => 'textContent', 'value' => '<script>alert(1)</script>'],
        ]);

        $html = (new LayerBuilder($layer))->build();

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_text_variant_overwrite_is_html_escaped()
    {
        // S1: the `text` variant override is a separate emission site and must
        // also be escaped.
        $layer = $this->makeLayer([
            ['name' => 'textContent', 'value' => 'Original'],
        ]);

        $overwrite = collect([
            ['text' => '<img src=x onerror=alert(1)>'],
        ]);

        $html = (new LayerBuilder($layer))->overwrite($overwrite)->build();

        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
    }

    public function test_overwrite_returns_self()
    {
        $layer = $this->makeLayer();
        $builder = new LayerBuilder($layer);

        $result = $builder->overwrite(collect([]));

        $this->assertSame($builder, $result);
    }
}
