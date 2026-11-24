<?php

namespace Tests\Unit\Services;

use App\Models\Template;
use App\Services\PreviewRenderer;
use App\Support\InlineDesign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreviewRendererTest extends TestCase
{
    use RefreshDatabase;

    private function template(): Template
    {
        return Template::factory()->withLayers([
            ['key' => 'headline', 'type' => 'text', 'properties' => [
                ['name' => 'textContent', 'value' => 'Default'],
            ]],
        ])->create(['width' => '100', 'height' => '100']);
    }

    public function test_render_leaves_no_snap_row()
    {
        app(PreviewRenderer::class)->render($this->template());

        $this->assertDatabaseCount('snaps', 0);
    }

    public function test_render_writes_no_api_log()
    {
        app(PreviewRenderer::class)->render($this->template());

        $this->assertDatabaseCount('api_logs', 0);
    }

    public function test_render_returns_html_width_height()
    {
        $result = app(PreviewRenderer::class)->render($this->template());

        $this->assertStringContainsString('<!DOCTYPE html>', $result['html']);
        $this->assertStringContainsString('PREVIEW', $result['html']);
        $this->assertSame(100, $result['width']);
        $this->assertSame(100, $result['height']);
    }

    public function test_preview_content_is_watermarked()
    {
        $html = app(PreviewRenderer::class)->buildPreviewContent($this->template(), []);

        $this->assertStringContainsString('PREVIEW', $html);
    }

    public function test_the_watermark_switch_turns_the_overlay_off_for_templates_and_designs()
    {
        config(['snapsbrew.preview_watermark' => false]);

        $template = app(PreviewRenderer::class)->render($this->template());
        $design = app(PreviewRenderer::class)->renderDesign(InlineDesign::fromTemplate($this->template()));

        $this->assertStringNotContainsString('PREVIEW', $template['html']);
        $this->assertStringNotContainsString('PREVIEW', $design['html']);
        $this->assertStringContainsString('Default', $template['html']);
    }

    public function test_the_watermark_is_on_when_the_setting_is_absent()
    {
        $this->assertTrue(config('snapsbrew.preview_watermark'));
    }

    public function test_render_reports_overridden_layer_keys()
    {
        $result = app(PreviewRenderer::class)->render($this->template(), [
            ['layer' => 'headline', 'text' => 'Custom'],
        ]);

        $this->assertContains('headline', $result['overridden_layers']);
    }

    public function test_render_reports_ignored_unknown_layer_keys()
    {
        $result = app(PreviewRenderer::class)->render($this->template(), [
            ['layer' => 'headline', 'text' => 'Custom'],
            ['layer' => 'nope_typo', 'text' => 'X'],
        ]);

        $this->assertContains('nope_typo', $result['ignored_layers']);
        $this->assertNotContains('headline', $result['ignored_layers']);
    }

    public function test_render_design_returns_watermarked_html_at_the_design_size()
    {
        $design = new InlineDesign(
            layers: json_encode([
                ['key' => 'headline', 'type' => 'text', 'properties' => [
                    ['name' => 'textContent', 'value' => 'Inline'],
                ]],
            ]),
            groups: null,
            width: 640,
            height: 360,
            companyId: null,
        );

        $result = app(PreviewRenderer::class)->renderDesign($design);

        $this->assertStringContainsString('<!DOCTYPE html>', $result['html']);
        $this->assertStringContainsString('PREVIEW', $result['html']);
        $this->assertStringContainsString('Inline', $result['html']);
        $this->assertSame(640, $result['width']);
        $this->assertSame(360, $result['height']);
        $this->assertDatabaseCount('snaps', 0);
    }
}
