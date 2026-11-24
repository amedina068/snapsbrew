<?php

namespace Tests\Unit\Builders;

use App\Builders\SnapBuilder;
use Tests\TestCase;

class SnapBuilderTest extends TestCase
{
    private function makeLayers(array $layers): string
    {
        return json_encode($layers);
    }

    private function makeLayer(string $key = 'layer_1', array $properties = [], string $type = 'text'): array
    {
        return [
            'key' => $key,
            'type' => $type,
            'properties' => $properties,
        ];
    }

    public function test_build_content_returns_html_div()
    {
        $layers = $this->makeLayers([
            $this->makeLayer('bg', [
                ['name' => 'width', 'value' => 100],
                ['name' => 'height', 'value' => 100],
                ['name' => 'positionX', 'value' => 0],
                ['name' => 'positionY', 'value' => 0],
            ]),
        ]);

        $builder = (new SnapBuilder)
            ->setLayers($layers)
            ->setWidth(1080)
            ->setHeight(1080);

        $content = $builder->buildContent();

        $this->assertStringContainsString('<div style="', $content);
        $this->assertStringContainsString('width:1080px', $content);
        $this->assertStringContainsString('height:1080px', $content);
        $this->assertStringContainsString('position:relative', $content);
        $this->assertStringContainsString('overflow:hidden', $content);
    }

    public function test_layer_with_hidden_variant_is_dropped_from_content()
    {
        $layers = $this->makeLayers([
            $this->makeLayer('keep_me', [['name' => 'textContent', 'value' => 'KEEPME']]),
            $this->makeLayer('hide_me', [['name' => 'textContent', 'value' => 'HIDEME']]),
        ]);

        $builder = (new SnapBuilder)
            ->setLayers($layers)
            ->setWidth(1080)
            ->setHeight(1080)
            ->withVariables([
                ['layer' => 'hide_me', 'hidden' => true],
            ]);

        $content = $builder->buildContent();

        $this->assertStringContainsString('KEEPME', $content);
        $this->assertStringNotContainsString('HIDEME', $content);
    }

    public function test_layer_without_hidden_variant_is_rendered_with_overwrite()
    {
        $layers = $this->makeLayers([
            $this->makeLayer('text_1', [['name' => 'textContent', 'value' => 'DEFAULT']]),
        ]);

        $builder = (new SnapBuilder)
            ->setLayers($layers)
            ->setWidth(1080)
            ->setHeight(1080)
            ->withVariables([
                ['layer' => 'text_1', 'text' => 'OVERWRITTEN'],
            ]);

        $content = $builder->buildContent();

        $this->assertStringContainsString('OVERWRITTEN', $content);
    }

    public function test_build_head_returns_google_fonts_link()
    {
        $layers = $this->makeLayers([
            $this->makeLayer('text_1', [
                ['name' => 'fontFamily', 'value' => 'Open Sans'],
            ]),
        ]);

        $builder = (new SnapBuilder)
            ->setLayers($layers)
            ->setWidth(1080)
            ->setHeight(1080);

        $head = $builder->buildHead();

        $this->assertStringContainsString('fonts.googleapis.com', $head);
        $this->assertStringContainsString('Open+Sans', $head);
    }

    public function test_build_head_with_multiple_fonts()
    {
        $layers = $this->makeLayers([
            $this->makeLayer('text_1', [
                ['name' => 'fontFamily', 'value' => 'Roboto'],
            ]),
            $this->makeLayer('text_2', [
                ['name' => 'fontFamily', 'value' => 'Lato'],
            ]),
        ]);

        $builder = (new SnapBuilder)
            ->setLayers($layers)
            ->setWidth(1080)
            ->setHeight(1080);

        $head = $builder->buildHead();

        $this->assertStringContainsString('Roboto', $head);
        $this->assertStringContainsString('Lato', $head);
        $this->assertStringContainsString('&family=', $head);
    }

    public function test_font_family_is_allowlisted_before_link()
    {
        // S4: a crafted fontFamily must not break out of the <link href> or
        // inject markup. Non-allowlisted families are dropped, not emitted.
        $layers = $this->makeLayers([
            $this->makeLayer('evil', [
                ['name' => 'fontFamily', 'value' => 'Roboto"><script>alert(1)</script>'],
            ]),
        ]);

        $head = (new SnapBuilder)
            ->setLayers($layers)
            ->setWidth(1080)
            ->setHeight(1080)
            ->buildHead();

        // The head legitimately carries the auto-resize <script>; assert the
        // injected payload specifically never lands (the family is dropped).
        $this->assertStringNotContainsString('alert(1)', $head);
        $this->assertStringNotContainsString('Roboto"', $head);
    }

    public function test_font_family_with_css_injection_is_dropped()
    {
        $layers = $this->makeLayers([
            $this->makeLayer('evil', [
                ['name' => 'fontFamily', 'value' => 'Roboto; }body{background:red;'],
            ]),
        ]);

        $head = (new SnapBuilder)
            ->setLayers($layers)
            ->setWidth(1080)
            ->setHeight(1080)
            ->buildHead();

        $this->assertStringNotContainsString('body{background', $head);
    }

    private function groupedLayers(): string
    {
        return json_encode([
            ['key' => 'keep', 'type' => 'text', 'properties' => [['name' => 'textContent', 'value' => 'KEEPME']]],
            ['key' => 'hide', 'type' => 'text', 'groupId' => 'g1', 'properties' => [['name' => 'textContent', 'value' => 'HIDEME']]],
        ]);
    }

