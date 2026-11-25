<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesJsonOutput;
use App\Models\User;
use App\Support\Instance\ApiTokens;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Lists the API tokens of the instance. It never shows a token value.
 */
class TokenListCommand extends Command
{
    use WritesJsonOutput;

    protected $signature = 'snapsbrew:token:list
        {--user= : Show only the tokens of the user with this email}
        {--json : Write one JSON object to stdout}';

    protected $description = 'List the API tokens. The values are never shown.';

    public function handle(): int
    {
        $query = PersonalAccessToken::query()->with('tokenable')->orderBy('id');

        if ($email = $this->option('user')) {
            $user = User::query()->where('email', $email)->first();

            if (! $user) {
                return $this->failWith(self::EXIT_BAD_INPUT, 'user_not_found', "No user has the email {$email}.", 'Run php artisan snapsbrew:user:list to find the email.');
            }

            $query->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id);
        }

        $tokens = $query->get()->map(fn (PersonalAccessToken $token) => ApiTokens::describe($token))->values()->all();

        if ($this->wantsJson()) {
            return $this->respond(self::EXIT_OK, ['tokens' => $tokens]);
        }

        if ($tokens === []) {
            $this->components->info('No token matches.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'User', 'Abilities', 'Last used', 'Expires'],
            array_map(fn (array $token) => [
                $token['id'],
                $token['name'],
                $token['user']['email'] ?? '',
                implode(', ', $token['abilities']),
                $token['last_used_at'] ?? 'never',
                $token['expires_at'] ?? 'never',
            ], $tokens),
        );

        return self::SUCCESS;
    }
}
