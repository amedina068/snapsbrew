<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolError;
use App\Mcp\Tools\Concerns\InteractsWithSnaps;
use App\Services\EditorDocumentRateLimiter;
use App\Services\TemplateDocumentService;
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

#[Name('get-editor-document')]
#[Title('Get a template editor document')]
#[Description('Returns the v2 editor document and its revision. Pass that revision unchanged to put-editor-document. The document is scoped to your company.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class GetEditorDocumentTool extends Tool
{
    use InteractsWithSnaps;

    public function handle(Request $request): Response
    {
        if (! app(EditorDocumentRateLimiter::class)->attempt($request->user())) {
            return ToolError::editorOperation('editor_document_rate_limited');
        }

        $validated = $request->validate(['template_id' => ['required', 'string']]);
        $template = $this->companyTemplate($request, $validated['template_id']);

        if (! $template) {
            return ToolError::templateNotFound($validated['template_id']);
        }

        try {
            Gate::forUser($request->user())->authorize('view', $template);
        } catch (AuthorizationException) {
            return ToolError::templateChangeNotAllowed('view');
        }

        $result = app(TemplateDocumentService::class)->get($template);

        return Response::text(json_encode([
            'document' => $result['document'],
            'revision' => $result['revision'],
        ], JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['template_id' => $schema->string()->description('Template UUID.')->required()];
    }
}
