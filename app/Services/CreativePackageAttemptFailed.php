<?php

namespace App\Services;

use Illuminate\Contracts\Debug\ShouldntReport;

/**
 * The one exception ProcessCreativePackageItem lets escape. It carries a
 * fixed sentence and no previous exception, so the worker report and the
 * failed_jobs row hold no content of the original message. The job writes
 * the class of the original under the same correlation id first. The
 * framework retries it like any other exception.
 */
final class CreativePackageAttemptFailed extends \RuntimeException implements ShouldntReport
{
    public function __construct(public readonly int $itemId, public readonly string $correlationId)
    {
        parent::__construct("Creative package item {$itemId} attempt {$correlationId} failed. The log line with that correlation id names the exception class.");
    }
}
