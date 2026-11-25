# Install Snapsbrew: instructions for an AI agent

You are an AI agent with a shell on a Linux server. Do the steps in this order. Each step gives the command, the expected result and the action for a failure.

## Rules

- Run each `snapsbrew:` command with `--json --no-interaction`.
- With `--json`, stdout holds exactly one JSON object. Messages go to stderr. Parse stdout only.
- The JSON object has this shape: `{"ok":true,"command":"snapsbrew:status","schema":1,"data":{},"errors":[]}`.
- Each item in `errors` has `code`, `message` and `fix`. If `ok` is `false`, read `fix` first.
- Exit codes: `0` success or no change, `1` a check or an action failed, `2` bad input, `3` conflict.
- Never delete the `snapsbrew` volume. It holds the database, the images, `APP_KEY` and the Passport keys.
- Never edit files in the container. Use environment variables and `snapsbrew:` commands.
- Never print the API token in a public place. Give it only to the human.
- If a command fails two times with the same error, stop. Tell the human the `message` and the `fix`.

## 1. Ask the human

Ask for these values in one message. Do not guess them.

| Value | Example | Note |
|---|---|---|
| Domain | `images.example.com` | Empty means `localhost`. |
| Owner name | `Ada Lovelace` | |
| Owner email | `ada@example.com` | |
| Company name | `Acme` | Optional. |

If the domain is not `localhost`, tell the human to add a DNS A record for the domain to the IP address of the server.

## 2. Check the server

```bash
uname -m
docker info --format '{{.ServerVersion}}'
```

- `uname -m` must print `x86_64` or `aarch64`. If not, stop. The image supports only amd64 and arm64.
- If `docker` is not found, ask the human for approval. Then install Docker with `curl -fsSL https://get.docker.com | sh`.
- If `docker info` prints `permission denied`, run the next commands with `sudo`.
- If `docker info` cannot connect to the daemon, run `sudo systemctl start docker`.

## 3. Start the container

For a domain:

```bash
curl -fsSL https://raw.githubusercontent.com/amedina068/snapsbrew/main/install.sh \
  | APP_DOMAIN=images.example.com bash -s -- --non-interactive
```

For localhost:

```bash
curl -fsSL https://raw.githubusercontent.com/amedina068/snapsbrew/main/install.sh \
  | bash -s -- --non-interactive
```

- Exit code `0`: the app answers on `/up`. Go to step 4.
- Exit code `1`: stderr has an `Error:` line and a `Fix:` line. Do the fix, then run the command again.
- The installer writes a log to `/tmp/snapsbrew-install.log`. Read it if the `Fix:` line is not enough.
- To see each command before it runs, add `--dry-run`.

You can run the installer again. It replaces the container and keeps the volume.

## 4. Create the owner and an API token

```bash
docker exec snapsbrew php artisan snapsbrew:install \
  --name="Ada Lovelace" --email=ada@example.com --company="Acme" \
  --with-token=agent --json --no-interaction
```

Read these fields from `data`:

| Field | Use |
|---|---|
| `status` | `created` for a new owner. `exists` if an owner exists. Both exit with `0`. |
| `login_url` | A one-time login link for the human. |
| `token` | The API token for the MCP server and the REST API. |
| `connect.claude` | The `claude mcp add` command. |
| `connect.codex` | The `codex mcp add` command. |

- If `status` is `exists`, the command makes no new owner and no login link. Ask the human to log in with the existing account.
- The command does not accept `--password`. The owner sets a password after the first login.
- The command also adds the six starter templates to the company of the owner.

## 5. Check the instance

```bash
docker exec snapsbrew php artisan snapsbrew:doctor --json --no-interaction
```

`data` has a list of checks. Each check has `id`, `status`, `message` and `fix`. `status` is `ok`, `warn` or `fail`.

1. If a check has `fail`, run `snapsbrew:doctor --fix --json --no-interaction` one time. This applies only safe fixes.
2. Run `snapsbrew:doctor --json --no-interaction` again.
3. If a check still has `fail`, tell the human its `message` and its `fix`.
4. Tell the human about each `warn`. A `warn` does not stop the install.

For a short summary of the instance, run `snapsbrew:status --json --no-interaction`.

## 6. Connect the MCP server

Run the `connect.claude` command on the computer where the agent runs, not in the container. For Codex, use `connect.codex`.

```bash
claude mcp add --transport http snapsbrew https://images.example.com/mcp \
  --header "Authorization: Bearer <token>"
```

To show the command again, or to make a new token:

```bash
docker exec snapsbrew php artisan snapsbrew:mcp:connect --client=claude --json
docker exec snapsbrew php artisan snapsbrew:mcp:connect --client=claude --token=new --json
```

`--client` also accepts `codex`, `cursor` and `json`.

To test the connection, call the `whoami` tool. Then call `list-templates`. The six starter templates are in the list.

## 7. Report to the human

Give the human these items:

- The app URL: `https://<domain>`, or `http://localhost:8080`.
- The `login_url` from step 4. It works one time.
- The `connect.claude` command, if the human runs the agent on another computer.
- The results of step 5 that are not `ok`.
- This warning: "Back up the `snapsbrew` volume. It holds the database, the images and `APP_KEY`."

## Common failures

| Symptom | Action |
|---|---|
| `Port 80 is in use` or `Port 443 is in use` | Find the process with `sudo ss -ltnp`. Ask the human before you stop it. |
| `The container stopped.` | Run `docker logs --tail 50 snapsbrew`. Report the last error line. |
| The browser cannot open the domain | Run `getent hosts <domain>`. It must return the IP address of the server. If not, the DNS record is missing. |
| No HTTPS certificate | Make sure that ports 80 and 443 are open in the firewall. Caddy needs both. |
| The setup URL is lost | Run `docker exec snapsbrew php artisan snapsbrew:setup-url --json`. |
| A user cannot log in | Run `docker exec snapsbrew php artisan snapsbrew:user:password <email> --generate --json`. |
| A render fails | Run `snapsbrew:doctor --json --no-interaction`. Report the render check. |
