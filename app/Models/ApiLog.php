<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

class ApiLog extends Model
{
    use HasFactory, HasUuid;

    protected $guarded = [];

    const SUCCESSFUL_STATUSES = [200, 201];

    const POST_METHOD = 'POST';

    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }

    public function scopePost($query): void
    {
        $query->where('method', self::POST_METHOD);
    }

    public function scopeSuccessful($query): void
    {
        $query->whereIn('status_code', self::SUCCESSFUL_STATUSES);
    }
}
