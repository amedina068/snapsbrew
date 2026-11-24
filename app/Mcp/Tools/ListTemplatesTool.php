<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithSnaps;
use App\Models\Template;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-templates')]
#[Title('List templates')]
#[Description('Lists the templates in your account. Each result includes the template id (UUID), title, dimensions, and its modifiable layers. Use the id with get-template-details, preview-snap, or create-snap.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class ListTemplatesTool extends Tool
{
    use InteractsWithSnaps;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $templates = Template::query()
            ->where('company_id', $request->user()->company_id)
            ->orderByDesc('created_at')
            ->limit($validated['limit'] ?? 25)
            ->get();

        $data = $templates->map(fn (Template $template) => [
            'id' => $template->uuid,
            'title' => $template->title,
            'width' => (int) $template->width,
            'height' => (int) $template->height,
            'size' => $template->size,
            'layers' => $this->parsedLayers($template),
        ])->all();

        return Response::text(json_encode([
            'count' => count($data),
            'templates' => $data,
        ], JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Maximum number of templates to return (1–100, default 25).'),
        ];
    }
}
