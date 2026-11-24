<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolError;
use App\Mcp\Tools\Concerns\InteractsWithSnaps;
use App\Services\EditorDocumentRateLimiter;
use App\Services\EditorPreflight;
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

#[Name('preflight-editor-document')]
#[Title('Inspect a draft editor document before publishing')]
#[Description('Checks known render invariants in the current v2 draft without mutating it. Returns structured issues; an empty issues list is not a visual-quality guarantee.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class PreflightEditorDocumentTool extends Tool
{
    use InteractsWithSnaps;

    public function handle(Request $request): Response
    {
        if (! app(EditorDocumentRateLimiter::class)->attempt($request->user())) {
            return ToolError::editorOperation('editor_document_rate_limited');
        }
        try {
            $validated = $request->validate(['template_id' => ['required', 'string']]);
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
            return ToolError::templateChangeNotAllowed('view');
        }

        $current = app(TemplateDocumentService::class)->get($template);
        if (! is_array($current['document'])) {
            return ToolError::editorOperation('editor_operation_document_missing');
        }

        return Response::text(json_encode([
            'revision' => $current['revision'],
            'issues' => app(EditorPreflight::class)->inspect($current['document']),
        ], JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['template_id' => $schema->string()->description('Template UUID.')->required()];
    }
}
