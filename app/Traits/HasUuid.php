<?php

namespace App\Traits;

use Illuminate\Support\Str;

trait HasUuid
{
    public static function bootHasUuid()
    {
        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
        });
    }

    public function scopeUuid($query, $uuid): void
    {
        $query->where('uuid', $uuid);
    }
}
