<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Structural validation for a template's `layers` definition.
 *
 * SafeLayers guards the *content* of a layer (SSRF on background images). This
 * rule guards the *shape*: every layer needs a unique string `key` and a
 * `properties` array of { name, value } objects. Without it a malformed layer
 * saves cleanly and then crashes at render time (App\Builders\SnapBuilder /
 * LayerBuilder dereference `$layer['key']` and `$property['name']`).
 *
 * Messages are written for an MCP agent: they name the offending layer by index
 * and key, say exactly what is missing, and point at the describe-layers tool.
 */
class ValidLayers implements ValidationRule
{
    private const HINT = ' Call describe-layers for the exact layer shape and an example.';

    /**
     * Size caps. SafeLayers resolves the host of every image property,
     * so an unbounded layer list or property list is an unbounded DNS loop.
     * The counts are raw: a duplicate property name still costs one scan.
     * The cap only bounds the scan when the caller lists `bail` before this
     * rule. Without `bail`, Laravel runs SafeLayers after this rule fails.
     */
    public const MAX_LAYERS = 200;

    public const MAX_PROPERTIES_PER_LAYER = 100;

    /**
     * Flat color-typed design-time properties (S3). Their values land in inline
     * CSS, so they must pass the same allowlist as variant colors.
     */
    private const COLOR_PROPERTIES = ['fontColor', 'borderColor', 'backgroundColor'];

    /**
     * Numeric style properties (S2/T-010). LayerBuilder concatenates these into
     * inline CSS as `Npx`/`Ndeg`; a non-numeric value injects a declaration.
     * The builder casts to float on emission, but the boundary must reject the
     * bad shape too. A bare number or a number with a px/deg/% unit is allowed.
     */
    private const NUMERIC_PROPERTIES = [
        'width', 'height', 'positionX', 'positionY',
        'borderRadiusLeftTop', 'borderRadiusRightTop',
        'borderRadiusRightBottom', 'borderRadiusLeftBottom',
        'borderWidth', 'fontSize', 'lineHeight', 'rotate',
        'filterBlur', 'filterBrightness', 'filterContrast', 'filterSaturate', 'filterGrayscale',
        'letterSpacing', 'fontWeight',
    ];

    /**
     * The CSS mix-blend-mode keyword allowlist (F2). The value lands in inline
     * CSS, so anything outside this set is rejected at the boundary.
     */
    private const BLEND_MODES = [
        'normal', 'multiply', 'screen', 'overlay', 'darken', 'lighten',
        'color-dodge', 'color-burn', 'hard-light', 'soft-light',
        'difference', 'exclusion', 'hue', 'saturation', 'color', 'luminosity',
    ];

    /**
     * Allowed barcode symbologies (S/F6). The user-facing format name is mapped
     * to a fixed picqer type in LayerBuilder; rejecting unknown names at the
     * boundary keeps arbitrary input out of the generator. Keep in sync with
     * App\Builders\LayerBuilder::BARCODE_FORMATS keys.
     */
    private const BARCODE_FORMATS = ['Code128', 'EAN-13', 'UPC-A'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return; // the `array` rule owns the type check
        }

        if (count($value) > self::MAX_LAYERS) {
            // Return here. The per-layer scan is the cost the cap bounds.
            $fail('A template can hold at most '.self::MAX_LAYERS.' layers; this one has '.count($value).'.'.self::HINT);

            return;
        }

        $seenKeys = [];

