<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandToken extends Model
{
    use HasUuid;

    public const KINDS = ['color', 'typography', 'spacing', 'treatment'];

    protected $guarded = [];

    protected $casts = ['value' => 'array', 'retired_at' => 'datetime'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
