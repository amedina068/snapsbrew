<?php

namespace App\Mcp\Tools\Concerns;

use App\Exceptions\IdempotencyConflictException;
use App\Mcp\ToolError;
use App\Models\Snap;
use App\Services\UsageRecorder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * The checks and the usage record that every MCP tool which creates one snap
 * runs, in one place. `create-snap` and `create-snap-from-design` differ in
 * what they render from, not in what they check or record.
 *
 * The response envelope stays in each tool: `ignored_layers` and the template
 * note have no meaning for a design.
 */
trait CreatesMcpSnaps
{
    /**
     * The snap tools read two codes from the claim: another call holds the
     * key, or a batch owns the key. Only the first one is retryable.
     */
    protected function idempotencyError(IdempotencyConflictException $exception): Response
    {
        return $exception->errorCode === IdempotencyConflictException::BELONGS_TO_BATCH
            ? ToolError::idempotencyKeyBelongsToBatch()
            : ToolError::idempotencyKeyInProgress($exception->getMessage());
    }

    /**
     * A jpeg cannot carry an alpha channel.
     */
    protected function rejectUnsupportedTransparency(array $validated, Request $request): ?Response
    {
        if (($validated['format'] ?? null) === 'jpeg' && $request->boolean('transparent')) {
            return ToolError::transparencyNotSupported();
        }

        return null;
    }

    /**
     * A replay returns the snap an earlier call already created. Record a
     * render only for the call that really created it.
     *
     * @return array{replayed: bool}
     */
    protected function recordUnlessReplayed(Snap $snap): array
    {
        $replayed = ! $snap->wasRecentlyCreated;

        if (! $replayed) {
            UsageRecorder::recordMcpSnap($snap);
        }

        return ['replayed' => $replayed];
    }
}
