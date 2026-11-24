<?php

namespace App\Services;

use App\Jobs\ProcessSnap;
use App\Models\Snap;
use App\Models\SnapImport;
use App\Models\Template;
use App\Support\Queues;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Creates one batch of snaps and starts it. Shared by the spreadsheet import
 * and by the batch API, so the transaction and the batch callbacks live in one
 * place.
 *
 * Usage is not recorded here. Each item is recorded on completion by
 * `UsageRecorder::recordCompletedAsyncSnap()` from inside its own job, because
 * HTTP 202 is not a render.
 */
class SnapBatchService
{
    /** The largest number of items one request can create. */
    public const MAX_ITEMS = 100;

    public function __construct(private SnapSourceVersioner $sourceVersioner) {}

    /**
     * Create the batch record and its item rows, then start the batch.
     *
     * @param  array<string, mixed>  $importAttributes  columns for the `snap_imports` row
     * @param  array<int, array<string, mixed>>  $items  one entry per snap. Each entry
     *                                                   accepts `identifier`, `variants`, `metadata`, `type`, `format`,
     *                                                   `transparent` and `webhook_url`.
     */
    public function create(Template $template, array $importAttributes, array $items): SnapImport
    {
        // One transaction around every row. Without it an exception at row k
        // commits rows 1 to k-1 and leaves them `pending` with no batch to
        // render them. The dispatch stays outside: a worker that reads a snap
        // row before its insert is visible returns early and drops that item.
        [$import, $jobs] = DB::transaction(function () use ($template, $importAttributes, $items) {
            $template = $this->sourceVersioner->lock($template);
            $source = $this->sourceVersioner->sourceForLockedTemplate($template);
            $import = SnapImport::create($importAttributes + [
                'template_id' => $template->id,
                'status' => SnapImport::STATUS_PENDING,
                'total_rows' => count($items),
                'processed_rows' => 0,
            ]);

            $jobs = [];

            foreach ($items as $index => $item) {
                $snap = $template->snaps()->create([
                    'user_id' => $import->user_id,
                    'company_id' => $import->company_id,
                    'source_template_version_id' => $source->id,
                    // The default names the row after the batch. The spreadsheet
                    // import relies on that format, so it passes no identifier.
                    'identifier' => $item['identifier'] ?? sprintf('%s-%d', $import->uuid, $index + 1),
                    'variants' => json_encode($item['variants'] ?? []),
                    'metadata' => json_encode($item['metadata'] ?? []),
                    'type' => $item['type'] ?? Snap::TYPE_IMAGE,
                    'format' => $item['format'] ?? 'png',
                    'transparent' => $item['transparent'] ?? false,
                    'is_async' => true,
                    'status' => Snap::STATUS_PENDING,
                    // Null on a batch-API row. That surface sends one webhook
                    // when the batch finishes, not one per item.
                    'webhook_url' => $item['webhook_url'] ?? null,
                    'last_error' => null,
                    'completed_at' => null,
                    'snap_import_id' => $import->id,
                ]);

                // No onQueue() here. Bus::batch takes one queue for the whole
                // batch and discards the queue of each job. See the batch below.
                $jobs[] = new ProcessSnap($snap);
            }

            return [$import, $jobs];
        });

        $batch = Bus::batch($jobs)
            ->name('snap-import-'.$import->id)
            // The queue belongs on the batch. Batch::add() passes one queue name
            // to the bulk insert, so a per-job onQueue() is dropped in silence.
            ->onQueue(Queues::IMPORTS)
            // Without this, Laravel cancels the batch at the first failed item.
            // `Batch::cancel()` is also what the cancel controller calls when a
            // person stops an import, so the two states become the same.
            ->allowFailures()
            // `catch()` fires at the first failed item, while the rest of the
            // batch is still running, so it records the message and nothing else.
            ->catch(function (Batch $batch, Throwable $exception) use ($import) {
                SnapImportFinalizer::recordFailure($import, $exception);
            })
            // `finally()` is the only callback that runs one time after every
            // item reached a terminal state, so the terminal status is decided
            // there.
            ->finally(function () use ($import) {
                SnapImportFinalizer::finalize($import);
            })
            ->dispatch();

        $import->update([
            'status' => SnapImport::STATUS_PROCESSING,
            'batch_id' => $batch->id,
        ]);

        return $import->fresh();
    }
}
