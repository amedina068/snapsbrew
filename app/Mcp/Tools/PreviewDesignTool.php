<?php

namespace App\Mcp\Tools;

use App\Rules\SafeLayers;
use App\Rules\ValidLayers;
use App\Services\PreviewRenderer;
use App\Support\CanvasRules;
use App\Support\InlineDesign;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('preview-design')]
#[Title('Preview an inline design')]
#[Description('Renders a watermarked preview of a design you describe inline: a canvas (`size` preset, or `width` and `height`) plus a `layers` definition, with NO template. Nothing is saved. Returns the rendered HTML plus the exact canvas and layers it rendered. Iterate here, then pass the SAME canvas and layers to create-snap-from-design for the final un-watermarked image. Call describe-layers for the layer vocabulary and design-guide for how to compose it. If the human will reuse this design, call create-template instead so it has a saved template.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsOpenWorld]
class PreviewDesignTool extends Tool
{
    public function handle(Request $request, PreviewRenderer $renderer): array
    {
        $validated = $request->validate([
            'layers' => ['bail', 'nullable', 'array', new ValidLayers, new SafeLayers],
        ] + CanvasRules::rules(), CanvasRules::messages());

        $canvas = CanvasRules::resolve($validated);
        $layers = array_values($validated['layers'] ?? []);

        $design = InlineDesign::fromInput(
            $layers,
            $canvas['width'],
            $canvas['height'],
            $request->user()->company_id,
        );

        $result = $renderer->renderDesign($design);

        return [
            Response::text($result['html']),
            Response::text(json_encode([
                'preview' => true,
                'format' => 'html',
                'width' => $result['width'],
                'height' => $result['height'],
                'size' => $canvas['size'],
                'layers' => $layers,
                'note' => 'Watermarked HTML preview of an inline design. Nothing was saved. Render it to show the human, then call create-snap-from-design with this exact canvas and these exact layers for the final image.',
            ], JSON_PRETTY_PRINT)),
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return CanvasRules::schema($schema) + [
            'layers' => $schema->array()->description('Layer definitions (array of layer objects), the same shape create-template takes. Each layer is { "key": "<unique string>", "type"?: "<string>", "properties": [ { "name", "value" } ] }. Pass [] or omit for a blank canvas. Call describe-layers first for the property vocabulary and an example.'),
        ];
    }
}
