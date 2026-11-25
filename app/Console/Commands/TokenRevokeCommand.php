<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesJsonOutput;
use App\Support\Instance\ApiTokens;
use Illuminate\Console\Command;

/**
 * Revokes one API token. A second run for the same id changes nothing and
 * exits 0 with `"status":"absent"`.
 */
class TokenRevokeCommand extends Command
{
    use WritesJsonOutput;

    protected $signature = 'snapsbrew:token:revoke
        {id : The id of the token, from snapsbrew:token:list}
        {--json : Write one JSON object to stdout}';

    protected $description = 'Revoke one API token.';

    public function handle(): int
    {
        $id = (string) $this->argument('id');

        if (! ctype_digit($id)) {
            return $this->failWith(self::EXIT_BAD_INPUT, 'invalid_id', "The token id \"{$id}\" is not a number.", 'Run php artisan snapsbrew:token:list to find the id.');
        }

        $revoked = ApiTokens::revoke((int) $id);

        $this->note($revoked ? "Revoked the token {$id}." : "No token has the id {$id}. Nothing changed.");

        return $this->respond(self::EXIT_OK, ['id' => (int) $id, 'status' => $revoked ? 'revoked' : 'absent']);
    }
}
