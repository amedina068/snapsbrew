<?php

namespace App\Services;

use App\Mcp\ToolError;
use RuntimeException;

/**
 * A claim that cannot resolve a readable package. The retryable flag and the
 * allowed detail keys come from the `ToolError` registry; other keys are dropped.
 */
class CreativePackageClaimException extends RuntimeException
{
    public readonly bool $retryable;

    /** @var array<string, mixed> */
    public readonly array $details;

    /** @param array<string, mixed> $details */
    public function __construct(public readonly string $codeName, string $message, array $details = [])
    {
        $registry = ToolError::CREATIVE_PACKAGE_ERRORS[$codeName];
        $this->retryable = $registry['retryable'];
        $this->details = array_intersect_key($details, array_flip($registry['details']));
        parent::__construct($message);
    }
}
