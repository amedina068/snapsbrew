<?php

namespace App\Mcp\Tools;

use App\Exceptions\IdempotencyConflictException;
use App\Mcp\ToolError;
use App\Mcp\Tools\Concerns\InteractsWithSnaps;
use App\Models\IdempotencyKey;
use App\Models\Snap;
use App\Models\SnapImport;
use App\Rules\SafeUrl;
use App\Services\IdempotencyClaimer;
use App\Services\SnapBatchService;
use App\Support\IdempotencyRules;
use App\Support\VariantRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('create-snaps-batch')]
#[Title('Create many snaps in one batch')]
#[Description('Renders up to 100 FINAL, un-watermarked snaps from ONE template and one set of variants per item. Returns a batch id right away and renders in the background. Only call this after the human has approved a preview (preview-snap). Poll get-snaps-batch with the batch id to read progress and collect the image URLs.')]
// destructiveHint stays true although a create is additive. A batch can render
// 100 final images that cannot be undone, and this hint makes a client ask the
// human first.
#[IsDestructive]
#[IsOpenWorld]
#[IsReadOnly(false)]
// No IsIdempotent. `idempotency_key` is optional, so a call without one is not
// idempotent, and a hint that is false for some calls is worse than no hint.
class CreateSnapsBatchTool extends Tool
{
    use InteractsWithSnaps;

    public function handle(Request $request, SnapBatchService $batches, IdempotencyClaimer $claimer): Response
    {
        $validated = $request->validate([
            'template_id' => ['required', 'string'],
            'title' => ['sometimes', 'string', 'max:255'],
            'webhook_url' => ['sometimes', 'nullable', 'url', 'max:2048', new SafeUrl],
            'type' => ['sometimes', 'in:image,pdf'],
            'format' => ['sometimes', 'in:png,jpeg,webp'],
            'transparent' => ['sometimes', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:'.SnapBatchService::MAX_ITEMS],
            'items.*.identifier' => ['sometimes', 'nullable', 'string', 'max:191'],
        ] + IdempotencyRules::rules() + VariantRules::rules('items.*.variants'), IdempotencyRules::messages());

        if (($validated['format'] ?? null) === 'jpeg' && $request->boolean('transparent')) {
            return ToolError::transparencyNotSupported();
        }

        $user = $request->user();
        $items = $validated['items'];

        $template = $this->companyTemplate($request, $validated['template_id']);

        if (! $template) {
            return ToolError::templateNotFound($validated['template_id']);
        }

        $key = $validated['idempotency_key'] ?? null;
        $claim = null;

        if ($key !== null) {
            try {
                $claim = $claimer->claim($user->company_id, $key);
            } catch (IdempotencyConflictException $e) {
                return ToolError::idempotencyKeyInProgress($e->getMessage());
            }

            if ($claim instanceof SnapImport) {
                return $this->batchResponse($claim, count($items), replayed: true);
            }

            if ($claim instanceof Snap) {
                return ToolError::idempotencyKeyBelongsToSnap();
            }
        }

        try {
            $import = $batches->create(
                $template,
                [
                    'company_id' => $user->company_id,
                    'user_id' => $user->id,
                    'title' => $validated['title'] ?? 'MCP batch',
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

        return $this->batchResponse($import, count($items));
    }

    /**
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
            // Null on purpose. One webhook reports the batch. The spreadsheet
            // import is the only surface that posts one event per item.
        ])->all();
    }

    private function batchResponse(SnapImport $import, int $itemCount, bool $replayed = false): Response
    {
        return Response::text(json_encode([
            'replayed' => $replayed,
            'batch' => $import->progressSnapshot(),
            'note' => $this->note($import, $itemCount, $replayed),
        ], JSON_PRETTY_PRINT));
    }

    /**
     * The sentence the agent repeats to the human.
     *
     * It must name the next call.
     * A batch id with no instruction to poll is a dead end.
     */
    private function note(SnapImport $import, int $itemCount, bool $replayed): string
    {
        if ($replayed) {
            return 'This batch already existed for that idempotency_key. It was returned, not started again. '
                .'Call get-snaps-batch with batch_id `'.$import->uuid.'` to read its current state.';
        }

        return 'Batch started with '.$itemCount.' items. Call get-snaps-batch with batch_id `'.$import->uuid.'` to read '
            .'progress and collect the image URLs. Poll about every 5 seconds. Stop when status is '
            .'completed, failed or canceled.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'template_id' => $schema->string()
                ->description('UUID of the template every item renders from.')
                ->required(),
            'items' => $schema->array()
                ->description('One entry per snap, at most '.SnapBatchService::MAX_ITEMS.'. Each entry carries its own "variants" and an optional "identifier" you use to recognize it in the result.')
                ->items($schema->object([
                    'identifier' => $schema->string()->description('Your own name for this item. Defaults to item_1, item_2, and so on.'),
                    'variants' => $this->variantsSchema($schema),
                ]))
                ->required(),
            'title' => $schema->string()
                ->description('A name for this batch, shown in the account. Defaults to "MCP batch".'),
            'webhook_url' => $schema->string()
                ->description('Optional public https URL. ONE request is posted to it when the batch finishes. The address must be public: a private, loopback or link-local host is rejected.'),
            'type' => $schema->string()
                ->enum(['image', 'pdf'])
                ->description('Output type for every item. Defaults to image.'),
            'format' => $schema->string()
                ->enum(['png', 'jpeg', 'webp'])
                ->description('Image output format for every item. Defaults to png. Transparency requires png or webp.'),
            'transparent' => $schema->boolean()
                ->description('Render every item on a transparent background (png/webp only). Defaults to false.'),
            'idempotency_key' => $schema->string()
                ->description('Optional. Send the same key when you retry after a timeout. The retry returns the same batch and starts no second one. Keys are honored for 24 hours, are scoped to your account, and accept letters, digits, underscores and hyphens, up to 191 characters.'),
        ];
    }
}
