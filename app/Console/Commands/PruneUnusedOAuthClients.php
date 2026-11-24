<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Passport\Passport;

/**
 * Deletes the OAuth clients that registered through `POST /oauth/register`
 * and never got an access token in 7 days.
 *
 * Registration takes no authentication, so a caller can create clients that
 * nobody uses. A registered client is public (no secret) and has no owner.
 * A client that has a secret or an owner is never deleted here.
 */
class PruneUnusedOAuthClients extends Command
{
    public const UNUSED_DAYS = 7;

    protected $signature = 'oauth-clients:prune';

    protected $description = 'Delete registered OAuth clients that got no access token in 7 days.';

    public function handle(): int
    {
        $deleted = 0;

        Passport::clientModel()::query()
            ->whereNull('secret')
            ->whereNull('owner_id')
            ->where('created_at', '<', now()->subDays(self::UNUSED_DAYS))
            ->whereDoesntHave('tokens')
            ->chunkById(500, function ($clients) use (&$deleted) {
                foreach ($clients as $client) {
                    $client->authCodes()->delete();
                    $client->delete();
                    $deleted++;
                }
            });

        $this->info("Deleted {$deleted} unused OAuth client(s).");

        return self::SUCCESS;
    }
}
