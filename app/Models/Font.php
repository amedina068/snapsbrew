<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A company-uploaded custom font (web-only v1). The file lives in a single-file
 * Spatie media collection under a UUID storage key (never the user filename);
 * `family` is the CSS family name an @font-face block exposes at render time,
 * allowlisted to a safe character set on upload (S2/S4 — it lands in CSS).
 */
class Font extends Model implements HasMedia
{
    use HasFactory, HasUuid, InteractsWithMedia;

    /** Per-file ceiling and per-company quota. */
    public const MAX_FILE_SIZE = 5 * 1024 * 1024;

    public const MAX_FONTS_PER_COMPANY = 25;

    /** Accepted extensions and their magic-byte signatures (S: extension can lie). */
    public const ALLOWED_EXTENSIONS = ['otf', 'ttf', 'woff', 'woff2'];

    public const FAMILY_PATTERN = '/^[A-Za-z0-9 _-]{1,100}$/';

    protected $guarded = [];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('file')->singleFile();
    }

    public function getUrlAttribute(): string
    {
        return $this->getFirstMediaUrl('file');
    }
}
