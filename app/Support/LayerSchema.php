<?php

namespace App\Support;

use App\Builders\SvgPatterns\SvgPatternBuilder;
use App\Builders\SvgPatterns\SvgPatternData;

/**
 * Single source of truth for the SHAPE of a template layer — the design-time
 * structure stored in `templates.layers`. Exposed via the describe-layers tool
 * and referenced by the create-template / edit-template schemas so the agent
 * builds valid layers without guessing.
 *
 * This is the design-time vocabulary (how a layer is BUILT). It is distinct from
 * App\Support\VariantProperties, which is the run-time vocabulary (how a layer is
 * OVERRIDDEN per snap). Keep the property names below in sync with the keys
 * App\Builders\LayerBuilder actually recognizes, and the validation in
 * App\Rules\ValidLayers.
 */
class LayerSchema
{
    /**
     * Property `name`s LayerBuilder knows how to render. A layer may carry other
     * names — they are ignored, not rejected — but anything here will take effect.
     *
     * @return array<int, string>
     */
    public static function propertyNames(): array
    {
        return [
            // content
            'textContent',
            // background
            'backgroundType', 'backgroundColor', 'backgroundGradientColor',
            'backgroundSvg', 'backgroundImage', 'backgroundSize',
            // box / position
            'width', 'height', 'positionX', 'positionY', 'zIndex', 'opacity',
            'rotate', 'shadow',
            // effects (design-time)
            'flipHorizontal', 'flipVertical', 'mixBlendMode',
            'filterBlur', 'filterBrightness', 'filterContrast', 'filterSaturate', 'filterGrayscale',
            // border
            'borderStyle', 'borderWidth', 'borderColor',
            'borderRadiusLeftTop', 'borderRadiusRightTop',
            'borderRadiusRightBottom', 'borderRadiusLeftBottom',
            // text
            'fontFamily', 'fontSize', 'fontColor', 'lineHeight',
            'textAlignment', 'autoResize',
            // qr_code layers
            'qrContent', 'qrContentType',
            // barcode layers
            'barcodeContent', 'barcodeFormat',
        ];
    }

    /**
     * Layer `type` values that get special rendering. Any other (or absent) type
     * renders as a generic styled box driven purely by its properties.
     *
     * @return array<int, string>
     */
    public static function layerTypes(): array
    {
        return ['qr_code', 'barcode'];
    }

    /**
     * The valid `design` names a backgroundSvg property accepts. Sourced from
     * SvgPatternData (the renderer's single source of truth) so the list the MCP
     * agent sees can never drift from what SvgPatternBuilder actually renders.
     *
     * @return array<int, string>
     */
    public static function svgPatternDesigns(): array
    {
        return array_keys(SvgPatternData::PATTERNS);
    }

