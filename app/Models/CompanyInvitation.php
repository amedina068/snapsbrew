<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyInvitation extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')
            ->where(function (Builder $inner) {
                $inner->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now());
            });
    }

    public function markAccepted(User $user): void
    {
        $this->forceFill([
            'accepted_at' => now(),
            'accepted_by' => $user->id,
        ])->save();
    }
}