    public function test_layer_in_hidden_group_is_dropped()
    {
        // F7/T-501: a layer whose groupId maps to a visible:false group is
        // skipped — same mechanism as the existing `hidden` variant.
        $content = (new SnapBuilder)
            ->setLayers($this->groupedLayers())
            ->setGroups('[{"id":"g1","visible":false}]')
            ->setWidth(1080)
            ->setHeight(1080)
            ->buildContent();

        $this->assertStringContainsString('KEEPME', $content);
        $this->assertStringNotContainsString('HIDEME', $content);
    }

    public function test_layer_in_visible_group_renders()
    {
        $content = (new SnapBuilder)
            ->setLayers($this->groupedLayers())
            ->setGroups('[{"id":"g1","visible":true}]')
            ->setWidth(1080)
            ->setHeight(1080)
            ->buildContent();

        $this->assertStringContainsString('HIDEME', $content);
    }

    public function test_dangling_group_id_renders_as_ungrouped()
    {
        // groupId references a group not present in the groups payload → renders.
        $content = (new SnapBuilder)
            ->setLayers($this->groupedLayers())
            ->setGroups('[{"id":"other","visible":false}]')
            ->setWidth(1080)
            ->setHeight(1080)
            ->buildContent();

        $this->assertStringContainsString('HIDEME', $content);
    }

    public function test_layers_render_without_groups_set()
    {
        // Back-compat: no setGroups() call at all.
        $content = (new SnapBuilder)
            ->setLayers($this->groupedLayers())
            ->setWidth(1080)
            ->setHeight(1080)
            ->buildContent();

        $this->assertStringContainsString('KEEPME', $content);
        $this->assertStringContainsString('HIDEME', $content);
    }

    public function test_autoresize_script_emitted_in_head()
    {
        // F4/T-301: the head carries a static, synchronous shrink-to-fit script
        // that runs over [data-autoresize] before Browsershot screenshots.
        $head = (new SnapBuilder)
            ->setLayers($this->makeLayers([]))
            ->setWidth(1080)
            ->setHeight(1080)
            ->buildHead();

        $this->assertStringContainsString('[data-autoresize]', $head);
        $this->assertStringContainsString('DOMContentLoaded', $head);
    }

    public function test_build_content_with_text()
    {
        $layers = $this->makeLayers([
            $this->makeLayer('text_1', [
                ['name' => 'textContent', 'value' => 'Hello World'],
                ['name' => 'width', 'value' => 500],
                ['name' => 'height', 'value' => 100],
                ['name' => 'positionX', 'value' => 10],
                ['name' => 'positionY', 'value' => 20],
            ]),
        ]);

        $builder = (new SnapBuilder)
            ->setLayers($layers)
            ->setWidth(1080)
            ->setHeight(1080);

        $content = $builder->buildContent();

        $this->assertStringContainsString('Hello World', $content);
    }

    public function test_with_variables_overrides_text()
    {
        $layers = $this->makeLayers([
            $this->makeLayer('name_layer', [
                ['name' => 'textContent', 'value' => 'Default Name'],
                ['name' => 'width', 'value' => 500],
                ['name' => 'height', 'value' => 100],
                ['name' => 'positionX', 'value' => 0],
                ['name' => 'positionY', 'value' => 0],
            ]),
        ]);

        $variables = [
            ['layer' => 'name_layer', 'text' => 'Custom Name'],
        ];

        $builder = (new SnapBuilder)
            ->setLayers($layers)
            ->setWidth(1080)
            ->setHeight(1080)
            ->withVariables($variables);

        $content = $builder->buildContent();

        $this->assertStringContainsString('Custom Name', $content);
        $this->assertStringNotContainsString('Default Name', $content);
    }

    public function test_empty_layers_produces_empty_content()
    {
        $layers = $this->makeLayers([]);

        $builder = (new SnapBuilder)
            ->setLayers($layers)
            ->setWidth(1080)
            ->setHeight(1080);

        $content = $builder->buildContent();

        $this->assertStringContainsString('width:1080px', $content);
        // Inner content should be empty
        $this->assertStringEndsWith('"></div>', $content);
    }

    public function test_layer_without_key_does_not_crash()
    {
        // Legacy / externally-edited layers may lack `key`; the render must not
        // fault with "Undefined array key 'key'" — it renders without overwrites.
        $layers = $this->makeLayers([
            ['type' => 'text', 'properties' => [
                ['name' => 'textContent', 'value' => 'NOKEY'],
            ]],
        ]);

        $content = (new SnapBuilder)
            ->setLayers($layers)
            ->setWidth(1080)
            ->setHeight(1080)
            ->buildContent();

        $this->assertStringContainsString('NOKEY', $content);
    }

    public function test_layer_without_properties_does_not_crash()
    {
        $layers = $this->makeLayers([
            ['key' => 'broken'],
        ]);

        $builder = (new SnapBuilder)
            ->setLayers($layers)
            ->setWidth(1080)
            ->setHeight(1080);

        $this->assertStringContainsString('width:1080px', $builder->buildContent());
        $this->assertStringContainsString('fonts.googleapis.com', $builder->buildHead());
    }

    public function test_fluent_interface()
    {
        $builder = new SnapBuilder;

        $result = $builder->setWidth(100);
        $this->assertSame($builder, $result);

        $result = $builder->setHeight(100);
        $this->assertSame($builder, $result);

        $result = $builder->setLayers('[]');
        $this->assertSame($builder, $result);

        $result = $builder->withVariables([]);
        $this->assertSame($builder, $result);
    }
}
