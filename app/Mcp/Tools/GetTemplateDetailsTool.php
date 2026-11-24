<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolError;
use App\Mcp\Tools\Concerns\InteractsWithSnaps;
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

#[Name('get-template-details')]
#[Title('Get template details')]
#[Description('Returns a single template with its layer keys and the modifications each layer accepts. Call this before building variants for preview-snap or create-snap — the layer keys it returns are exactly what you pass as "layer" in variants.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class GetTemplateDetailsTool extends Tool
{
    use InteractsWithSnaps;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'template_id' => ['required', 'string'],
        ]);

        $template = $this->companyTemplate($request, $validated['template_id']);

        if (! $template) {
            return ToolError::templateNotFound($validated['template_id']);
        }

        return Response::text(json_encode([
            'id' => $template->uuid,
            'title' => $template->title,
            'width' => (int) $template->width,
            'height' => (int) $template->height,
            'size' => $template->size,
            'published_version' => $template->publishedVersion?->version_number,
            'has_unpublished_edits' => $template->hasUnpublishedEdits(),
            'layers' => $this->parsedLayers($template),
        ], JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'template_id' => $schema->string()
                ->description('UUID of the template (from list-templates).')
                ->required(),
        ];
    }
}
