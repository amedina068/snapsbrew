<?php

namespace App\Models;

use App\Contracts\SnapRenderer;
use App\Support\SnapDocument;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;

class Snap extends Model implements HasMedia
{
    use HasFactory, HasUuid, InteractsWithMedia;

    const TYPES = [
        'image',
        'pdf',
    ];

    /** Image output formats. `transparent` is only valid for png and webp. */
    const FORMATS = ['png', 'jpeg', 'webp'];

    const FORMAT_META = [
        'png' => ['ext' => 'png', 'mime' => 'image/png'],
        'jpeg' => ['ext' => 'jpg', 'mime' => 'image/jpeg'],
        'webp' => ['ext' => 'webp', 'mime' => 'image/webp'],
    ];

    public const TYPE_IMAGE = 'image';

    public const TYPE_PDF = 'pdf';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    /**
     * The `last_error` of a failed render. The API, MCP and the import page
     * show `last_error` to the customer, so it holds this code and never the
     * message of the renderer. The log keeps that message.
     */
    public const ERROR_RENDER_FAILED = 'render_failed';

    protected $guarded = [];

    protected $casts = [
        'is_async' => 'boolean',
        'transparent' => 'boolean',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * The immutable template-version source selected at dispatch time. Legacy
     * snaps remain nullable until S01c creates their explicit source snapshot.
     */
    public function sourceTemplateVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'source_template_version_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(SnapImport::class, 'snap_import_id');
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** Snaps that hold a rendered file in the `image` collection. */
    public function scopeWithImage($query): void
    {
        $query->whereHas('media', fn ($media) => $media->where('collection_name', 'image'));
    }

    /** Snaps with no rendered file in the `image` collection. */
    public function scopeWithoutImage($query): void
    {
        $query->whereDoesntHave('media', fn ($media) => $media->where('collection_name', 'image'));
    }

    public function isComplete(): bool
    {
        return $this->status == self::STATUS_COMPLETED;
    }

    private function takeSnapshot($html, $width, $height, string $format = 'png', bool $transparent = false)
    {
        return app(SnapRenderer::class)->screenshotBase64($html, (int) $width, (int) $height, $format, $transparent);
    }

    private function takePdfSnapshot($html, $width, $height, $pages = null)
    {
        return app(SnapRenderer::class)->pdfBase64($html, (int) $width, (int) $height, $pages);
    }

    public function renderDocument(): string
    {
        return SnapDocument::wrap($this->head, $this->content);
    }

    /**
     * Render the snap's HTML page to a base64 PNG WITHOUT persisting any media.
     *
     * Used by the preview path: the transient snap is screenshotted and thrown
     * away, so nothing should be written to the `image` collection.
     */
    public function renderImageBase64(?int $width = null, ?int $height = null): string
    {
        [$sourceWidth, $sourceHeight] = $this->renderDimensions();

        return $this->takeSnapshot(
            $this->renderDocument(),
            $width ?? $sourceWidth,
            $height ?? $sourceHeight
        );
    }

    public function remove()
    {
        dispatch(function () {
            $this->delete();
        });
    }

    public function getImage()
    {
        [$width, $height] = $this->renderDimensions();
        $format = $this->imageFormat();
        $meta = self::FORMAT_META[$format];

        $base64Data = $this->takeSnapshot(
            $this->renderDocument(),
            $width,
            $height,
            $format,
            (bool) $this->transparent,
        );

        $this
            ->addMediaFromBase64($base64Data)
            ->withCustomProperties(['mime-type' => $meta['mime']])
            ->usingFileName((string) Str::uuid(16).'.'.$meta['ext'])
            ->toMediaCollection('image');

        $this->unsetRelation('media');

        return $this->imageUrl;
    }

    /** The validated output format, defaulting to png for legacy/blank rows. */
    private function imageFormat(): string
    {
        return in_array($this->format, self::FORMATS, true) ? $this->format : 'png';
    }

    /** @return array{int, int} */
    private function renderDimensions(): array
    {
        if ($this->sourceTemplateVersion) {
            return [(int) $this->sourceTemplateVersion->width, (int) $this->sourceTemplateVersion->height];
        }

        // Preview snaps are transient and predate immutable sources. ProcessSnap
        // rejects this path; retaining it keeps non-persistent previews working.
        $template = $this->template;

        if (! $template) {
            throw new \LogicException('legacy_source_missing');
        }

        return [(int) $template->width, (int) $template->height];
    }

    public function getPdf($pages = null)
    {
        [$width, $height] = $this->renderDimensions();
        $base64Data = $this->takePdfSnapshot($this->renderDocument(), $width, $height, $pages);

        $this
            ->addMediaFromBase64($base64Data)
            ->usingFileName('certificate-'.date('ymdhis').'.pdf')
            ->toMediaCollection('image');

        $this->unsetRelation('media');

        return $this->pdfUrl;
    }

    public function getImageDataAttribute()
    {
        return file_get_contents($this->imageUrl);
    }

    public function getImagePathAttribute()
    {
        return $this->getFirstMediaPath('image');
    }

    public function getImageUrlAttribute()
    {
        return $this->getFirstMediaUrl('image');
    }

    public function getPdfUrlAttribute()
    {
        return $this->getFirstMediaUrl('image');
    }

    public function getContentAttribute(): string
    {
        return $this->readMediaContents('html_content');
    }

    public function getHeadAttribute(): string
    {
        return $this->readMediaContents('html_head');
    }

    /**
     * Read a single-file media collection through its own disk. Avoids an
     * HTTPS round-trip to the app's own URL (which fails behind self-signed
     * local certificates) while still working when media lives on S3 — there
     * the local path is just a bucket-relative key, not a readable file.
     */
    private function readMediaContents(string $collection): string
    {
        $media = $this->getFirstMedia($collection);

        if (! $media) {
            return '';
        }

        $relativePath = PathGeneratorFactory::create($media)->getPath($media).$media->file_name;

        $disk = Storage::disk($media->disk);

        // Guard the read: a missing file throws FileNotFoundException on
        // Laravel 8 (Flysystem 1) and is absent-by-design when media lives on
        // S3 (the local path is a bucket key, not a readable file). exists()
        // is stable across Flysystem 1 and 3, so we avoid coupling to either
        // version's thrown exception type.
        if (! $disk->exists($relativePath)) {
            return '';
        }

        return (string) $disk->get($relativePath);
    }

    public function addContent($content)
    {
        $this
            ->addMediaFromString($content)
            ->toMediaCollection('html_content');
    }

    public function addHead($head)
    {
        $this
            ->addMediaFromString($head)
            ->toMediaCollection('html_head');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')
            ->singleFile();

        $this->addMediaCollection('html_content')
            ->singleFile();

        $this->addMediaCollection('html_head')
            ->singleFile();
    }
}
