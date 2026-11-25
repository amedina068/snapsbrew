<?php

namespace App\Support\Instance;

/**
 * The commands and the config that connect an MCP client to `/mcp`.
 *
 * `snapsbrew:install --with-token` and `snapsbrew:mcp:connect` print these
 * lines. The Claude, Codex and Cursor lines read the token from the
 * `SNAPSBREW_TOKEN` environment variable, so the token is not in the command
 * text or in the shell history. When the token is known, the `env` line
 * exports it. Only the generic `json` config carries the token itself.
 *
 * Codex syntax (codex-cli 0.166): `codex mcp add <name> --url <url>
 * --bearer-token-env-var <VAR>`. Codex reads the token from that variable
 * each time it starts, so the variable must be set in its environment.
 */
class McpConnect
{
    public const SERVER_NAME = 'snapsbrew';

    public const TOKEN_ENV = 'SNAPSBREW_TOKEN';

    public const CLIENTS = ['claude', 'codex', 'cursor', 'json'];

    public static function url(): string
    {
        return rtrim((string) config('app.url'), '/').'/mcp';
    }

    /**
     * The `connect` object of `snapsbrew:install --with-token`.
     *
     * @return array<string, mixed>
     */
    public static function summary(?string $token): array
    {
        return [
            'mcp_url' => self::url(),
            'token_env' => self::TOKEN_ENV,
            'env' => self::envLine($token),
            'claude' => self::claude(),
            'codex' => self::codex(),
            'claude_oauth' => self::claudeOAuth(),
        ];
    }

    /**
     * The connect data for one client.
     *
     * @return array<string, mixed>
     */
    public static function forClient(string $client, ?string $token): array
    {
        $data = [
            'client' => $client,
            'mcp_url' => self::url(),
            'token_env' => self::TOKEN_ENV,
            'env' => self::envLine($token),
        ];

        return $data + match ($client) {
            'claude' => [
                'command' => self::claude(),
                'oauth' => [
                    'command' => self::claudeOAuth(),
                    'available' => self::oauthAvailableForLocalClients(),
                    'note' => self::oauthAvailableForLocalClients()
                        ? 'Claude Code opens a browser to sign in. Use this command when you do not want a token in the config.'
                        : 'This instance does not accept the localhost callback of Claude Code. Add http://localhost to MCP_REDIRECT_DOMAINS to use OAuth, or use the token command.',
                ],
            ],
            'codex' => [
                'command' => self::codex(),
                'config_toml' => self::codexToml(),
            ],
            'cursor' => [
                'path' => '~/.cursor/mcp.json',
                'config' => ['mcpServers' => [self::SERVER_NAME => [
                    'url' => self::url(),
                    'headers' => ['Authorization' => 'Bearer ${env:'.self::TOKEN_ENV.'}'],
                ]]],
            ],
            // json: a generic `mcpServers` entry for any client.
            default => [
                'config' => ['mcpServers' => [self::SERVER_NAME => [
                    'type' => 'http',
                    'url' => self::url(),
                    'headers' => ['Authorization' => 'Bearer '.($token ?? '${'.self::TOKEN_ENV.'}')],
                ]]],
            ],
        };
    }

    public static function claude(): string
    {
        return 'claude mcp add --transport http '.self::SERVER_NAME.' '.self::url()
            .' --header "Authorization: Bearer ${'.self::TOKEN_ENV.'}"';
    }

    public static function claudeOAuth(): string
    {
        return 'claude mcp add --transport http '.self::SERVER_NAME.' '.self::url()
            .' && claude mcp login '.self::SERVER_NAME;
    }

    public static function codex(): string
    {
        return 'codex mcp add '.self::SERVER_NAME.' --url '.self::url().' --bearer-token-env-var '.self::TOKEN_ENV;
    }

    public static function codexToml(): string
    {
        return '[mcp_servers.'.self::SERVER_NAME."]\n"
            .'url = "'.self::url()."\"\n"
            .'bearer_token_env_var = "'.self::TOKEN_ENV."\"\n";
    }

    private static function envLine(?string $token): ?string
    {
        return $token === null ? null : 'export '.self::TOKEN_ENV.'='.escapeshellarg($token);
    }

    /**
     * Claude Code and Codex receive the OAuth code on a localhost callback.
     * `/oauth/register` accepts that callback only when MCP_REDIRECT_DOMAINS
     * names localhost (or `*`).
     */
    private static function oauthAvailableForLocalClients(): bool
    {
        foreach ((array) config('mcp.redirect_domains', []) as $domain) {
            $host = rtrim((string) preg_replace('#^[a-z]+://#i', '', (string) $domain), '/');

            if (in_array($host, ['*', 'localhost', '127.0.0.1', '[::1]'], true)) {
                return true;
            }
        }

        return false;
    }
}