    /**
     * Full structural reference for the agent.
     *
     * @return array<string, mixed>
     */
    public static function catalog(): array
    {
        return [
            'layer_shape' => [
                'description' => 'Each item in `layers` is an object with these fields.',
                'fields' => [
                    'key' => 'REQUIRED. Unique, non-empty string identifying the layer '
                        .'(e.g. "headline", "logo", "qr"). `variants` target a layer by this key, '
                        .'so it must be unique within a template.',
                    'type' => 'OPTIONAL string. Use "qr_code" for QR layers, "barcode" for barcode '
                        .'layers; omit for a normal styled box / text / image layer.',
                    'properties' => 'REQUIRED array of { "name": <string>, "value": <mixed> } objects. '
                        .'Each property sets one visual aspect. Unknown names are ignored.',
                ],
            ],
            'property_names' => self::propertyNames(),
            'svg_pattern_designs' => [
                'description' => 'Valid `design` names for a backgroundSvg property. Use one of these '
                    .'exact strings; an unrecognized name renders the "'.SvgPatternBuilder::FALLBACK_DESIGN.'" '
                    .'fallback. Recolor with the property\'s "color" key.',
                'names' => self::svgPatternDesigns(),
            ],
            'value_shapes' => [
                'textContent' => 'string — the rendered text. Pair with a "text" variant to override.',
                'backgroundType' => 'string — "color" | "linear" | "radial" | "image" | "svg". '
                    .'Required when using backgroundGradientColor (set "linear"/"radial") or '
                    .'backgroundSvg (set "svg") so the editor paints the right background.',
                'backgroundColor' => 'string (#hex or css color).',
                'backgroundGradientColor' => 'object — gradient definition; shape depends on backgroundType. '
                    .'For "linear": { "degrees": <number>, "steps": [ { "color": "#hex", "percentage": <number> }, ... ] }. '
                    .'For "radial": { "from": "#hex", "to": "#hex" }. NOT a string.',
                'backgroundSvg' => 'object — { "design": <pattern name>, "color": "#hex" }. '
                    .'`design` must be one of the names in `svg_pattern_designs` below; an '
                    .'unknown name falls back to "'.SvgPatternBuilder::FALLBACK_DESIGN.'". '
                    .'`color` is the pattern\'s ink color (#hex or css color).',
                'backgroundImage' => 'string — public http/https URL or data: URI. Internal / reserved / '
                    .'file:// addresses are rejected (SSRF guard).',
                'backgroundSize' => 'string — css background-size (defaults to "cover").',
                'width' => 'number — px.',
                'height' => 'number — px.',
                'positionX' => 'number — px from the left edge.',
                'positionY' => 'number — px from the top edge.',
                'zIndex' => 'number — stacking order.',
                'opacity' => 'number — 0..1.',
                'rotate' => 'number — degrees.',
                'shadow' => 'object — { "x": <px>, "y": <px>, "blur": <px>, "spread": <px>, "color": "#hex" }.',
                'flipHorizontal' => 'boolean — mirror the layer left-to-right.',
                'flipVertical' => 'boolean — mirror the layer top-to-bottom.',
                'mixBlendMode' => 'string — a CSS mix-blend-mode keyword (e.g. "multiply", "screen").',
                'filterBlur' => 'number — gaussian blur radius in px (0 = none).',
                'filterBrightness' => 'number — brightness multiplier (1 = unchanged).',
                'filterContrast' => 'number — contrast multiplier (1 = unchanged).',
                'filterSaturate' => 'number — saturation multiplier (1 = unchanged).',
                'filterGrayscale' => 'number — 0..1 (0 = none, 1 = full grayscale).',
                'borderRadiusLeftTop' => 'number — px (one per corner).',
                'fontFamily' => 'string — a Google Fonts family name (loaded automatically).',
                'fontSize' => 'number — px.',
                'fontColor' => 'string (#hex or css color).',
                'lineHeight' => 'number — px.',
                'textAlignment' => 'string — "left" | "center" | "right".',
                'autoResize' => 'boolean — when true, the rendered text shrinks to fit its box '
                    .'(font-size is auto-managed; any fontSize is ignored).',
                'qrContent' => 'string — content encoded by a qr_code layer (often a URL).',
                'qrContentType' => 'string — label for the QR content type (e.g. "url", "text").',
                'barcodeContent' => 'string — value encoded by a barcode layer. Must be valid for the '
                    .'chosen barcodeFormat (e.g. EAN-13 needs 12-13 digits, UPC-A needs 12).',
                'barcodeFormat' => 'string — symbology. One of "Code128", "EAN-13", "UPC-A".',
            ],
            'example' => [
                [
                    'key' => 'background',
                    'properties' => [
                        ['name' => 'width', 'value' => 1080],
                        ['name' => 'height', 'value' => 1080],
                        ['name' => 'positionX', 'value' => 0],
                        ['name' => 'positionY', 'value' => 0],
                        ['name' => 'backgroundColor', 'value' => '#1d4ed8'],
                    ],
                ],
                [
                    'key' => 'pattern',
                    'properties' => [
                        ['name' => 'width', 'value' => 1080],
                        ['name' => 'height', 'value' => 1080],
                        ['name' => 'positionX', 'value' => 0],
                        ['name' => 'positionY', 'value' => 0],
                        ['name' => 'backgroundType', 'value' => 'svg'],
                        ['name' => 'backgroundSvg', 'value' => ['design' => 'diagonalStripes', 'color' => '#1d4ed8']],
                    ],
                ],
                [
                    'key' => 'headline',
                    'properties' => [
                        ['name' => 'textContent', 'value' => 'Summer Sale'],
                        ['name' => 'positionX', 'value' => 80],
                        ['name' => 'positionY', 'value' => 120],
                        ['name' => 'fontFamily', 'value' => 'Poppins'],
                        ['name' => 'fontSize', 'value' => 72],
                        ['name' => 'fontColor', 'value' => '#ffffff'],
                    ],
                ],
            ],
        ];
    }
}
