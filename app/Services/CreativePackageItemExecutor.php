<?php

namespace App\Services;

use App\Models\CreativePackageItem;
use App\Models\Snap;
use App\Support\CreativePackageContract;
use App\Support\CreativePackageDiagnostic;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Does the work for one claimed item. The job calls this seam with the
 * rendering item and the live lease, and the executor presents the lease to
 * every later side effect.
 *
 * Sequence: entitlement, guard, heartbeat, render (or reuse the snap of a
 * crashed attempt), the snap_id write through guard(), verify the media, the
 * output write through guard(), heartbeat, record the usage row, then the
 * terminal write through guard(). The only orphan window runs from the snap
 * row insert inside createFromVersion() to the guarded snap_id write, which
 * lands as soon as the render returns. That window holds the whole render.
 * Every crash after that write replays through the stored snap without a
 * second render. A heartbeat renews the lease through the fence, so a stale
 * fence stops the worker before the render and before the usage row. A fence
 * that goes stale between the usage row and the ready write leaves the row in
 * place: the item keeps its api_log_id, so the next worker writes no second
 * row.
 */
class CreativePackageItemExecutor
{
    /** The id of the attempt that runs now. Every log line and every diagnostic row of the attempt carries it. */
    private string $correlationId = '';

    public function __construct(
        private CreativePackageItemFence $fence,
        private CreativePackageAggregator $aggregator,
        private CreativePackageItemAttempts $attempts,
        private SnapService $snaps,
    ) {}

