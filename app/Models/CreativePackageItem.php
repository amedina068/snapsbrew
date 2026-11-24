<?php

namespace App\Models;

use App\Traits\HasUuid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class CreativePackageItem extends Model
{
    use HasFactory;
    use HasUuid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RENDERING = 'rendering';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id', 'creative_package_id',
        'position', 'client_creative_id',
        'client_family_id', 'label',
        'placement_key', 'caption',
        'alt_text', 'metadata',
        'source_template_uuid', 'source_template_version',
        'source_editor_document_version', 'source_template_version_id',
        'requested_format', 'status', 'attempts', 'retryable', 'lease_token', 'lease_generation', 'lease_expires_at',
        'snap_id', 'media_id', 'api_log_id', 'output_filename', 'output_mime_type', 'output_bytes',
        'output_width', 'output_height', 'output_sha256', 'failure_code', 'failure_details', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'position' => 'integer', 'metadata' => 'array',
        'source_template_version' => 'integer', 'source_editor_document_version' => 'integer',
        'attempts' => 'integer', 'retryable' => 'boolean', 'lease_generation' => 'integer',
        'output_bytes' => 'integer',
        'output_width' => 'integer', 'output_height' => 'integer',
        'lease_expires_at' => 'datetime', 'started_at' => 'datetime',
        'completed_at' => 'datetime', 'diagnostic_expires_at' => 'datetime',
    ];

    /** The hidden columns of a failed attempt. The ready write clears every one. */
    public const DIAGNOSTIC_COLUMNS = [
        'diagnostic_code', 'diagnostic_exception_class', 'diagnostic_correlation_id',
        'diagnostic_message_fingerprint', 'diagnostic_expires_at',
    ];

    protected $hidden = self::DIAGNOSTIC_COLUMNS;

    /**
     * The hidden diagnostic of the last failed attempt. The S18 operator
     * command is its only caller. No MCP tool, API resource or serializer
     * reads it, and the columns stay in `$hidden`, so a `toArray()` call
     * never emits them. A ready item returns null in every field, because
     * the ready write clears the columns of the attempts before it.
     *
     * @return array{diagnostic_code: string|null, diagnostic_exception_class: string|null, diagnostic_correlation_id: string|null, diagnostic_message_fingerprint: string|null, diagnostic_expires_at: string|null}
     */
    public function diagnostics(): array
    {
        return [
            'diagnostic_code' => $this->diagnostic_code,
            'diagnostic_exception_class' => $this->diagnostic_exception_class,
            'diagnostic_correlation_id' => $this->diagnostic_correlation_id,
            'diagnostic_message_fingerprint' => $this->diagnostic_message_fingerprint,
            'diagnostic_expires_at' => $this->diagnostic_expires_at?->toDateTimeString(),
        ];
    }

    /**
     * Items in one of the statuses that no live lease holds. A lease is live up
     * to and including `lease_expires_at`. The fence and the reconciler share
     * this one rule.
     *
     * @param  array<int, string>  $statuses
     */
    public function scopeUnowned(Builder $query, array $statuses, CarbonInterface $now): Builder
    {
        return $query->whereIn('status', $statuses)->where('retryable', true)
            ->where(fn (Builder $lease) => $lease->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<', $now));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(CreativePackage::class, 'creative_package_id');
    }

    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'source_template_version_id');
    }

    public function snap(): BelongsTo
    {
        return $this->belongsTo(Snap::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function apiLog(): BelongsTo
    {
        return $this->belongsTo(ApiLog::class);
    }
}
