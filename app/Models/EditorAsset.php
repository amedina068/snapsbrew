<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class EditorAsset extends Model implements HasMedia
{
    use HasFactory, HasUuid, InteractsWithMedia;

    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_DIMENSION = 6000;

    public const MAX_PIXELS = 16_000_000;

    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    protected $guarded = [];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function versions(): BelongsToMany
    {
        return $this->belongsToMany(TemplateVersion::class, 'template_version_assets')
            ->withTimestamps();
    }

    public function registerMediaCollections(): void
    {
        // Assets are renderer inputs and must not become public just because a
        // caller supplied their original URL.
        $this->addMediaCollection('source')->useDisk('local')->singleFile();
    }
}
