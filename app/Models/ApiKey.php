<?php

namespace App\Models;

use App\Support\SignedUrlKeys;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

class ApiKey extends Model
{
    use HasFactory;

    const TYPE_SIGNED_URL = 'signed_url';

    protected $guarded = [];

    /**
     * The signed URL middleware caches the valid keys of each company. A new,
     * changed or deleted key clears that cache at once.
     */
    protected static function booted(): void
    {
        $forget = function (ApiKey $apiKey) {
            SignedUrlKeys::forget($apiKey->company_id);
            SignedUrlKeys::forget($apiKey->getOriginal('company_id'));
        };

        static::saved($forget);
        static::deleted($forget);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'token_id');
    }

    public function scopeSignedUrl($query): void
    {
        $query->where('type', self::TYPE_SIGNED_URL);
    }

    public function setKeyAttribute($value)
    {
        $this->attributes['key'] = encrypt($value);
    }

    public function getKeyAttribute($value)
    {
        return decrypt($value);
    }
}
