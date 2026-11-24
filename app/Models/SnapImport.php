<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SnapImport extends Model
{
    use HasFactory;
    use HasUuid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    protected $guarded = [];

    protected $casts = [
        'completed_at' => 'datetime',
        'canceled_at' => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function snaps(): HasMany
    {
        return $this->hasMany(Snap::class);
    }

    public function progressSnapshot(): array
    {
        $this->loadCount([
            'snaps as completed_snaps_count' => fn ($query) => $query->where('status', Snap::STATUS_COMPLETED),
            'snaps as pending_snaps_count' => fn ($query) => $query->whereIn('status', [Snap::STATUS_PENDING, Snap::STATUS_PROCESSING]),
            'snaps as failed_snaps_count' => fn ($query) => $query->where('status', Snap::STATUS_FAILED),
            'snaps as canceled_snaps_count' => fn ($query) => $query->where('status', Snap::STATUS_CANCELED),
        ]);

        $processed = $this->completed_snaps_count + $this->failed_snaps_count + $this->canceled_snaps_count;

        if ($this->processed_rows !== $processed) {
            $this->forceFill(['processed_rows' => $processed])->save();
        }

        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'status' => $this->status,
            'total_rows' => $this->total_rows,
            'processed_rows' => $processed,
            'completed' => $this->completed_snaps_count,
            'pending' => $this->pending_snaps_count,
            'failed' => $this->failed_snaps_count,
            'canceled' => $this->canceled_snaps_count,
        ];
    }

    public static function cancellationCacheKey(int $importId): string
    {
        return 'cancel_import_at:'.$importId;
    }
}