        foreach ($value as $index => $layer) {
            $label = "Layer #{$index}";

            if (! is_array($layer)) {
                $fail("{$label} must be an object with a `key` and a `properties` array.".self::HINT);

                continue;
            }

            $key = $layer['key'] ?? null;

            if (! is_string($key) || trim($key) === '') {
                $fail("{$label} is missing a non-empty string `key`. Every layer needs a unique key so "
                    .'variants can target it.'.self::HINT);
            } else {
                if (in_array($key, $seenKeys, true)) {
                    $fail("{$label} reuses the key `{$key}`. Layer keys must be unique within a template, "
                        .'otherwise a variant override hits every layer that shares the key.');
                }
                $seenKeys[] = $key;
                $label = "Layer `{$key}`";
            }

            if (! array_key_exists('properties', $layer) || ! is_array($layer['properties'])) {
                $fail("{$label} must include a `properties` array (use [] if the layer has no properties).".self::HINT);

                continue;
            }

            if (count($layer['properties']) > self::MAX_PROPERTIES_PER_LAYER) {
                // Skip the property scan of an over-cap layer for the same reason.
                $fail("{$label} has ".count($layer['properties']).' properties; the limit is '
                    .self::MAX_PROPERTIES_PER_LAYER.'.'.self::HINT);

                continue;
            }

            foreach ($layer['properties'] as $pIndex => $property) {
                if (! is_array($property)
                    || ! array_key_exists('name', $property)
                    || ! is_string($property['name'])
                    || ! array_key_exists('value', $property)) {
                    $fail("{$label} property #{$pIndex} must be an object with a string `name` and a `value`.".self::HINT);

                    continue;
                }

                if (in_array($property['name'], self::COLOR_PROPERTIES, true)
                    && ! ValidCssColor::matches($property['value'])) {
                    $fail("{$label} property `{$property['name']}` must be a valid CSS color "
                        .'(hex, rgb()/rgba()/hsl()/hsla(), or a named color).'.self::HINT);
                }

                if (in_array($property['name'], self::NUMERIC_PROPERTIES, true)
                    && $property['value'] !== null
                    && ! preg_match('/^-?\d+(\.\d+)?(px|deg|%)?$/', (string) $property['value'])) {
                    $fail("{$label} property `{$property['name']}` must be numeric (optionally with a px/deg/% unit).".self::HINT);
                }

                if ($property['name'] === 'barcodeFormat'
                    && ! in_array($property['value'], self::BARCODE_FORMATS, true)) {
                    $fail("{$label} property `barcodeFormat` must be one of: ".implode(', ', self::BARCODE_FORMATS).'.'.self::HINT);
                }

                if ($property['name'] === 'mixBlendMode'
                    && ! in_array($property['value'], self::BLEND_MODES, true)) {
                    $fail("{$label} property `mixBlendMode` must be a valid CSS blend mode.".self::HINT);
                }

                if ($property['name'] === 'fontStyle'
                    && ! in_array($property['value'], ['normal', 'italic'], true)) {
                    $fail("{$label} property `fontStyle` must be normal or italic.".self::HINT);
                }

                $this->validateNestedColors($label, $property, $fail);
            }
        }
    }

    /**
     * Color allowlist for the nested color shapes (S3) the flat COLOR_PROPERTIES
     * loop can't reach: gradient steps (`backgroundGradientColor.steps[].color`),
     * radial endpoints (`.from`/`.to`), and `shadow.color`. These land inside
     * `linear-gradient(...)`/`box-shadow`, so an un-allowlisted value can close
     * the declaration.
     */
    private function validateNestedColors(string $label, array $property, Closure $fail): void
    {
        $colors = [];

        if ($property['name'] === 'backgroundGradientColor' && is_array($property['value'])) {
            foreach ($property['value']['steps'] ?? [] as $step) {
                if (is_array($step) && array_key_exists('color', $step)) {
                    $colors[] = $step['color'];
                }
            }
            foreach (['from', 'to'] as $endpoint) {
                if (array_key_exists($endpoint, $property['value'])) {
                    $colors[] = $property['value'][$endpoint];
                }
            }
        }

        if ($property['name'] === 'shadow' && is_array($property['value']) && array_key_exists('color', $property['value'])) {
            $colors[] = $property['value']['color'];
        }

        foreach ($colors as $color) {
            if (! ValidCssColor::matches($color)) {
                $fail("{$label} property `{$property['name']}` contains an invalid CSS color. "
                    .'Use hex, rgb()/rgba()/hsl()/hsla(), or a named color.'.self::HINT);

                return;
            }
        }
    }
}
