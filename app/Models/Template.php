<?php

namespace App\Models;

use App\Builders\SnapBuilder;
use App\Support\TemplateSizes;
use App\Traits\HasUuid;
use App\Traits\Taggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Template extends Model implements HasMedia
{
    use HasFactory, HasUuid, InteractsWithMedia, Taggable;

    public const MAX_DIMENSION = 4000;

    /**
     * The attributes that the preview render reads.
     */
    public const PREVIEW_ATTRIBUTES = ['layers', 'groups', 'width', 'height'];

    /**
     * Canvas size presets. The catalog lives in App\Support\TemplateSizes —
     * add or change a preset there, never here.
     *
     * @var array<int, array{name: string, value: string, width: int, height: int, platform: string}>
     */
    const DEFAULT_SIZES = TemplateSizes::ALL;

    protected $guarded = [];

    protected $casts = [
        'editor_document' => 'array',
        'editor_schema_version' => 'integer',
        'editor_revision' => 'integer',
    ];

    /**
     * Keeps `templates.size` in step with the canvas dimensions. The column is
     * filtered on in the template list, so a template saved with dimensions and
     * no preset must still report the preset it matches. A caller that sets
     * `size` itself wins — several presets share one shape (1080x1920 is a
     * Story, a Reel and a TikTok video) and only the caller knows which it is.
     *
     * A stored preset that still matches the new dimensions is kept. Eloquent
     * reports that an attribute changed, not that a caller supplied it. A
     * caller that repeats the stored value therefore reads as no value at all.
     * A resolve there returns the first preset of that shape and moves the
     * template off the preset it already names.
     */
    protected static function booted(): void
    {
        static::saving(function (Template $template) {
            if ($template->isDirty('size')) {
                return;
            }

            if (! $template->exists || $template->isDirty(['width', 'height'])) {
                $stored = TemplateSizes::find($template->size);

                if ($stored
                    && $stored['width'] === (int) $template->width
                    && $stored['height'] === (int) $template->height) {
                    return;
                }

                $template->size = TemplateSizes::resolveValue(
                    (int) $template->width,
                    (int) $template->height
                );
            }
        });

        // A snap points at the version that it rendered, and the database
        // refuses to delete a version that a snap uses. So the snaps go first,
        // one at a time, so that the media library also deletes their files.
        // An import stays as a record. Its mail names "your template" then.
        static::deleting(function (Template $template) {
            $template->snaps()->lazyById()->each->delete();
            $template->forms()->delete();
            $template->collections()->detach();
            $template->tags()->detach();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TemplateVersion::class);
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'published_version_id');
    }

    /**
     * The layers the RENDER path should use (F8 seam). Step 2 returns the
     * published version's layers; falls back to the live draft when the template
     * has never been published (back-compat). The render path consumes a JSON
     * string, so a version's `layers` is stored in that exact shape.
     */
    public function renderableLayers()
    {
        if ($this->published_version_id && $this->publishedVersion) {
            return $this->publishedVersion->layers;
        }

        return $this->layers;
    }

    public function renderableGroups()
    {
        if ($this->published_version_id && $this->publishedVersion) {
            return $this->publishedVersion->groups;
        }

        return $this->groups;
    }

    /**
     * True when the draft has diverged from the published version. That means an
     * edit is saved but not yet live, because renders read the published
     * version. When the template was never published, the render path falls back
     * to the draft, so there is nothing pending to publish.
     *
     * This is the one definition of "unchanged" in the application. The MCP
     * `has_unpublished_edits` field reports it and `TemplateVersioning::publish`
     * reads it to answer a repeated publish without a second version. It must
     * compare every field a version snapshot copies and a consumer reads:
     * `SnapSourceVersioner` renders from the version `layers`, `groups`, `width`
     * and `height`, and `ProcessSnap` inspects its `editor_document`. The
     * `editor_document` matters on its own, because `EditorDocumentProjector`
     * copies `layers` and `groups` only, and a change outside them leaves the
     * projection identical. `templates.width` is a string column and
     * `template_versions.width` is an integer column, so both sides need a cast.
     *
     * `editor_revision` is snapshot into every version row and is deliberately
     * NOT compared here. It is a write counter that
     * `TemplateDocumentService::replace` increments on every write, including a
     * write of identical content. Comparing it would report an idempotent PUT
     * of the editor document as an edit, for ever. Do not add it to close the
     * gap between what a snapshot copies and what this method reads.
     */
    public function hasUnpublishedEdits(): bool
    {
        if (! $this->published_version_id || ! $this->publishedVersion) {
            return false;
        }

        $published = $this->publishedVersion;

        return $this->layers !== $published->layers
            || $this->groups !== $published->groups
            || (int) $this->width !== (int) $published->width
            || (int) $this->height !== (int) $published->height
            || $this->editor_document !== $published->editor_document;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function snaps(): HasMany
    {
        return $this->hasMany(Snap::class);
    }

    public function imports(): HasMany
    {
        return $this->hasMany(SnapImport::class);
    }

    public function forms(): MorphMany
    {
        return $this->morphMany(Form::class, 'formable');
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(TemplateCollection::class);
    }

    public function getReadableSizeAttribute()
    {
        $defaultSize = TemplateSizes::find($this->size);

        return $defaultSize['name'] ?? ("Custom ({$this->width}x{$this->height})");
    }

    public function getIsOwnerAttribute()
    {
        return Auth::check() ? $this->user_id == Auth::id() : false;
    }

    public function getPreviewImageAttribute()
    {
        return $this->getFirstMediaUrl('preview', 'description');
    }

    public function scopePublic($query): void
    {
        $query->whereNull(['user_id']);
    }

    public function scopeUser($query, $userId = null): void
    {
        $query->where('user_id', $userId ?? Auth::id());
    }

    /**
     * Match one template by its UUID or by its numeric primary key.
     *
     * The public API returns the UUID and never returns the numeric id, so a
     * REST client holds only the UUID. The web application URL holds the
     * numeric id. Both identifiers reach this scope, so both must work.
     *
     * The branch condition is the shape of the value. `templates.id` is an
     * integer column, so only a string of digits can name a row by its
     * primary key. Every other value is a UUID.
     *
     * One branch picks one column, and the query compares that one column.
     * Do not write one query that compares the value against both columns.
     * MySQL casts a string to a number when it compares it to an integer
     * column, so a UUID that starts with a digit matches a row by accident.
     * Measured on MySQL 8:
     * `SELECT * FROM cast_probe WHERE id = '4f3a1b2c-0000-4000-8000-000000000000'`
     * returned the row with `id` 4 and warned
     * `Truncated incorrect DOUBLE value`.
     *
     * `ctype_digit` is the condition, not `is_numeric`. `is_numeric` accepts
     * `4e3`, `4.0`, `+4` and ` 4`, and MySQL casts each of those to a number
     * too. A value that is neither a UUID nor a string of digits reads the
     * `uuid` string column, finds no row, and the caller reads 404.
     */
    public function scopeIdentifier($query, $identifier): void
    {
        $query->where(
            ctype_digit((string) $identifier) ? 'id' : 'uuid',
            $identifier
        );
    }

    /**
     * Save the template, and render a new preview only when the image changes.
     *
     * The render reads the attributes in `PREVIEW_ATTRIBUTES` and nothing
     * else of the template. A `size` preset reaches it only through `width`
     * and `height`. A write that changes none of them keeps the stored
     * preview and its URL, unless the template has no preview yet.
     *
     * The render runs before the save. It reads the unsaved attributes. If it
     * throws, the row stays as it was, and a retry of the same write renders
     * again. A save first and a render second left the new row with the old
     * preview, and the retry then found no change to render.
     */
    public function saveWithPreview(): void
    {
        if ($this->hasMedia('preview') && ! $this->isDirty(self::PREVIEW_ATTRIBUTES)) {
            $this->save();

            return;
        }

        $image = $this->renderPreview();

        $this->save();
        $this->storePreview($image);
    }

    public function generatePreviewImage()
    {
        $this->storePreview($this->renderPreview());
    }

    private function renderPreview(): string
    {
        return $this->renderToBase64($this->layers, $this->groups, $this->width, $this->height);
    }

    private function storePreview(string $image): void
    {
        $this->addMediaFromBase64($image)
            ->usingFileName(Str::uuid().'.png')
            ->withCustomProperties(['mime-type' => 'image/png'])
            ->toMediaCollection('preview');
    }

    /**
     * Render an arbitrary layers/groups snapshot to a base64 PNG via a
     * throwaway snap. Shared by the live preview image and the version-history
     * preview. NEVER bills — the snap is created and deleted in place.
     */
    public function renderToBase64($layers, $groups, $width, $height): string
    {
        $snapBuilder = (new SnapBuilder)
            ->setLayers($layers)
            ->setGroups($groups)
            ->forCompany($this->company_id)
            ->setWidth($width)
            ->setHeight($height);

        $snap = $this->snaps()->create([
            'user_id' => Auth::id(),
            'company_id' => $this->company_id,
        ]);

        $snap->addContent($snapBuilder->buildContent());
        $snap->addHead($snapBuilder->buildHead());

        try {
            return $snap->renderImageBase64((int) $width, (int) $height);
        } finally {
            $snap->clearMediaCollection('html_content');
            $snap->clearMediaCollection('html_head');
            $snap->delete();
        }
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('preview')
            ->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(500)
            ->height(230)
            ->nonOptimized()
            ->performOnCollections('preview');

        $this->addMediaConversion('description')
            ->width(500)
            ->height(700)
            ->nonOptimized()
            ->performOnCollections('preview');
    }
}
