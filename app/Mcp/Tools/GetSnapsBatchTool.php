<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolError;
use App\Models\Snap;
use App\Models\SnapImport;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-snaps-batch')]
#[Title('Read a snap batch')]
#[Description('Reads the progress of a batch started by create-snaps-batch. Renders nothing. Returns the counts and every item with its status and image URL. Poll this about every 5 seconds. Stop when status is completed, failed or canceled.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class GetSnapsBatchTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'batch_id' => ['required', 'string'],
        ]);

        // company_id first, then the uuid. A batch that belongs to another
        // company reads as "not found", never as "exists, not yours".
        $import = SnapImport::query()
            ->where('company_id', $request->user()->company_id)
            ->where('uuid', $validated['batch_id'])
            ->first();

        if (! $import) {
            return ToolError::batchNotFound($validated['batch_id']);
        }

        $snapshot = $import->progressSnapshot();

        return Response::text(json_encode([
            'batch' => $snapshot,
            'items' => $this->items($import),
            'note' => $this->note($snapshot),
        ], JSON_PRETTY_PRINT));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function items(SnapImport $import): array
    {
        // `media` is eager loaded because `imageUrl` reads the media collection.
        // Without it a 100-item batch runs 100 extra queries.
        return $import->snaps()
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
    }

    /**
     * The sentence the agent repeats to the human.
     *
     * `status` alone cannot carry a partial result. "failed" with 97 rendered
     * items and "failed" with zero rendered items are different facts, and the
     * agent must not report the second when the first happened.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function note(array $snapshot): string
    {
        $terminal = [
            SnapImport::STATUS_COMPLETED,
            SnapImport::STATUS_FAILED,
            SnapImport::STATUS_CANCELED,
        ];

        if (! in_array($snapshot['status'], $terminal, true)) {
            return $snapshot['completed'].' of '.$snapshot['total_rows'].' items are done. '
                .$snapshot['pending'].' are still running. Poll again in about 5 seconds.';
        }

        if ($snapshot['status'] === SnapImport::STATUS_CANCELED) {
            return 'This batch was canceled. '.$snapshot['completed'].' items rendered. '
                .$snapshot['canceled'].' were stopped. This is final.';
        }

        if ($snapshot['completed'] === 0) {
            return 'This batch is finished and no item rendered. This is final.';
        }

        $sentence = $snapshot['completed'].' of '.$snapshot['total_rows'].' items rendered.';

        if ($snapshot['failed'] > 0) {
            $sentence .= ' '.$snapshot['failed'].' failed. Read last_error on each failed item.';
        }

        return $sentence.' This is final.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'batch_id' => $schema->string()
                ->description('The batch id returned by create-snaps-batch.')
                ->required(),
        ];
    }
}
