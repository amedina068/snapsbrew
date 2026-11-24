<?php

namespace App\Services;

use App\Models\Snap;
use App\Models\Template;
use App\Models\TemplateVersion;
use Illuminate\Support\Facades\DB;

/** Captures the exact template source that a snap will render. */
class SnapSourceVersioner
{
    /** @param callable(Template, TemplateVersion): Snap $create */
    public function createSnap(Template $template, callable $create): Snap
    {
        return DB::transaction(function () use ($template, $create) {
            $locked = $this->lock($template);

            return $create($locked, $this->sourceForLockedTemplate($locked));
        });
    }

    /**
     * Creates a snap from a caller-selected immutable version. The source is
     * re-read under the template lock so a stale preflight result cannot fall
     * through to the template's current published version.
     *
     * @param  callable(Template, TemplateVersion): Snap  $create
     */
    public function createSnapFromVersion(
        Template $template,
        TemplateVersion $source,
        int $companyId,
        callable $create,
    ): Snap {
        return DB::transaction(function () use ($template, $source, $companyId, $create) {
            $locked = Template::query()
                ->whereKey($template->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->first() ?? throw new SourceVersionUnavailableException("Template {$template->id} is not available to company {$companyId}.");

            $exactSource = $locked->versions()
                ->whereKey($source->id)
                ->lockForUpdate()
                ->first() ?? throw new SourceVersionUnavailableException("Template version {$source->id} is not available on template {$template->id}.");

            return $create($locked, $exactSource);
        });
    }

    /** Call only while the caller owns a transaction which creates snaps. */
    public function lock(Template $template): Template
    {
        return Template::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Chooses the published immutable version when available; an unpublished
     * template receives a snapshot instead of a worker-time mutable fallback.
     */
    public function sourceForLockedTemplate(Template $template): TemplateVersion
    {
        if ($template->published_version_id) {
            return $template->versions()->whereKey($template->published_version_id)->firstOrFail();
        }

        return $template->versions()->create([
            'version_number' => (int) $template->versions()->max('version_number') + 1,
            'layers' => $template->layers,
            'groups' => $template->groups,
            'width' => $template->width,
            'height' => $template->height,
            'published' => false,
            'created_by' => $template->user_id,
            'editor_document' => $template->editor_document,
            'editor_schema_version' => $template->editor_schema_version,
            'editor_revision' => $template->editor_revision,
        ]);
    }

    /**
     * Gives a pre-S01c queued snap one explicit source before it renders.
     *
     * New snaps always receive their source at dispatch time. This adapter is
     * intentionally only for rows created before that invariant existed; it
     * writes a snapshot under locks, then lets ProcessSnap render that snapshot
     * exclusively rather than falling through to the mutable template.
     */
    public function materializeLegacySource(Snap $snap): ?Snap
    {
        return DB::transaction(function () use ($snap) {
            $lockedSnap = Snap::query()->whereKey($snap->id)->lockForUpdate()->first();

            if (! $lockedSnap) {
                return null;
            }

            if ($lockedSnap->source_template_version_id) {
                return $lockedSnap->load('sourceTemplateVersion');
            }

            $template = Template::query()
                ->whereKey($lockedSnap->template_id)
                ->lockForUpdate()
                ->first();

            if (! $template) {
                return null;
            }

            $source = $this->sourceForLockedTemplate($template);
            $lockedSnap->forceFill(['source_template_version_id' => $source->id])->save();

            return $lockedSnap->load('sourceTemplateVersion');
        });
    }
}
