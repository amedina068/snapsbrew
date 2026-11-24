<?php

namespace App\Services;

use RuntimeException;

class CreativePackageSourcePreflightException extends RuntimeException
{
    /** @param array<int, array<string, mixed>> $details */
    public function __construct(public readonly string $codeName, public readonly array $details)
    {
        parent::__construct($codeName);
    }
}
