<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolError;
use App\Models\SnapImport;
use App\Services\SnapImportFinalizer;
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

#[Name('cancel-snaps-batch')]
#[Title('Cancel a running snap batch')]
#[Description('Stops a batch started by create-snaps-batch. Items that already rendered stay. Items that had not started are marked canceled and never render. An item that is rendering right now can still finish. This cannot be undone: a canceled batch cannot be resumed. A batch that already finished is left alone.')]
#[IsReadOnly(false)]
#[IsDestructive]
// Canceling twice leaves the same state, and the second call changes nothing.
#[IsIdempotent]
#[IsOpenWorld(false)]
class CancelSnapsBatchTool extends Tool
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

        $stopped = SnapImportFinalizer::cancel($import);
        $snapshot = $import->fresh()->progressSnapshot();

        return Response::text(json_encode([
            'stopped' => $stopped,
            'batch' => $snapshot,
            'note' => $this->note($snapshot, $stopped),
        ], JSON_PRETTY_PRINT));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function note(array $snapshot, bool $stopped): string
    {
        if (! $stopped) {
            return 'This batch had already finished with status '.$snapshot['status']
                .'. Nothing changed. Read it with get-snaps-batch.';
        }

        return 'Batch canceled. '.$snapshot['completed'].' of '.$snapshot['total_rows']
            .' items rendered. '.$snapshot['canceled'].' were stopped. '
            .'This cannot be undone. Start a new batch for the remaining items.';
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
