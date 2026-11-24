<?php

namespace App\Models;

use App\Contracts\PageScreenshotter;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Screenshot extends Model implements HasMedia
{
    use HasFactory, HasUuid, InteractsWithMedia;

    protected $guarded = [];

    public function getImage()
    {
        $base64Data = $this->takeSnapshot();

        // Both renderers return a PNG. The media library refuses a file name
        // with no allowed extension.
        $this->addMediaFromBase64($base64Data)
            ->usingFileName(Str::uuid().'.png')
            ->toMediaCollection('image');

        return $this->imageUrl;
    }

    private function takeSnapshot()
    {
        return app(PageScreenshotter::class)
            ->screenshotUrlBase64($this->url, $this->width, $this->height, (bool) $this->full_page);
    }

    /**
     * The URL of the image, or null when the screenshot has no image. The
     * media library answers `""` for no image, and `ApiSpec` documents
     * `image_url` as `string|null`.
     */
    public function getImageUrlAttribute(): ?string
    {
        return $this->getFirstMediaUrl('image', 'image') ?: null;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')
            ->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('image')
            ->nonOptimized()
            ->performOnCollections('image');
    }
}
