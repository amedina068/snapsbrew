<?php

namespace App\Services;

use App\Mail\SnapImportFinishedMail;
use App\Models\Snap;
use App\Models\SnapImport;
use App\Support\Queues;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SnapImportFinalizer
{
    /**
     * Decide the terminal status after every item in the batch has run.
     *
     * Laravel calls `then()` only when zero jobs failed, and calls `catch()` at
     * the first failed job, which happens while the rest of the batch is still
     * running. Neither callback can describe a batch that ran to the end with
     * some items failed. `finally()` runs one time after every job reached a
     * terminal state, so it is the only place this decision is correct.
     */
    public static function finalize(SnapImport $import): void
    {
        self::clearCancellationFlag($import);

        $import = $import->fresh();

        if (! $import || $import->status === SnapImport::STATUS_CANCELED) {
            return;
        }

        // Every item ran, so the item counts decide the batch. Zero renders is a
        // failed batch. One render or more is a completed batch, and the caller
        // reads the failed count for the rest.
        $rendered = $import->snaps()
            ->where('status', Snap::STATUS_COMPLETED)
            ->count();

        if ($rendered === 0) {
            self::markFailed($import);

            return;
        }

        self::markCompleted($import);
    }

    /**
     * Keep the message of the first failed item without touching the status.
     *
     * The batch is still running when this is called. The status is not known
     * yet. Only the message is.
     */
    public static function recordFailure(SnapImport $import, ?Throwable $exception = null): void
    {
        SnapImport::query()
            ->where('id', $import->id)
            ->update(['error_message' => optional($exception)->getMessage()]);
    }

    public static function markCompleted(SnapImport $import): void
    {
        $import = $import->fresh();

        if (! $import || $import->status === SnapImport::STATUS_CANCELED) {
            return;
        }

        $import->update([
            'status' => SnapImport::STATUS_COMPLETED,
            'completed_at' => now(),
            'error_message' => null,
        ]);

        self::notify($import, 'snap-import.completed');
    }

    public static function markFailed(SnapImport $import, ?Throwable $exception = null): void
    {
        $import = $import->fresh();

        if (! $import) {
            return;
        }

        $attributes = ['status' => SnapImport::STATUS_FAILED];

        // A null exception must not erase the message `recordFailure()` already
        // stored from the first failed item.
        if ($exception) {
            $attributes['error_message'] = $exception->getMessage();
        }

        $import->update($attributes);

        self::notify($import, 'snap-import.failed');
    }

    public static function markCanceled(SnapImport $import): void
    {
        $import = $import->fresh();

        if (! $import) {
            return;
        }

        $import->update([
            'status' => SnapImport::STATUS_CANCELED,
            'canceled_at' => now(),
        ]);

        self::notify($import, 'snap-import.canceled');
    }

    /**
     * Stop a batch that is still running.
     *
     * Returns false when the batch already holds a terminal status, so the
     * caller can answer a second cancel without changing anything. The order
     * matters: the cache flag is set first, because a worker that starts an item
     * between the flag and the row update reads the flag and stops.
     */
    public static function cancel(SnapImport $import): bool
    {
        if (in_array($import->status, [
            SnapImport::STATUS_COMPLETED,
            SnapImport::STATUS_FAILED,
            SnapImport::STATUS_CANCELED,
        ], true)) {
            return false;
        }

        Cache::put(
            SnapImport::cancellationCacheKey($import->id),
            now(),
            now()->addDay()
        );

        if ($import->batch_id) {
            $batch = Bus::findBatch($import->batch_id);

            if ($batch) {
                $batch->cancel();
            }
        }

        $import->snaps()
            ->whereIn('status', [Snap::STATUS_PENDING, Snap::STATUS_PROCESSING])
            ->update([
                'status' => Snap::STATUS_CANCELED,
                'completed_at' => now(),
            ]);

        $import->refresh();
        $import->update([
            'processed_rows' => $import->snaps()
                ->whereIn('status', [Snap::STATUS_COMPLETED, Snap::STATUS_FAILED, Snap::STATUS_CANCELED])
                ->count(),
        ]);

        self::markCanceled($import);

        return true;
    }

    public static function clearCancellationFlag(SnapImport $import): void
    {
        Cache::forget(SnapImport::cancellationCacheKey($import->id));
    }

    private static function notify(SnapImport $import, string $event): void
    {
        $import->loadMissing('user', 'template');

        if ($import->user) {
            Mail::to($import->user->email)
                ->queue(
                    (new SnapImportFinishedMail($import->fresh(['template'])))->onQueue(Queues::MAIL)
                );
        }

        SnapImportWebhookDispatcher::dispatch($import->fresh(['template']), $event);
    }
}
