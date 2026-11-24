<?php

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use Illuminate\Console\Command;

/**
 * Deletes idempotency keys past their retention window.
 *
 * The window is a promise the API documentation makes. A documented window that
 * nothing enforces is a false statement on a money path, so this command is not
 * housekeeping. It is what makes the documented window true.
 */
class PruneIdempotencyKeys extends Command
{
    /** Matches the window stated in `ApiSpec.php` for `idempotency_key`. */
    public const RETENTION_HOURS = 24;

    protected $signature = 'snaps:prune-idempotency-keys';

    protected $description = 'Delete idempotency keys older than the documented retention window.';

    public function handle(): int
    {
        $cutoff = now()->subHours(self::RETENTION_HOURS);
        $deleted = 0;

        // chunkById, not a single delete with a join. The same rule as every
        // other bulk write in this project: one query plan that runs on MySQL
        // and on SQLite alike.
        IdempotencyKey::query()
            ->where('created_at', '<', $cutoff)
            ->chunkById(1000, function ($rows) use (&$deleted) {
                $deleted += IdempotencyKey::query()
                    ->whereIn('id', $rows->pluck('id'))
                    ->delete();
            });

        $this->info("Deleted {$deleted} idempotency key(s) older than ".self::RETENTION_HOURS.' hours.');

        return self::SUCCESS;
    }
}
