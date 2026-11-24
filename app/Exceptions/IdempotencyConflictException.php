<?php

namespace App\Exceptions;

use Exception;

/**
 * The idempotency key cannot be used for this request.
 *
 * `errorCode` is a stable identifier that the REST body and the MCP error
 * both carry, so a client can tell the cases apart without reading prose:
 *
 * - `idempotency_key_in_progress`: another request holds the key right now.
 *   The caller can retry with the same key. Nothing was created.
 * - `idempotency_key_belongs_to_snap`: a single snap owns the key. A batch
 *   request must use a new key.
 * - `idempotency_key_belongs_to_batch`: a batch owns the key. A single snap
 *   request must use a new key.
 */
class IdempotencyConflictException extends Exception
{
    public const IN_PROGRESS = 'idempotency_key_in_progress';

    public const BELONGS_TO_SNAP = 'idempotency_key_belongs_to_snap';

    public const BELONGS_TO_BATCH = 'idempotency_key_belongs_to_batch';

    public function __construct(
        string $message = 'A request with this idempotency_key is already in progress. Retry in a moment. Nothing was created and nothing was charged.',
        public readonly string $errorCode = self::IN_PROGRESS,
    ) {
        parent::__construct($message);
    }

    public static function belongsToSnap(): self
    {
        return new self(
            'This idempotency_key already belongs to a single snap. Use a new key for a batch.',
            self::BELONGS_TO_SNAP,
        );
    }

    public static function belongsToBatch(): self
    {
        return new self(
            'This idempotency_key already belongs to a batch. Use a new key for a single snap.',
            self::BELONGS_TO_BATCH,
        );
    }
}
