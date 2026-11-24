<?php

namespace App\Services;

use App\Editor\TemplateDocumentAssetNormalizer;
use App\Jobs\GenerateTemplateVersionPreview;
use App\Models\EditorAsset;
use App\Models\Template;
use App\Models\TemplateVersion;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Draft / publish / revert for templates (F8).
 *
 * - Draft is the live `templates.layers`/`groups`.
 * - Publish snapshots the draft into a new immutable, published TemplateVersion
 *   and points `templates.published_version_id` at it.
 * - Revert is non-destructive: it first snapshots the CURRENT draft into history,
 *   then copies a prior version's layers/groups back into the draft.
 *
 * Version `layers`/`groups` are copied as the raw JSON-string shape so the render
 * seam (`Template::renderableLayers`) can return them verbatim.
 */
class TemplateVersioning
{
    public function __construct(private TemplateDocumentAssetNormalizer $assetNormalizer) {}

    /**
     * Publish the draft, or answer a repeat with the version which is live.
     *
     * A retried publish must not write a second version and must not render a
     * second preview. The state is read again here, because the caller can hold
     * a model which an earlier publish already changed. The read is outside the
     * transaction, so the remote asset fetch below is skipped for a repeat.
     */
    public function publish(Template $template): TemplateVersion
    {
        $current = $template->fresh(['publishedVersion']) ?? $template;
        $published = $current->publishedVersion;

        if ($published && ! $current->hasUnpublishedEdits()) {
            return $published;
        }

        // Fetching/canonicalizing a remote image may take seconds. It MUST run
        // before acquiring the per-template version lock; the transaction below
        // only verifies the prepared document is still current and writes DB.
        $prepared = $this->prepareEditorDocument($template);

        $version = DB::transaction(function () use ($template, $prepared) {
            $locked = $this->lockTemplate($template);
            $this->applyPreparedDocument($locked, $template, $prepared);
            $version = $this->snapshot($locked, published: true);
            $this->attachAssets($version, $prepared['asset_ids'], $locked->company_id);

            $locked->forceFill(['published_version_id' => $version->id])->save();

            return $version;
        });

        // Render the snapshot image off the request path — Browsershot is slow
        // and would block publish. The history UI shows the image once ready.
        GenerateTemplateVersionPreview::dispatch($version);

        return $version;
    }

    public function revert(Template $template, TemplateVersion $version): TemplateVersion
    {
        $snapshot = DB::transaction(function () use ($template, $version) {
            $template = $this->lockTemplate($template);
            $version = $template->versions()->findOrFail($version->id);

            // Non-destructive: keep the current draft in history before overwriting.
            $snapshot = $this->snapshot($template, published: false);

            $template->forceFill([
                'layers' => $version->layers,
                'groups' => $version->groups,
            ])->save();

            return $snapshot;
        });

        // The just-captured draft snapshot renders its own preview off the
        // request path; the target version already has one from when it was made.
        GenerateTemplateVersionPreview::dispatch($snapshot);

        return $version;
    }

    /**
     * Version allocation serializes on the parent template row. Re-reading the
     * row also prevents a stale in-memory model from allocating a duplicate
     * sequence number after a concurrent publish.
     */
    private function lockTemplate(Template $template): Template
    {
        return Template::query()
            ->whereKey($template->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function snapshot(Template $template, bool $published): TemplateVersion
    {
        $nextNumber = (int) $template->versions()->max('version_number') + 1;

        return $template->versions()->create([
            'version_number' => $nextNumber,
            'layers' => $template->layers,
            'groups' => $template->groups,
            'width' => $template->width,
            'height' => $template->height,
            'published' => $published,
            'created_by' => Auth::id(),
            'editor_document' => $template->editor_document,
            'editor_schema_version' => $template->editor_schema_version,
            'editor_revision' => $template->editor_revision,
        ]);
    }

    /** @return array{document: array|null, asset_ids: array<int, string>} */
    private function prepareEditorDocument(Template $template): array
    {
        if (! is_array($template->editor_document)) {
            return ['document' => null, 'asset_ids' => []];
        }

        $normalized = $this->assetNormalizer->normalize((int) $template->company_id, $template->editor_document);

        return $normalized;
    }

    /** @param array{document: array|null, asset_ids: array<int, string>} $prepared */
    private function applyPreparedDocument(Template $locked, Template $original, array $prepared): void
    {
        if ($locked->editor_document !== $original->editor_document
            || $locked->editor_revision !== $original->editor_revision) {
            throw new \LogicException('editor_document_changed');
        }

        if ($prepared['document'] !== null) {
            $locked->forceFill(['editor_document' => $prepared['document']])->save();
        }
    }

    /** @param array<int, string> $assetIds */
    private function attachAssets(TemplateVersion $version, array $assetIds, ?int $companyId): void
    {
        if ($assetIds === [] || $companyId === null) {
            return;
        }

        $assets = EditorAsset::query()
            ->where('company_id', $companyId)
            ->whereIn('uuid', $assetIds)
            ->pluck('id');

        if ($assets->count() !== count($assetIds)) {
            throw new \LogicException('asset_tenant_mismatch');
        }

        $version->editorAssets()->attach($assets);
    }
}
