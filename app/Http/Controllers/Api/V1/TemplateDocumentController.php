<?php

namespace App\Http\Controllers\Api\V1;

use App\Editor\EditorDocumentOperationApplier;
use App\Exceptions\EditorDocumentConflict;
use App\Exceptions\EditorOperationRejected;
use App\Http\Controllers\Controller;
use App\Models\Template;
use App\Services\EditorPreflight;
use App\Services\TemplateDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TemplateDocumentController extends Controller
{
    public function show(string $templateUuid, TemplateDocumentService $documents): JsonResponse
    {
        $template = $this->companyTemplate($templateUuid);
        $this->authorize('view', $template);

        return response()->json($this->payload($documents->get($template)));
    }

    public function update(Request $request, string $templateUuid, TemplateDocumentService $documents): JsonResponse
    {
        $validated = $request->validate([
            'revision' => ['required', 'integer', 'min:0'],
            'document' => ['required', 'array'],
        ]);
        $template = $this->companyTemplate($templateUuid);
        $this->authorize('update', $template);

        try {
            return response()->json($this->payload($documents->replace($template, $validated['revision'], $validated['document'])));
        } catch (EditorDocumentConflict $conflict) {
            return $this->conflict($conflict);
        }
    }

    /**
     * Apply a closed list of layer operations to the stored document.
     *
     * This is the REST twin of the apply-editor-operations MCP tool. It carries
     * the same shapes: the document and the revision on success, and the
     * canonical conflict body on a stale revision.
     */
    public function apply(
        Request $request,
        string $templateUuid,
        TemplateDocumentService $documents,
        EditorDocumentOperationApplier $applier,
    ): JsonResponse {
        $validated = $request->validate([
            'revision' => ['required', 'integer', 'min:0'],
            'operations' => ['required', 'array', 'max:100'],
        ]);
        $template = $this->companyTemplate($templateUuid);
        $this->authorize('update', $template);

        $current = $documents->get($template);
        if (! is_array($current['document'])) {
            return response()->json([
                'code' => 'editor_operation_document_missing',
                'message' => EditorOperationRejected::messageFor('editor_operation_document_missing'),
            ], 422);
        }

        try {
            $document = $applier->apply($current['document'], $validated['operations']);

            return response()->json($this->payload($documents->replace($template, $validated['revision'], $document)));
        } catch (EditorOperationRejected $rejected) {
            return response()->json([
                'code' => $rejected->errorCode,
                'message' => EditorOperationRejected::messageFor($rejected->errorCode),
            ], 422);
        } catch (EditorDocumentConflict $conflict) {
            return $this->conflict($conflict);
        }
    }

    public function preflight(string $templateUuid, TemplateDocumentService $documents, EditorPreflight $preflight): JsonResponse
    {
        $template = $this->companyTemplate($templateUuid);
        $this->authorize('view', $template);
        $document = $documents->get($template);

        return response()->json([
            'issues' => $preflight->inspect($document['document']),
            'revision' => $document['revision'],
        ]);
    }

    private function companyTemplate(string $uuid): Template
    {
        return Template::query()->where('company_id', Auth::user()->company_id)->uuid($uuid)->firstOrFail();
    }

    /** The one conflict body every document write returns. */
    private function conflict(EditorDocumentConflict $conflict): JsonResponse
    {
        return response()->json([
            'code' => 'editor_document_conflict',
            'message' => EditorDocumentConflict::MESSAGE,
            'current_revision' => $conflict->revision,
            'current_document' => $conflict->document,
        ], 409);
    }

    /** @param array{document: array|null, revision: int} $result */
    private function payload(array $result): array
    {
        return ['document' => $result['document'], 'revision' => $result['revision']];
    }
}
