<?php

namespace App\Services;

use App\Builders\SnapBuilder;
use App\Models\Template;
use App\Support\InlineDesign;
use App\Support\SnapDocument;

/**
 * Builds a watermarked preview of a template (optionally with variants)
 * as a self-contained HTML document — WITHOUT a usage row, WITHOUT a screenshot,
 * and WITHOUT leaving a persisted snap behind.
 *
 * The preview is the returned markup, not a rasterized image: the caller (MCP
 * client or the preview endpoint) renders it. This skips Browsershot entirely,
 * so previews are instant and have no external render dependency. Nothing is
 * written to the ledger and nothing is persisted.
 */
class PreviewRenderer
{
    public function render(Template $template, ?array $variants = []): array
    {
        $variants = $variants ?? [];

        $result = $this->renderDesign(InlineDesign::fromTemplate($template), $variants);

        [$overridden, $defaults, $ignored] = $this->layerKeys($template, $variants);

        return $result + [
            'variants' => $variants,
            'overridden_layers' => $overridden,
            'default_layers' => $defaults,
            'ignored_layers' => $ignored,
        ];
    }

    /**
     * Render a design that lives on the request, not on a template row. Same
     * watermark, same document shell, no persistence.
     *
     * @return array{html: string, width: int, height: int}
     */
    public function renderDesign(InlineDesign $design, ?array $variants = []): array
    {
        $builder = $this->builder($design, $variants ?? []);

        $html = $this->document(
            $builder->buildHead(),
            $this->withWatermark($builder->buildContent())
        );

        return [
            'html' => $html,
            'width' => $design->width,
            'height' => $design->height,
        ];
    }

    /**
     * Build the snap content HTML with a baked-in "PREVIEW" watermark overlay.
     * Public so the watermark is unit-testable without a full document.
     */
    public function buildPreviewContent(Template $template, array $variants): string
    {
        return $this->withWatermark($this->builder(InlineDesign::fromTemplate($template), $variants)->buildContent());
    }

    private function builder(InlineDesign $design, array $variants): SnapBuilder
    {
        return (new SnapBuilder)
            ->setLayers($design->layers)
            ->setGroups($design->groups)
            ->forCompany($design->companyId)
            ->setWidth($design->width)
            ->setHeight($design->height)
            ->withVariables($variants);
    }

    /**
     * Wrap head + content in the same document shell snaps render with
     * (SnapDocument), so the preview is a standalone, openable page.
     */
    private function document(string $head, string $content): string
    {
        return SnapDocument::wrap($head, $content);
    }

    /**
     * Inject a full-canvas, repeating, rotated "PREVIEW" overlay inside the
     * content's root element so it is part of the rendered markup and cannot be
     * trivially stripped without editing the document. The
     * `snapsbrew.preview_watermark` setting turns the overlay off.
     */
    private function withWatermark(string $content): string
    {
        if (! config('snapsbrew.preview_watermark')) {
            return $content;
        }

        $overlay = '<div style="position:absolute;inset:0;pointer-events:none;'
            .'display:flex;align-items:center;justify-content:center;'
            .'transform:rotate(-30deg);font-family:Arial,Helvetica,sans-serif;'
            .'font-weight:bold;font-size:48px;line-height:1.8;text-align:center;'
            .'color:rgba(0,0,0,0.28);letter-spacing:10px;white-space:pre-wrap;">'
            .'PREVIEW PREVIEW PREVIEW PREVIEW PREVIEW PREVIEW PREVIEW PREVIEW'
            .'</div>';

        // Insert before the final closing tag of the root element so the
        // overlay is positioned relative to the sized canvas div.
        if (preg_match('/<\/div>\s*$/', $content)) {
            return preg_replace('/<\/div>\s*$/', $overlay.'</div>', $content, 1);
        }

        return $content.$overlay;
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, string>, 2: array<int, string>}
     */
    private function layerKeys(Template $template, array $variants): array
    {
        $layerKeys = collect(json_decode($template->layers, true) ?: [])
            ->pluck('key')
            ->filter()
            ->values();

        $variantLayers = collect($variants)->pluck('layer')->filter()->unique()->values();

        $overridden = $variantLayers->intersect($layerKeys)->values()->all();

        $defaults = $layerKeys->reject(fn ($key) => in_array($key, $overridden, true))
            ->values()
            ->all();

        // Variant layer keys that match no layer on the template are silently
        // dropped by SnapBuilder — surface them so the caller catches typos
        // before (preview) or instead of (create) wasting a render.
        $ignored = $variantLayers->reject(fn ($key) => $layerKeys->contains($key))
            ->values()
            ->all();

        return [$overridden, $defaults, $ignored];
    }
}
