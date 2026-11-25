<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesJsonOutput;
use App\Models\User;
use App\Support\Instance\ApiTokens;
use App\Support\Instance\McpConnect;
use Illuminate\Console\Command;

/**
 * Prints the command or the config that connects an MCP client to `/mcp`.
 *
 * Without `--token=new`, the output reads the token from SNAPSBREW_TOKEN.
 * With `--token=new`, the command creates a token for the user and puts it in
 * the output.
 */
class McpConnectCommand extends Command
{
    use WritesJsonOutput;

    protected $signature = 'snapsbrew:mcp:connect
        {--client=claude : claude, codex, cursor or json}
        {--token= : Give "new" to create a token and put it in the output}
        {--user= : The email of the user for --token=new. The default is the first owner}
        {--json : Write one JSON object to stdout}';

    protected $description = 'Print the command or config that connects an MCP client to this instance.';

    public function handle(): int
    {
        $client = strtolower((string) $this->option('client'));

        if (! in_array($client, McpConnect::CLIENTS, true)) {
            return $this->failWith(self::EXIT_BAD_INPUT, 'invalid_client', "The client \"{$client}\" is not known.", 'Use --client=claude, codex, cursor or json.');
        }

        if (! config('mcp.enabled')) {
            return $this->failWith(self::EXIT_FAILED, 'mcp_disabled', 'The MCP server is off on this instance.', 'Set MCP_ENABLED=true and restart the app.');
        }

        $tokenOption = $this->option('token');
        $plainText = null;
        $token = null;

        if ($tokenOption !== null) {
            if ($tokenOption !== 'new') {
                return $this->failWith(self::EXIT_BAD_INPUT, 'invalid_token_option', 'The --token option takes only the value "new".', 'Run the command again with --token=new, or without --token.');
            }

            $user = $this->option('user')
                ? User::query()->where('email', $this->option('user'))->first()
                : User::query()->where('is_owner', true)->orderBy('id')->first();

            if (! $user) {
                return $this->failWith(self::EXIT_BAD_INPUT, 'user_not_found', 'No user matches for the new token.', 'Run php artisan snapsbrew:install first, or give --user=<email>.');
            }

            $created = ApiTokens::create($user, "mcp-{$client}");
            $plainText = $created->plainTextToken;
            $token = ApiTokens::describe($created->accessToken) + ['value' => $plainText];
        }

        $data = McpConnect::forClient($client, $plainText) + ['token' => $token];

        if (! $this->wantsJson()) {
            $this->renderText($data);
        }

        return $this->respond(self::EXIT_OK, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderText(array $data): void
    {
        if ($data['token'] === null && in_array($data['client'], ['claude', 'codex'], true)) {
            $this->line('# Set the token first. Create one with php artisan snapsbrew:token:create --name=agent.');
            $this->line('export '.McpConnect::TOKEN_ENV.'=<token>');
        } elseif ($data['env'] !== null) {
            $this->line($data['env']);
        }

        if (isset($data['command'])) {
            $this->line($data['command']);
        }

        if (isset($data['config_toml'])) {
            $this->newLine();
            $this->line('# Or add this to ~/.codex/config.toml:');
            $this->line(rtrim($data['config_toml']));
        }

        if (isset($data['config'])) {
            if (isset($data['path'])) {
                $this->line("# {$data['path']}");
            }
            $this->line((string) json_encode($data['config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if (isset($data['oauth'])) {
            $this->newLine();
            $this->line('# OAuth, with no token in the config:');
            $this->line($data['oauth']['command']);
            $this->line('# '.$data['oauth']['note']);
        }
    }
}
