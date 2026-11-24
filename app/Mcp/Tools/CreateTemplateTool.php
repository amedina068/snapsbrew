<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolError;
use App\Models\Template;
use App\Rules\SafeLayers;
use App\Rules\ValidLayers;
use App\Support\CanvasRules;
use App\Support\TemplateSizes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('create-template')]
#[Title('Create a template')]
#[Description('Creates a new template in your account from a title, a canvas size, and a layers definition. Give the canvas EITHER a `size` preset (for example "instagram_story" — call list-template-sizes for the catalog) OR an explicit `width` and `height`. Templates are the reusable designs that snaps are rendered from. Returns the new template id (UUID). A preview image is generated automatically.')]
#[IsDestructive(false)]
#[IsOpenWorld]
#[IsReadOnly(false)]
class CreateTemplateTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'layers' => ['bail', 'nullable', 'array', new ValidLayers, new SafeLayers],
        ] + CanvasRules::rules(), CanvasRules::messages());

        $canvas = CanvasRules::resolve($validated);

        $user = $request->user();

        try {
            Gate::forUser($user)->authorize('create', Template::class);
        } catch (AuthorizationException $e) {
            return ToolError::templatesNotAllowed();
        }

        $template = Template::create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'title' => $validated['title'],
            'width' => $canvas['width'],
            'height' => $canvas['height'],
            'size' => $canvas['size'],
            'layers' => json_encode($validated['layers'] ?? []),
        ]);

        $template->generatePreviewImage();

        return Response::text(json_encode([
            'id' => $template->uuid,
            'title' => $template->title,
            'size' => $template->size,
            'width' => (int) $template->width,
            'height' => (int) $template->height,
            'safe_margin_px' => TemplateSizes::safeMargin((int) $template->width, (int) $template->height),
            'message' => 'Template created.',
        ], JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Template title.')->required(),
            'size' => $schema->string()->description('Canvas size preset, for example "instagram_story", "youtube_thumbnail" or "open_graph". Sets width and height for you, so you can leave them out. Call list-template-sizes for every preset with its dimensions.'),
            'width' => $schema->integer()->description('Canvas width in px (1–'.Template::MAX_DIMENSION.'). Required only when you pass no `size`.'),
            'height' => $schema->integer()->description('Canvas height in px (1–'.Template::MAX_DIMENSION.'). Required only when you pass no `size`.'),
            'layers' => $schema->array()->description('Layer definitions (array of layer objects). Pass [] for an empty canvas. Each layer is { "key": "<unique string>", "type"?: "<string>", "properties": [ { "name", "value" } ] }. Call describe-layers first for the full shape, property vocabulary, and an example.'),
        ];
    }
}
