<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolError;
use App\Mcp\Tools\Concerns\InteractsWithSnaps;
use App\Models\Template;
use App\Rules\SafeLayers;
use App\Rules\ValidLayers;
use App\Support\TemplateSizes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('edit-template')]
#[Title('Edit a template (any account in your company)')]
#[Description('Updates an existing template in your company (title, canvas size, and/or layers) by writing its DRAFT. Resize it with a `size` preset (for example "instagram_story") or with an explicit `width` and `height`. New snaps and previews keep rendering the last published version until you call publish-template — so an edit alone does not change what create-snap produces. Only do this when explicitly asked. Returns the updated fields. Any account in your company that can change templates can edit it.')]
#[IsDestructive]
#[IsOpenWorld]
#[IsReadOnly(false)]
class EditTemplateTool extends Tool
{
    use InteractsWithSnaps;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'template_id' => ['required', 'string'],
            'title' => ['sometimes', 'string', 'max:255'],
            'size' => ['sometimes', 'string', Rule::in(TemplateSizes::values(withCustom: false))],
            'width' => ['sometimes', 'numeric', 'min:1', 'max:'.Template::MAX_DIMENSION],
            'height' => ['sometimes', 'numeric', 'min:1', 'max:'.Template::MAX_DIMENSION],
            'layers' => ['bail', 'sometimes', 'array', new ValidLayers, new SafeLayers],
        ], [
            'size.in' => 'Unknown size preset. Call list-template-sizes for the presets you can use, or pass width and height instead.',
        ]);

        $preset = TemplateSizes::find($validated['size'] ?? null);

        $template = $this->companyTemplate($request, $validated['template_id']);

        if (! $template) {
            return ToolError::templateNotFound($validated['template_id']);
        }

        try {
            Gate::forUser($request->user())->authorize('update', $template);
        } catch (AuthorizationException $e) {
            return ToolError::templateChangeNotAllowed('edit');
        }

        if ($template->editor_schema_version !== null) {
            return ToolError::legacyEditRejectedForV2();
        }

        $template->fill(array_filter([
            'title' => $validated['title'] ?? null,
            // A preset wins over width/height — it is the caller's stated intent
            // and it also names the preset the template is stored as.
            'width' => $preset['width'] ?? $validated['width'] ?? null,
            'height' => $preset['height'] ?? $validated['height'] ?? null,
            'size' => $preset['value'] ?? null,
            'layers' => array_key_exists('layers', $validated) ? json_encode($validated['layers']) : null,
        ], fn ($value) => ! is_null($value)))->saveWithPreview();

        return Response::text(json_encode([
            'id' => $template->uuid,
            'title' => $template->title,
            'size' => $template->size,
            'width' => (int) $template->width,
            'height' => (int) $template->height,
            'safe_margin_px' => TemplateSizes::safeMargin((int) $template->width, (int) $template->height),
            'message' => 'Template updated.',
        ], JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'template_id' => $schema->string()->description('UUID of the template to edit.')->required(),
            'title' => $schema->string()->description('New title.'),
            'size' => $schema->string()->description('New canvas size preset, for example "instagram_story". Sets width and height for you and overrides them if you pass both. Call list-template-sizes for every preset with its dimensions.'),
            'width' => $schema->integer()->description('New canvas width in px (1–'.Template::MAX_DIMENSION.'). Ignored when you pass a `size`.'),
            'height' => $schema->integer()->description('New canvas height in px (1–'.Template::MAX_DIMENSION.'). Ignored when you pass a `size`.'),
            'layers' => $schema->array()->description('Replacement layer definitions (array of layer objects). REPLACES all existing layers. Each layer is { "key": "<unique string>", "type"?: "<string>", "properties": [ { "name", "value" } ] }. Call describe-layers first for the full shape, property vocabulary, and an example.'),
        ];
    }
}
