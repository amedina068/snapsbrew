<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per create request that carried an idempotency key.
 *
 * The row is a 24-hour claim on a key, not a usage record. `api_logs` holds
 * the durable usage history. A prune command deletes rows past the window.
 */
class IdempotencyKey extends Model
{
    /** A request holds the claim and has not linked a snap yet. */
    public const STATUS_IN_PROGRESS = 'in_progress';

    /**
     * The work exists and one of `snap_id` or `snap_import_id` points at it. A
     * replay returns that snap or that batch.
     */
    public const STATUS_COMPLETED = 'completed';

    /** The render threw. Another retry can reclaim this key. */
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id',
        'key',
        'status',
        'snap_id',
        'snap_import_id',
    ];

    public function snap(): BelongsTo
    {
        return $this->belongsTo(Snap::class);
    }

    public function snapImport(): BelongsTo
    {
        return $this->belongsTo(SnapImport::class);
    }
}