    /**
     * CreativePackageItemFence::guard() and UsageRecorder::recordPackageItem()
     * each lock the item alone, as separate top-level transactions in
     * sequence: work, guard, record, guard.
     *
     * The executor may pass only `status` ready or failed through guard().
     *
     * The job mints one correlation id per attempt. A caller that passes none
     * gets one minted here, so a diagnostic row never lacks it.
     */
    public function execute(CreativePackageItem $item, CreativePackageItemLease $lease, ?string $correlationId = null): void
    {
        $this->correlationId = $correlationId ?? (string) Str::uuid();
        $started = hrtime(true);
        try {
            $this->run($item, $lease);
        } catch (StaleFenceException) {
            Log::warning('creative_package_item.stale_fence', ['item_id' => $item->id, 'correlation_id' => $this->correlationId]);

            return;
        }
        $this->aggregator->recount($item->package);
        $finished = $item->fresh();
        Log::info('creative_package_item.completed', [
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000), 'status' => $finished->status, 'attempts' => $finished->attempts,
            'correlation_id' => $this->correlationId, 'item_id' => $item->id, 'package_id' => $item->creative_package_id,
            'company_id' => $item->company_id, 'error_code' => $finished->failure_code,
        ]);
    }

    private function run(CreativePackageItem $item, CreativePackageItemLease $lease): void
    {
        $company = $item->company;
        $creator = $item->package->creator;
        $entitled = $item->package->company_id === $item->company_id && $creator?->company_id === $item->company_id
            && CreativePackageClaim::entitled($creator, $company);
        if (! $entitled) {
            $this->fail($item, $lease, 'creative_package_entitlement_required', retryable: true);

            return;
        }
        $this->fence->guard($lease);
        $this->attempts->heartbeat($item, $lease);
        $snap = $this->completedSnap($item);
        if ($snap === null) {
            try {
                $snap = $this->snaps->createFromVersion($item->sourceVersion->template, $item->sourceVersion, null, Snap::TYPE_IMAGE, false, null, (int) $creator->id, $item->company_id, $item->requested_format, keepErrorMessage: false);
            } catch (\Throwable $exception) {
                $this->fail($item, $lease, 'creative_package_render_failed', retryable: false, exception: $exception);

                return;
            }
            $this->fence->guard($lease, ['snap_id' => $snap->id]);
        }
        $media = $snap->getFirstMedia('image');
        $output = $media ? $this->verify($item, $media) : null;
        if ($output === null) {
            $this->fail($item, $lease, 'creative_package_output_invalid', retryable: false, attributes: ['snap_id' => $snap->id]);

            return;
        }

        $this->fence->guard($lease, ['snap_id' => $snap->id, 'media_id' => $media->id] + $output);
        $this->attempts->heartbeat($item, $lease);
        UsageRecorder::recordPackageItem($item);
        // A ready item keeps nothing of the attempts before it, so a reader never joins a live row to a stale failure.
        $this->fence->guard($lease, ['status' => CreativePackageItem::STATUS_READY, 'retryable' => false, 'completed_at' => now()]
            + array_fill_keys(['failure_code', 'failure_details', ...CreativePackageItem::DIAGNOSTIC_COLUMNS], null));
    }

    /** The snap of an earlier attempt that crashed after its render, or null. */
    private function completedSnap(CreativePackageItem $item): ?Snap
    {
        $snap = $item->snap;

        return $snap?->status === Snap::STATUS_COMPLETED && $snap->getFirstMedia('image') !== null ? $snap : null;
    }

    /**
     * The output facts of the managed media, with the measured pixel
     * dimensions, or null when the file is empty or its type or size does not
     * match the requested format and the source.
     *
     * @return array<string, mixed>|null
     */
    private function verify(CreativePackageItem $item, Media $media): ?array
    {
        $bytes = (string) Storage::disk($media->disk)->get($media->getPathRelativeToRoot());
        $image = $bytes === '' ? false : @getimagesizefromstring($bytes);
        $mime = Snap::FORMAT_META[$item->requested_format]['mime'] ?? null;
        $source = $item->sourceVersion;
        if ($image === false || $media->mime_type !== $mime || $image['mime'] !== $mime || [$image[0], $image[1]] !== [(int) $source->width, (int) $source->height]) {
            return null;
        }

        return ['output_filename' => $media->file_name, 'output_mime_type' => $mime, 'output_bytes' => strlen($bytes), 'output_width' => $image[0], 'output_height' => $image[1], 'output_sha256' => hash('sha256', $bytes)];
    }

    /**
     * Write the failed state through the fence. The
     * public failure code names the outcome and the hidden diagnostic columns
     * keep the code, the exception class, the correlation id and a fingerprint
     * of the message. The message itself is never stored. A
     * SourceVersionUnavailableException is a refusal before the render, so its
     * public detail does not say the renderer ran.
     *
     * The write clears the lease. A failed item holds no work, and a replay
     * reads `unowned()`, so a lease left on the row hides the item from the
     * replay until the lease runs out. A stale worker cannot clear a lease it
     * lost, because guard() refuses its write first.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function fail(CreativePackageItem $item, CreativePackageItemLease $lease, string $code, bool $retryable, ?\Throwable $exception = null, array $attributes = []): void
    {
        $now = now();
        $this->fence->guard($lease, $attributes + [
            'status' => CreativePackageItem::STATUS_FAILED, 'retryable' => $retryable, 'failure_code' => $code, 'lease_expires_at' => null,
            'failure_details' => match (true) {
                $exception === null => null,
                $exception instanceof SourceVersionUnavailableException => 'The source could not be prepared for rendering.',
                default => 'The renderer did not produce an image.',
            }, 'completed_at' => $now,
            'diagnostic_code' => $code,
            'diagnostic_correlation_id' => $this->correlationId,
            'diagnostic_exception_class' => $exception ? CreativePackageDiagnostic::className($exception) : null,
            'diagnostic_message_fingerprint' => $exception ? CreativePackageDiagnostic::fingerprint($exception->getMessage()) : null,
            'diagnostic_expires_at' => $now->copy()->addDays(CreativePackageContract::limit('diagnostic_days', CreativePackageContract::DIAGNOSTIC_DAYS)),
        ]);
    }
}
