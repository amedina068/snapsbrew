<?php

namespace App\Services;

use RuntimeException;

/** A guarded write found no row for the presented fence: the token, the generation, the lease or the status moved. */
class StaleFenceException extends RuntimeException
{
    public function __construct(public readonly int $itemId)
    {
        parent::__construct("Creative package item {$itemId} is not held by this fence.");
    }
}
