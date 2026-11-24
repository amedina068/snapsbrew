<?php

namespace App\Services;

use App\Exceptions\IdempotencyConflictException;
use App\Models\IdempotencyKey;
use App\Models\Snap;
use App\Models\SnapImport;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Owns the claim state machine for one idempotency key.
 *
 * The unique index on `(company_id, key)` is the lock. A read before a write
 * loses a real race, so the insert runs first and the loser reads the winner's
 * row. The insert is its own transaction and commits before any render starts.
 * A lock held across a browser render would serialize every retry behind it.
 *
 * Every path fails closed. When this class cannot prove what an earlier request
 * produced, it raises a conflict rather than create a second snap.
 */
class IdempotencyClaimer
{
    /**
     * Take the claim on a key, or resolve what an earlier request already made.
     *
     * Returns an IdempotencyKey when the caller owns the claim and must render.
     * Returns a Snap or a SnapImport when an earlier request already finished.
     *
     * @throws IdempotencyConflictException when another request holds the claim.
     */
    public function claim(int $companyId, string $key): IdempotencyKey|Snap|SnapImport
    {
        try {
            return IdempotencyKey::create([
                'company_id' => $companyId,
                'key' => $key,
                'status' => IdempotencyKey::STATUS_IN_PROGRESS,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Somebody else owns this key, or owned it. Laravel raises this one
            // class for MySQL 1062 and for the SQLite unique failure alike, so
            // this branch needs no driver check.
        }

        // Scoped by company_id. Without it, one company sends the key another
        // company used and reads that company's snap.
        $existing = IdempotencyKey::query()
            ->where('company_id', $companyId)
            ->where('key', $key)
            ->first();

        // The row lost the insert race and then vanished before this read.
        // Creating a snap here can double-charge, which is the bug this feature
        // removes, so refuse instead.
        if (! $existing) {
            throw new IdempotencyConflictException;
        }

        if ($existing->status === IdempotencyKey::STATUS_COMPLETED) {
            return $this->resolve($existing);
        }

        if ($existing->status === IdempotencyKey::STATUS_FAILED) {
            return $this->reclaim($existing);
        }

        // Another request holds the claim right now.
        throw new IdempotencyConflictException;
    }

    /**
     * Mark the claim done and point it at the snap it produced.
     */
    public function complete(IdempotencyKey $claim, Snap $snap): void
    {
        $claim->update([
            'snap_id' => $snap->id,
            'status' => IdempotencyKey::STATUS_COMPLETED,
        ]);
    }

    /**
     * Mark the claim done and point it at the batch it produced.
     *
     * A batch claim completes when the rows are committed, not when the render
     * ends. The batch record carries its own progress from that moment, so a
     * replay reads the current state instead of a stale copy.
     */
    public function completeForImport(IdempotencyKey $claim, SnapImport $import): void
    {
        $claim->update([
            'snap_import_id' => $import->id,
            'status' => IdempotencyKey::STATUS_COMPLETED,
        ]);
    }

    /**
     * Release the claim so a later retry can take it.
     *
     * A render that threw must not burn the key for the whole retention window.
     */
    public function release(IdempotencyKey $claim): void
    {
        $claim->update(['status' => IdempotencyKey::STATUS_FAILED]);
    }

    private function resolve(IdempotencyKey $existing): Snap|SnapImport
    {
        // Exactly one of the two is set on a completed claim. A batch claim is
        // read first, because only the batch surface writes that column.
        if ($existing->snap_import_id) {
            $import = $existing->snapImport;

            if (! $import) {
                throw new IdempotencyConflictException;
            }

            $import->wasRecentlyCreated = false;

            return $import;
        }

        $snap = $existing->snap;

        if (! $snap) {
            throw new IdempotencyConflictException;
        }

        // A queried model always reads false here. Say so explicitly, because
        // the callers bill on this flag.
        $snap->wasRecentlyCreated = false;

        return $snap;
    }

    private function reclaim(IdempotencyKey $existing): IdempotencyKey
    {
        // One conditional update decides the owner. One affected row means this
        // call reclaimed the key. Zero means another retry won it.
        $reclaimed = IdempotencyKey::query()
            ->where('id', $existing->id)
            ->where('status', IdempotencyKey::STATUS_FAILED)
            ->update(['status' => IdempotencyKey::STATUS_IN_PROGRESS]);

        if ($reclaimed !== 1) {
            throw new IdempotencyConflictException;
        }

        return $existing->refresh();
    }
}
