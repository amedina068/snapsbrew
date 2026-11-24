<?php

namespace App\Support;

/**
 * Canonical queue names. Routing and the Horizon supervisor both read from
 * here so a queue can never be dispatched to a name Horizon does not process
 * (a typo would otherwise leave jobs sitting unworked forever).
 *
 * Keep ALL names in sync with the supervisor `queue` list in
 * `config/horizon.php`.
 */
final class Queues
{
    /** Interactive renders — a user is waiting (REST sync-async, MCP, embed, onboarding). */
    public const RENDERS = 'renders';

    /** Bulk import batches — high volume, must not starve interactive renders. */
    public const IMPORTS = 'imports';

    /** Queued mail. */
    public const MAIL = 'mail';

    /** Cleanup and catch-all. */
    public const DEFAULT = 'default';

    /**
     * Priority-ordered list (highest first) for the Horizon supervisor.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [self::RENDERS, self::IMPORTS, self::MAIL, self::DEFAULT];
    }
}
