<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\IdempotencyConflictException;
use App\Http\Controllers\Controller;
use App\Http\Responses\InternalError;
use App\Models\ApiLog;
use App\Models\IdempotencyKey;
use App\Models\Snap;
use App\Models\SnapImport;
use App\Models\Template;
use App\Rules\SafeUrl;
use App\Services\IdempotencyClaimer;
use App\Services\SnapBatchService;
use App\Services\SnapImportFinalizer;
use App\Services\UsageRecorder;
use App\Support\IdempotencyRules;
use App\Support\VariantRules;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates and reads a batch of snaps.
 *
 * The batch record is a `snap_imports` row. That table was built for the
 * spreadsheet import and already carries progress, a terminal status, a webhook
 * and a cancel flag. `original_filename` and `stored_path` are nullable and stay
 * null here, so this surface needs no table of its own.
 */
class SnapBatchController extends Controller
{
    public function store(Request $request, SnapBatchService $batches, IdempotencyClaimer $claimer)
    {
        $companyId = Auth::user()->company_id;

        try {
            $validated = $request->validate([
                'template_id' => [
                    'required',
                    'string',
                    Rule::exists('templates', 'uuid')->where('company_id', $companyId),
                ],
                'title' => ['sometimes', 'string', 'max:255'],
                // SafeUrl, not just `url`. The server POSTs to this address from
                // the render host. See the comment in SnapImportController.
                'webhook_url' => ['nullable', 'url', 'max:2048', new SafeUrl],
                // These three describe the output and apply to every item. An
                // item that needs a different format is a different batch.
                'type' => ['sometimes', Rule::in(Snap::TYPES)],
                'format' => ['sometimes', Rule::in(Snap::FORMATS)],
                'transparent' => ['sometimes', 'boolean'],
                'items' => ['required', 'array', 'min:1', 'max:'.SnapBatchService::MAX_ITEMS],
                'items.*.identifier' => ['sometimes', 'nullable', 'string', 'max:191'],
            ] + IdempotencyRules::rules() + VariantRules::rules('items.*.variants'), IdempotencyRules::messages() + [
                'items.max' => 'A batch accepts at most '.SnapBatchService::MAX_ITEMS.' items. Split the request into smaller batches.',
                'items.required' => 'Send at least one item. Each item describes one snap.',
            ]);

            // Transparency needs an alpha channel and JPEG has none. Reject
            // rather than flatten to a white background without saying so.
            if (($validated['format'] ?? null) === 'jpeg' && $request->boolean('transparent')) {
                throw ValidationException::withMessages([
                    'transparent' => 'Transparency is not supported for the JPEG format.',
                ]);
            }

            $items = $validated['items'];

            $template = Template::query()
                ->uuid($validated['template_id'])
                ->where('company_id', $companyId)
                ->firstOrFail();

            $key = $validated['idempotency_key'] ?? null;
            $claim = null;

            if ($key !== null) {
                $claim = $claimer->claim($companyId, $key);

                if ($claim instanceof SnapImport) {
                    return $this->respond($request, $this->snapshot($claim), 202, replayed: true);
                }

                // The key already names one snap from POST /v1/snaps. Returning
                // that snap here would answer a batch request with a single
                // object, so refuse instead of guessing.
                if ($claim instanceof Snap) {
                    throw IdempotencyConflictException::belongsToSnap();
                }
            }

            try {
                $import = $batches->create(
                    $template,
                    [
                        'company_id' => $companyId,
                        'user_id' => Auth::id(),
                        'title' => $validated['title'] ?? 'API batch',
                        'webhook_url' => $validated['webhook_url'] ?? null,
                    ],
                    $this->rows($items, $validated, $request),
                );
            } catch (\Throwable $exception) {
                if ($claim instanceof IdempotencyKey) {
                    $claimer->release($claim);
                }

                throw $exception;
            }

            if ($claim instanceof IdempotencyKey) {
                $claimer->completeForImport($claim, $import);
            }

            return $this->respond($request, $this->snapshot($import), 202);
        } catch (IdempotencyConflictException $e) {
            return $this->respond($request, ['message' => $e->getMessage(), 'code' => $e->errorCode], 409);
        } catch (ValidationException $e) {
            return $this->respond($request, [
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            // The same body as POST /v1/snaps. The raw message stays in the log.
            return $this->respond($request, InternalError::report($e, $request, 'batch', [
                'template_id' => $request->input('template_id'),
            ]), 500);
        }
    }

    public function show(Request $request, string $batchUuid)
    {
        // company_id first, then the uuid. A uuid that belongs to another
        // company reads as "not found", never as "exists, not yours".
        $import = SnapImport::query()
            ->where('company_id', Auth::user()->company_id)
            ->uuid($batchUuid)
            ->first();

        if (! $import) {
            return $this->respond($request, ['message' => 'No batch with that id exists in your account.'], 404);
        }

        return $this->respond($request, $this->snapshot($import, withItems: true), 200);
    }

    public function cancel(Request $request, string $batchUuid)
    {
        $import = SnapImport::query()
            ->where('company_id', Auth::user()->company_id)
            ->uuid($batchUuid)
            ->first();

        if (! $import) {
            return $this->respond($request, ['message' => 'No batch with that id exists in your account.'], 404);
        }

        // A batch that already holds a terminal status answers with its current
        // state and changes nothing. Cancel is safe to repeat.
        SnapImportFinalizer::cancel($import);

        return $this->respond($request, $this->snapshot($import->fresh(), withItems: true), 200);
    }

    /**
     * Build one snap row payload per item.
     *
     * `webhook_url` is not set on the rows. This surface sends one webhook when
     * the batch finishes, unlike the spreadsheet import, which posts one event
     * per item as its screen promises.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $validated
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $items, array $validated, Request $request): array
    {
        $type = $validated['type'] ?? Snap::TYPE_IMAGE;
        $format = $validated['format'] ?? 'png';
        $transparent = $request->boolean('transparent');

        return collect($items)->values()->map(fn ($item, $index) => [
            'identifier' => $item['identifier'] ?? 'item_'.($index + 1),
            'variants' => $item['variants'] ?? [],
            'metadata' => [],
            'type' => $type,
            'format' => $format,
            'transparent' => $transparent,
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(SnapImport $import, bool $withItems = false): array
    {
        $snapshot = $import->progressSnapshot() + [
            'status_url' => url('api/v1/snaps/batches/'.$import->uuid),
        ];

        if (! $withItems) {
            return $snapshot;
        }

        // `media` is eager loaded because `imageUrl` reads the media collection.
        // Without it a 100-item batch runs 100 extra queries.
        $snapshot['items'] = $import->snaps()
            ->with('media')
            ->orderBy('id')
            ->get()
            ->map(fn (Snap $snap) => [
                'id' => $snap->uuid,
                'identifier' => $snap->identifier,
                'status' => $snap->status,
                'media_url' => $snap->status === Snap::STATUS_COMPLETED ? $snap->imageUrl : null,
                'last_error' => $snap->last_error,
            ])->all();

        return $snapshot;
    }

    private function respond(Request $request, array $payload, int $statusCode, bool $replayed = false)
    {
        $apiLog = ApiLog::uuid($request->api_log_uuid)->first();

        // Always zero. HTTP 202 is not a render. Each item is recorded on
        // completion by UsageRecorder::recordCompletedAsyncSnap() from inside
        // its own job.
        UsageRecorder::settle(
            $apiLog,
            renders: 0,
            feature: UsageRecorder::FEATURE_API,
            type: UsageRecorder::FEATURE_SNAP_IMPORT,
            statusCode: $statusCode,
            response: $payload,
        );

        $headers = ['X-Request-Uuid' => $apiLog->uuid];

        if ($replayed) {
            $headers['X-Idempotent-Replay'] = 'true';
        }

        return response()->json($payload, $statusCode)->withHeaders($headers);
    }
}
