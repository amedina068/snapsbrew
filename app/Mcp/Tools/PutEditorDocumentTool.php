<?php

namespace App\Mcp\Tools;

use App\Exceptions\EditorDocumentConflict;
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

#[Name('put-editor-document')]
#[Title('Replace a template editor document (any account in your company)')]
#[Description('Atomically replaces a v2 editor document when revision matches. On editor_document_conflict, retain your draft and choose to reload, copy it, or cancel; never overwrite the returned current document automatically.')]
#[IsDestructive]
#[IsOpenWorld(false)]
#[IsReadOnly(false)]
class PutEditorDocumentTool extends Tool
{
    use InteractsWithSnaps;

    public function handle(Request $request): Response
    {
        if (! app(EditorDocumentRateLimiter::class)->attempt($request->user())) {
            return ToolError::editorOperation('editor_document_rate_limited');
        }

        $validated = $request->validate([
            'template_id' => ['required', 'string'],
            'revision' => ['required', 'integer', 'min:0'],
            'document' => ['required', 'array'],
        ]);
        $template = $this->companyTemplate($request, $validated['template_id']);

        if (! $template) {
            return ToolError::templateNotFound($validated['template_id']);
        }

        try {
            Gate::forUser($request->user())->authorize('update', $template);
        } catch (AuthorizationException) {
            return ToolError::templateChangeNotAllowed('edit');
        }

        try {
            $result = app(TemplateDocumentService::class)->replace($template, $validated['revision'], $validated['document']);
        } catch (EditorDocumentConflict $conflict) {
            return ToolError::editorOperation('editor_document_conflict', ['current_revision' => $conflict->revision, 'current_document' => $conflict->document]);
        }

        return Response::text(json_encode([
            'document' => $result['document'],
            'revision' => $result['revision'],
        ], JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'template_id' => $schema->string()->description('Template UUID.')->required(),
            'revision' => $schema->integer()->description('Revision returned by get-editor-document.')->required(),
            'document' => $schema->object([])->description('Full replacement v2 document.')->required(),
        ];
    }
}
