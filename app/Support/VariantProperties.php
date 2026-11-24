<?php

namespace App\Support;

/**
 * Single source of truth for the variant override properties an MCP client can
 * apply to a template layer. Exposed via the list-variant-properties tool and
 * referenced in tool schemas so the agent knows the full vocabulary and the
 * shape of valid values without guessing.
 *
 * Keep this in sync with App\Builders\LayerBuilder (the keys it recognizes) and
 * App\Support\VariantRules (the validation).
 */
class VariantProperties
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function catalog(): array
    {
        return [
            [
                'property' => 'text',
                'value_type' => 'string',
                'applies_to' => 'text layers',
                'description' => "Replace a text layer's content.",
                'example' => ['layer' => 'headline', 'text' => 'Summer Sale — 40% off'],
            ],
            [
                'property' => 'image_url',
                'value_type' => 'string (public http/https URL)',
                'applies_to' => 'image / background-image layers',
                'description' => 'Swap a layer\'s background image. Must be a public URL — internal, '
                    .'reserved, and file:// addresses are rejected.',
                'example' => ['layer' => 'logo', 'image_url' => 'https://cdn.example.com/logo.png'],
            ],
            [
                'property' => 'background_color',
                'value_type' => 'string (#hex)',
                'applies_to' => 'layers with a background',
                'description' => 'Set a layer background color.',
                'example' => ['layer' => 'panel', 'background_color' => '#1d4ed8'],
            ],
            [
                'property' => 'qr_content',
                'value_type' => 'string',
                'applies_to' => 'qr_code layers',
                'description' => 'Set the content encoded by a QR-code layer (often a URL).',
                'example' => ['layer' => 'qr', 'qr_content' => 'https://example.com/promo'],
            ],
            [
                'property' => 'barcode_content',
                'value_type' => 'string',
                'applies_to' => 'barcode layers',
                'description' => 'Set the value encoded by a barcode layer. Must be valid for the '
                    .'layer\'s symbology (e.g. EAN-13 needs 12-13 digits).',
                'example' => ['layer' => 'barcode', 'barcode_content' => '5901234123457'],
            ],
            [
                'property' => 'hidden',
                'value_type' => 'boolean',
                'applies_to' => 'any layer',
                'description' => 'Drop the layer from the render entirely.',
                'example' => ['layer' => 'badge', 'hidden' => true],
            ],
        ];
    }
}
