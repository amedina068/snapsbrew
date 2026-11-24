<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolError;
use App\Mcp\Tools\Concerns\InteractsWithSnaps;
use App\Services\TemplateVersioning;
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

#[Name('publish-template')]
#[Title('Publish a template (any account in your company)')]
#[Description('Publishes the current draft of a template in your company as a new immutable version. New snaps and previews render the PUBLISHED version, not the draft — `edit-template` only changes the draft, so call this to make those edits take effect for create-snap and the preview tools. Returns the new published version number. Any account in your company that can change templates can publish it.')]
#[IsDestructive]
#[IsOpenWorld(false)]
#[IsReadOnly(false)]
class PublishTemplateTool extends Tool
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

        try {
            Gate::forUser($request->user())->authorize('update', $template);
        } catch (AuthorizationException $e) {
            return ToolError::templateChangeNotAllowed('publish');
        }

        $version = app(TemplateVersioning::class)->publish($template);

        return Response::text(json_encode([
            // `id` repeats `uuid` and is deprecated. Every other template
            // field on both surfaces names the field `uuid`. The REST endpoint
            // `POST /v1/templates/{templateUuid}/publish` answers both keys
            // too, so the two surfaces stay level.
            'id' => $template->uuid,
            'uuid' => $template->uuid,
            'published_version' => $version->version_number,
            'message' => 'Template published. New snaps and previews now render this version.',
        ], JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'template_id' => $schema->string()
                ->description('UUID of the template whose draft you want to publish.')
                ->required(),
        ];
    }
}
