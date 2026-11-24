<?php

namespace App\Jobs;

use App\Builders\SnapBuilder;
use App\Http\Resources\SnapResource;
use App\Models\Snap;
use App\Models\SnapImport;
use App\Models\User;
use App\Services\EditorPreflight;
use App\Services\SnapSourceVersioner;
use App\Services\UsageRecorder;
use App\Services\WebhookSender;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSnap implements ShouldQueue
{
    // Batchable is not decoration. PendingBatch::ensureJobIsBatchable() reads
    // class_uses_recursive() and throws before any queue work starts, so a bulk
    // import returned 500 on every upload without it.
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        private Snap $snap
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $snap = $this->snap->fresh(['sourceTemplateVersion', 'import']);

        if (! $snap) {
            return;
        }

        if ($snap->import) {
            $cancelKey = SnapImport::cancellationCacheKey($snap->import->id);

            if ($snap->import->status === SnapImport::STATUS_CANCELED || Cache::has($cancelKey)) {
                $snap->forceFill([
                    'status' => Snap::STATUS_CANCELED,
                    'completed_at' => now(),
                    'last_error' => null,
                ])->save();

                $this->incrementImportProgress($snap->import);

                return;
            }
        }

        $variants = json_decode($snap->variants ?? '[]', true) ?: [];
        $type = $snap->type ?? Snap::TYPE_IMAGE;

        if ($snap->status !== Snap::STATUS_COMPLETED) {
            // New rows always have a source at dispatch. Legacy queued rows
            // receive one immutable snapshot under locks before rendering;
            // this is an adapter, never a fallback to mutable layers.
            if (! $snap->sourceTemplateVersion) {
                $snap = app(SnapSourceVersioner::class)->materializeLegacySource($snap);

                if (! $snap) {
                    return;
                }
            }

            $snap->forceFill([
                'status' => Snap::STATUS_PROCESSING,
                'last_error' => null,
            ])->save();

            $source = $snap->sourceTemplateVersion;
            $issues = app(EditorPreflight::class)->inspect($source->editor_document);

            if ($issues !== []) {
                throw new \LogicException($issues[0]['code']);
            }

            $snapBuilder = (new SnapBuilder)
                ->setLayers($source->layers)
                ->setGroups($source->groups)
                ->forCompany($snap->company_id)
                ->setWidth($source->width)
                ->setHeight($source->height)
                ->withVariables($variants);

            $snap->addContent($snapBuilder->buildContent());
            $snap->addHead($snapBuilder->buildHead());

            $snap->{$type === Snap::TYPE_PDF ? 'getPdf' : 'getImage'}();

            $user = User::find($snap->user_id);

            $activity = activity()
                ->performedOn($snap)
                ->withProperties([
                    'layers' => json_decode($source->layers, true),
                    'width' => $source->width,
                    'height' => $source->height,
                    'variants' => $variants,
                ]);

            if ($user) {
                $activity->causedBy($user);
            }

            $activity->log('created');

            $snap->forceFill([
                'status' => Snap::STATUS_COMPLETED,
                'completed_at' => now(),
            ])->save();

            UsageRecorder::recordCompletedAsyncSnap($snap);

            $this->incrementImportProgress($snap->import);
        }

        $snap = $snap->fresh()->append('imageUrl');

        $this->sendWebhook($snap);
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        $snap = $this->snap->fresh();

        if ($snap) {
            // A sync render rethrows to SnapService::render(), which decides
            // whether its caller logs the message. A creative package keeps
            // only a fingerprint of it.
            if ($snap->is_async) {
                self::logRenderFailure($snap, $exception);
            }

            $snap->forceFill([
                'status' => Snap::STATUS_FAILED,
                'last_error' => Snap::ERROR_RENDER_FAILED,
                'completed_at' => now(),
            ])->save();

            $this->incrementImportProgress($snap->import);

            // A delivery failure is only logged here. `last_error` keeps the
            // render code, because the render is the failure the owner must see.
            if ($snap->is_async) {
                $this->postWebhook($snap, 'snap.failed');
            }
        }
    }

    /**
     * The one place that writes the raw message of a failed render. The row
     * holds Snap::ERROR_RENDER_FAILED, so the log is the only copy.
     */
    public static function logRenderFailure(Snap $snap, Throwable $exception): void
    {
        Log::warning('Snap render failed.', [
            'snap_id' => $snap->id,
            'snap_uuid' => $snap->uuid,
            'exception' => strstr($exception::class, '@anonymous', true) ?: $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    /**
     * Dispatch the webhook payload once the snap is ready.
     */
    private function sendWebhook(Snap $snap): void
    {
        if (! $snap->webhook_url) {
            return;
        }

        // A fixed code. The API returns `last_error` to the owner of the snap,
        // so it never holds the body of the receiver.
        $snap->forceFill(['last_error' => $this->postWebhook($snap, 'snap.completed')])->save();
    }

    /**
     * @return string|null null when the receiver accepted the event, or the code of the last failure
     */
    private function postWebhook(Snap $snap, string $event): ?string
    {
        if (! $snap->webhook_url) {
            return null;
        }

        $error = WebhookSender::deliver($snap->webhook_url, [
            'event' => $event,
            'snap' => (new SnapResource($snap))->toArray(null),
        ], $snap->company->webhook_secret);

        if ($error !== null) {
            Log::warning('Failed to deliver snap webhook.', [
                'snap_id' => $snap->id,
                'snap_uuid' => $snap->uuid,
                'webhook_url' => $snap->webhook_url,
                'event' => $event,
                'attempts' => WebhookSender::MAX_ATTEMPTS,
                'error' => $error,
            ]);
        }

        return $error;
    }

    private function incrementImportProgress(?SnapImport $import): void
    {
        if (! $import) {
            return;
        }

        SnapImport::query()
            ->where('id', $import->id)
            ->whereColumn('processed_rows', '<', 'total_rows')
            ->increment('processed_rows');
    }
}
