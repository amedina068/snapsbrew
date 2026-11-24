<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolError;
use App\Mcp\Tools\Concerns\InteractsWithSnaps;
use App\Services\PreviewRenderer;
use App\Support\VariantRules;
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

#[Name('preview-template')]
#[Title('Preview a template')]
#[Description('Renders a watermarked preview of a template as a self-contained HTML document so the human can see it before the final render. Optionally apply variants. Returns the rendered HTML plus the exact variants used. Use this to iterate, then call create-snap with the same variants to produce the final un-watermarked snap.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsOpenWorld]
class PreviewTemplateTool extends Tool
{
    use InteractsWithSnaps;

    public function handle(Request $request, PreviewRenderer $renderer): array
    {
        $validated = $request->validate([
            'template_id' => ['required', 'string'],
        ] + VariantRules::rules());

        $template = $this->companyTemplate($request, $validated['template_id']);

        if (! $template) {
            return [ToolError::templateNotFound($validated['template_id'])];
        }

        $result = $renderer->render($template, $request->get('variants', []) ?? []);

        return [
            Response::text($result['html']),
            Response::text(json_encode([
                'preview' => true,
                'format' => 'html',
                'template_id' => $template->uuid,
                'width' => $result['width'],
                'height' => $result['height'],
                'variants' => $result['variants'],
                'overridden_layers' => $result['overridden_layers'],
                'default_layers' => $result['default_layers'],
                'ignored_layers' => $result['ignored_layers'],
                'note' => empty($result['ignored_layers'])
                    ? 'Watermarked HTML preview. Render it to show the human, then call create-snap with these exact variants for the final image.'
                    : 'Watermarked HTML preview. WARNING: ignored_layers did not match any layer on this template and had no effect — check the keys via get-template-details before creating.',
            ], JSON_PRETTY_PRINT)),
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'template_id' => $schema->string()
                ->description('UUID of the template to preview.')
                ->required(),
            'variants' => $this->variantsSchema($schema),
        ];
    }
}
