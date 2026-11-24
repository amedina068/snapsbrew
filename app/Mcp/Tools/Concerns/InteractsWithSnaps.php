<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Template;
use App\Parsers\TemplateLayerParser;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;

trait InteractsWithSnaps
{
    /**
     * The `variants` input schema, shared by every tool that overrides layers.
     * Validation (incl. SafeUrl on image_url) is enforced in handle() via
     * VariantRules; this schema teaches the AI the exact shape.
     */
    protected function variantsSchema(JsonSchema $schema)
    {
        return $schema->array()
            ->description(
                'Per-layer overrides. Each item is an object with a "layer" key '
                .'(a layer key from get-template-details) plus exactly one of: '
                .'"text" (string), "image_url" (public https URL), '
                .'"background_color" (#hex), "qr_content" (string), '
                .'"barcode_content" (string, valid for the barcode symbology), or '
                .'"hidden" (true to drop the layer). '
                .'Example: [{"layer":"headline","text":"Hello"},'
                .'{"layer":"logo","image_url":"https://cdn.example.com/logo.png"}]'
            )
            ->items($schema->object([
                'layer' => $schema->string()->description('Layer key to override.')->required(),
                'text' => $schema->string()->description('Replacement text for a text layer.'),
                'image_url' => $schema->string()->description('Public image URL for an image layer.'),
                'background_color' => $schema->string()->description('Background color as #hex.'),
                'qr_content' => $schema->string()->description('Content for a QR-code layer.'),
                'barcode_content' => $schema->string()->description('Value for a barcode layer. It must be valid for the barcode symbology of the layer, for example 12 or 13 digits for EAN-13.'),
                'hidden' => $schema->boolean()->description('Set true to hide the layer.'),
            ]));
    }

    /**
     * Resolve a template by UUID scoped to the caller's company. Returns null
     * (never another company's row) when not found.
     */
    protected function companyTemplate(Request $request, ?string $uuid): ?Template
    {
        if (! $uuid) {
            return null;
        }

        return Template::query()
            ->where('company_id', $request->user()->company_id)
            ->where('uuid', $uuid)
            ->first();
    }

    /**
     * Parsed layer summary (layer key + available modifications) for a template.
     */
    protected function parsedLayers(Template $template): array
    {
        return (new TemplateLayerParser(json_decode($template->layers, true) ?: []))->parse();
    }

    /**
     * Variant layer keys that don't match any layer on the template. SnapBuilder
     * silently drops these, so surfacing them lets the agent catch typos instead
     * of shipping a render that ignored its intent.
     *
     * @param  array<int, array<string, mixed>>  $variants
     * @return array<int, string>
     */
    protected function ignoredVariantLayers(Template $template, array $variants): array
    {
        $layerKeys = collect(json_decode($template->layers, true) ?: [])->pluck('key');

        return collect($variants)
            ->pluck('layer')
            ->filter()
            ->unique()
            ->reject(fn ($key) => $layerKeys->contains($key))
            ->values()
            ->all();
    }
}
