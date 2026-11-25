<?php

namespace App\Support\ApiDocs;

use App\Mcp\Servers\InstanceAdminServer;

/**
 * The "Install with an agent" and "Operate this instance" sections.
 *
 * `llms.txt` and `skill.md` both render these lines, so the two files name the
 * same commands. `AgentOperationsDocsTest` makes sure that each command named
 * here is a registered Artisan command.
 */
class AgentOperations
{
    /**
     * @return list<string>
     */
    public static function lines(): array
    {
        return [
            '## Install with an agent',
            '',
            'Run these commands on the server, in the app folder or with `docker exec <container>`. Each command accepts `--json` and `--no-interaction`.',
            'With `--json`, stdout holds one JSON object: `{"ok":true,"command":"...","schema":1,"data":{},"errors":[{"code":"...","message":"...","fix":"..."}]}`.',
            'The exit code is 0 for success or no change, 1 for a failed check or action, 2 for bad input and 3 for a conflict.',
            '',
            '1. `php artisan snapsbrew:install --name="Your Name" --email=you@example.com --with-token=agent --json` creates the owner. It returns a one-time `login_url`, an API token and a `connect` object. A second run returns `"status":"exists"`.',
            '2. Run the `connect.claude` line, or the `connect.env` and `connect.codex` lines, to connect the agent to `/mcp`.',
            '3. `php artisan snapsbrew:doctor --json` checks the renderer, a test render, the Passport keys, the queue, the scheduler and the storage. `--fix` runs only the safe fixes.',
            '',
            '## Operate this instance',
            '',
            '- `php artisan snapsbrew:status --json`: the version, the database, pending migrations, the queue and scheduler heartbeats, mail and MCP.',
            '- `php artisan snapsbrew:token:create --user=<email> --name=<name> --json`, `snapsbrew:token:list` and `snapsbrew:token:revoke <id>`: the API tokens.',
            '- `php artisan snapsbrew:mcp:connect --client=claude|codex|cursor|json [--token=new]`: the connect command or config for one client.',
            '- `php artisan snapsbrew:user:list --json`, `snapsbrew:user:password <email> --generate --json` and `snapsbrew:setup-url --json`: the users and the setup page.',
            '- The instance-admin MCP server runs over stdio: `ssh <host> docker exec -i <container> php artisan mcp:start '.InstanceAdminServer::HANDLE.'`.',
            '  It has tools for status, doctor, users, invitations, tokens, settings, the queue, recent errors, a test email and the starter templates.',
            '- These actions stay on the command line: create the owner, promote or demote an owner, rotate keys, revoke all tokens and delete data.',
            '',
        ];
    }
}
