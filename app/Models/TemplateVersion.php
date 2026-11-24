<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * An immutable snapshot of a template's layers/groups at publish time.
 *
 * `layers`/`groups` are held as the SAME JSON-string shape as the live
 * `templates.layers`/`groups` columns (no array cast) so the render path can
 * consume a version's layers byte-for-byte identically to a draft's — the
 * `renderableLayers()` seam returns one or the other with no shape change.
 *
 * Each version stores its own rendered preview image (singleFile media), built
 * once on first view and reused after — history previews load a stored image
 * instead of re-rendering on every click.
 */
class TemplateVersion extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $guarded = [];

    protected $casts = [
        'published' => 'boolean',
        'version_number' => 'integer',
        'editor_document' => 'array',
        'editor_schema_version' => 'integer',
        'editor_revision' => 'integer',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editorAssets(): BelongsToMany
    {
        return $this->belongsToMany(EditorAsset::class, 'template_version_assets')
            ->withTimestamps();
    }

    public function getPreviewImageAttribute(): string
    {
        return $this->getFirstMediaUrl('preview');
    }

    /**
     * Render this snapshot to a stored preview image. Renders only — never
     * bills. Renders at the version's own captured size, falling back to the
     * template's current size for legacy rows that never captured one.
     */
    public function generatePreview(): void
    {
        // A version with no template is an inline design rendered by one snap.
        // It has no history screen, so there is no preview to build.
        if (! $this->template) {
            return;
        }

        $base64 = $this->template->renderToBase64(
            $this->layers,
            $this->groups,
            $this->width ?? $this->template->width,
            $this->height ?? $this->template->height,
        );

        $this->addMediaFromBase64($base64)
            ->usingFileName(Str::uuid().'.png')
            ->withCustomProperties(['mime-type' => 'image/png'])
            ->toMediaCollection('preview');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('preview')
            ->singleFile();
    }
}
