<?php

namespace App\Models;

use App\Support\CreativePackageContract;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreativePackage extends Model
{
    use HasFactory;
    use HasUuid;

    public const STATUS_BUILDING = CreativePackageContract::PACKAGE_STATUS_BUILDING;

    public const STATUS_READY = CreativePackageContract::PACKAGE_STATUS_READY;

    public const STATUS_PARTIAL = CreativePackageContract::PACKAGE_STATUS_PARTIAL;

    public const STATUS_FAILED = CreativePackageContract::PACKAGE_STATUS_FAILED;

    protected $fillable = [
        'company_id',
        'created_by',
        'operation_namespace',
        'idempotency_key',
        'request_fingerprint',
        'status',
        'total_items',
        'pending_items_count',
        'rendering_items_count',
        'ready_items_count',
        'failed_items_count',
        'manifest_version',
        'failure_code',
        'failure_details',
        'lease_expires_at',
        'started_at',
        'completed_at',
        'replay_expires_at',
    ];

    protected $casts = [
        'failure_details' => 'array',
        'total_items' => 'integer',
        'pending_items_count' => 'integer',
        'rendering_items_count' => 'integer',
        'ready_items_count' => 'integer',
        'failed_items_count' => 'integer',
        'manifest_version' => 'integer',
        'lease_expires_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'replay_expires_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CreativePackageItem::class)->orderBy('position')->orderBy('id');
    }
}
