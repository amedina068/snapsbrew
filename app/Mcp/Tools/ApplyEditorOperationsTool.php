<?php

namespace App\Mcp\Tools;

use App\Editor\EditorDocumentOperationApplier;
use App\Exceptions\EditorDocumentConflict;
use App\Exceptions\EditorOperationRejected;
use App\Mcp\ToolError;
use App\Mcp\Tools\Concerns\InteractsWithSnaps;
use App\Services\EditorDocumentRateLimiter;
use App\Services\TemplateDocumentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('apply-editor-operations')]
#[Title('Apply validated editor layer operations')]
#[Description('Atomically applies a closed list of validated v2 layer property/type operations when revision matches. Reread the document after editor_document_conflict; never retry it by overwriting the returned document.')]
#[IsDestructive]
#[IsOpenWorld(false)]
#[IsReadOnly(false)]
class ApplyEditorOperationsTool extends Tool
{
    use InteractsWithSnaps;

    public function handle(Request $request): Response
    {
        if (! app(EditorDocumentRateLimiter::class)->attempt($request->user())) {
            return ToolError::editorOperation('editor_document_rate_limited');
        }
        try {
            $validated = $request->validate([
                'template_id' => ['required', 'string'], 'revision' => ['required', 'integer', 'min:0'], 'operations' => ['required', 'array', 'max:100'],
            ]);
        } catch (ValidationException) {
            return ToolError::editorOperation('editor_operation_invalid_request');
        }
        $template = $this->companyTemplate($request, $validated['template_id']);
        if (! $template) {
            return ToolError::templateNotFound($validated['template_id']);
        }
        try {
            Gate::forUser($request->user())->authorize('update', $template);
        } catch (AuthorizationException) {
            return ToolError::templateChangeNotAllowed('edit');
        }

        $current = app(TemplateDocumentService::class)->get($template);
        if (! is_array($current['document'])) {
            return ToolError::editorOperation('editor_operation_document_missing');
        }
        try {
            $document = app(EditorDocumentOperationApplier::class)->apply($current['document'], $validated['operations']);
            $result = app(TemplateDocumentService::class)->replace($template, $validated['revision'], $document);
        } catch (EditorOperationRejected $rejected) {
            return ToolError::editorOperation($rejected->errorCode);
        } catch (ValidationException) {
            return ToolError::editorOperation('editor_operation_document_invalid');
        } catch (EditorDocumentConflict $conflict) {
            return ToolError::editorOperation('editor_document_conflict', ['current_revision' => $conflict->revision, 'current_document' => $conflict->document]);
        }

        return Response::text(json_encode(['document' => $result['document'], 'revision' => $result['revision']], JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'template_id' => $schema->string()->description('Template UUID.')->required(),
            'revision' => $schema->integer()->description('Revision returned by get-editor-document.')->required(),
            'operations' => $schema->array()->description('One or more {operation, layer_key, property?, value?, type?} operations. operation is set_layer_property or set_layer_type.')->required(),
        ];
    }
}
