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

### MySQL mode (only if the human asks for it)

The default database is SQLite. Use MySQL 8.4, Redis and Browserless only if the human asks for them. Do these steps instead of the installer:

```bash
git clone https://github.com/amedina068/snapsbrew.git
cd snapsbrew
cp .env.docker.example .env
cat >> .env <<EOF
COMPOSE_PROFILES=mysql
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=snapsbrew
DB_USERNAME=snapsbrew
DB_PASSWORD=$(openssl rand -hex 24)
REDIS_HOST=redis
REDIS_PASSWORD=null
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
RENDER_DRIVER=browserless
BROWSERLESS_URL=http://browserless:3000
RENDER_ASSET_URL=http://web:8080
BROWSERLESS_TOKEN=$(openssl rand -hex 24)
EOF
docker compose up -d --build
```

- For a domain, add `APP_DOMAIN=<domain>` to `.env` before `docker compose up`. Also add `"80:80"` and `"443:443"` to the `ports` of the `web` service in `docker-compose.yml`.
- Run `docker compose ps` every 10 seconds. Continue when the `web` service shows `healthy`. The first start takes about 90 seconds.
- If `web` is not `healthy` after 5 minutes, run `docker compose logs --tail 50 web`. Report the last error line.
- In the next steps, replace `docker exec snapsbrew` with `docker compose exec -T web`. Run it in the `snapsbrew` folder.
- Never run `docker compose down -v`. It deletes the database volume.

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
| `connect.env` | The `export SNAPSBREW_TOKEN=...` line. It is the only line that holds the token. |
| `connect.claude` | The `claude mcp add` command. It reads the token from `SNAPSBREW_TOKEN`. |
| `connect.codex` | The `codex mcp add` command. It reads the token from `SNAPSBREW_TOKEN`. |

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

Run these commands on the computer where the agent runs, not in the container. First run the `connect.env` line. Then run the `connect.claude` line. For Codex, run `connect.codex` instead of `connect.claude`.

```bash
export SNAPSBREW_TOKEN='<token>'
claude mcp add --transport http snapsbrew https://images.example.com/mcp \
  --header "Authorization: Bearer ${SNAPSBREW_TOKEN}"
```

Codex reads `SNAPSBREW_TOKEN` each time that it starts. Keep the variable in the environment of Codex.

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
- The `connect.env` and `connect.claude` lines, if the human runs the agent on another computer.
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
| A company has no active owner | Run `docker exec snapsbrew php artisan snapsbrew:user:role <email> owner --json`. The doctor check `company_owners` gives the email. |
| A render fails | Run `snapsbrew:doctor --json --no-interaction`. Report the render check. |
