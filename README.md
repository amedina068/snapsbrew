# Snapsbrew

A self-hosted image API. Design a template once in the browser editor. Then render it many times as an image or a PDF, with new text, images, colors or QR codes. A render can come from the REST API, a signed URL, a form, a spreadsheet or an AI agent.

```bash
docker run -d --name snapsbrew -p 8080:8080 -v snapsbrew:/data ghcr.io/amedina068/snapsbrew
docker logs -f snapsbrew
# Open the setup URL on the last line of the log.
```

You need no `.env` file, no keys and no database server. The setup page creates the owner account and adds six starter templates. Headless Chromium runs inside the container, so a render needs no outside service.

Snapsbrew is a Laravel 13 app with a Vue 3 editor.

## Features

- **Template editor.** Text, image, shape, SVG pattern, QR code and barcode layers, groups, custom fonts and brand tokens. Each publish freezes a template version.
- **REST API.** Render one snap, a collection, or a batch of up to 100 items. Sync and async modes, signed webhooks and idempotency keys. The repository has the [OpenAPI spec](docs/api/openapi.yaml) and a [Postman collection](docs/api/postman_collection.json).
- **Signed URLs.** Put a signed image URL in an `og:image` tag. The first request renders the image, and later requests read the cache.
- **Embedded forms and spreadsheet imports.** A form renders a template from the values that a person types. A CSV or XLSX file gives one snap per row.
- **Screenshots.** Capture a web page as an image.
- **MCP server.** Claude, Codex, Cursor and other agents list templates, preview a snap and render the final image. They connect with an API token or with OAuth 2.0.
- **Teams.** Invite people to your company. Two-factor authentication is available.

## Run in production

Point a DNS A record for your domain, for example `images.example.com`, to the server. Then run the installer on the server:

```bash
curl -fsSL https://raw.githubusercontent.com/amedina068/snapsbrew/main/install.sh | bash
```

The installer asks for one value, the domain. It checks Docker and the ports, starts the container, waits for the health check and shows the setup URL. Add `--dry-run` to see the commands first.

To start the container yourself, set `APP_DOMAIN` and publish ports 80 and 443:

```bash
docker run -d --name snapsbrew --restart unless-stopped \
  -p 80:80 -p 443:443 -p 443:443/udp \
  -e APP_DOMAIN=images.example.com \
  -v snapsbrew:/data \
  ghcr.io/amedina068/snapsbrew
```

HTTPS is automatic. Caddy gets the certificate on the first request. `APP_DOMAIN` is the only required variable. The app derives the URL, the cookie settings and the trusted hosts from it.

To put the app behind your own proxy, or to send mail, read [Reverse proxy and TLS](https://snapsbrew.com/docs/self-hosting/reverse-proxy-and-tls) and [Configuration](https://snapsbrew.com/docs/self-hosting/configuration).

A version tag (`v0.1.0` and later) publishes the image for `linux/amd64` and `linux/arm64`. Before the first tag, build the image from a clone: `docker build -t ghcr.io/amedina068/snapsbrew .`

## Install with an AI agent

Paste this prompt into Claude Code, Codex or another agent that has a shell on the server:

```text
Read https://raw.githubusercontent.com/amedina068/snapsbrew/main/INSTALL.md and install Snapsbrew on this server.
```

The agent installs the app, creates the owner and makes an API token. Its last step gives you two lines that connect your agent to the MCP server of the instance. The first line exports the token. The second line reads the token from that variable, so the token is not in the command:

```bash
export SNAPSBREW_TOKEN='<token>'
claude mcp add --transport http snapsbrew https://images.example.com/mcp \
  --header "Authorization: Bearer ${SNAPSBREW_TOKEN}"
```

To show the line again, run `docker exec snapsbrew php artisan snapsbrew:mcp:connect --client=claude`.

## MySQL, Redis and Browserless

The default database is SQLite in the `/data` volume. It is enough for one server. The Compose file has a `mysql` profile that adds MySQL 8.4, Redis and a separate Browserless renderer. In this mode, the web container runs Horizon.

1. Clone the repository:

   ```bash
   git clone https://github.com/amedina068/snapsbrew.git
   cd snapsbrew
   ```

2. Make the `.env` file. The command copies `.env.docker.example` and adds its MySQL block with two new passwords:

   ```bash
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
   ```

3. For a domain, add `APP_DOMAIN=images.example.com` to `.env`. Then add `"80:80"` and `"443:443"` to the `ports` of the `web` service in `docker-compose.yml`. For localhost, skip this step.
4. Build and start the stack:

   ```bash
   docker compose up -d --build
   docker compose logs -f web
   ```

5. Wait for the line `Snapsbrew is ready`. The next line is the setup URL. Open it and create the owner account.

On the first start, the web container waits for MySQL and loads the schema. This takes about 90 seconds. `COMPOSE_PROFILES=mysql` in `.env` starts the MySQL, Redis and Browserless containers, so `docker compose` needs no `--profile` flag. Read [Install with Docker](https://snapsbrew.com/docs/getting-started/installation-docker) for each service and each volume.

## Upgrade

Run the installer again. Or pull the image and start a new container with the same volume:

```bash
docker pull ghcr.io/amedina068/snapsbrew
docker rm -f snapsbrew
docker run -d --name snapsbrew ...   # the same command as before
```

The container runs the new migrations when it starts. Read the [release notes](https://snapsbrew.com/docs/getting-started/release-notes) before you upgrade.

## Backup

The `/data` volume holds all the data: the SQLite database, the rendered images, the uploads and the generated secrets.

```bash
docker stop snapsbrew
docker run --rm -v snapsbrew:/data -v "$PWD":/backup alpine \
  tar czf /backup/snapsbrew-$(date +%F).tar.gz -C /data .
docker start snapsbrew
```

> [!CAUTION]
> Do not lose the `/data` volume. It holds `APP_KEY` and the Passport keys. Without them, the app cannot read the encrypted data, and every session, setup link and MCP OAuth token stops working.

With MySQL, also back up the database with `mysqldump`. Read [Backups](https://snapsbrew.com/docs/self-hosting/backups).

## Operate

Each command accepts `--json` for scripts and agents.

| Command | What it does |
|---|---|
| `snapsbrew:status` | Shows the version, the database, the queue, the scheduler and the mail driver. |
| `snapsbrew:doctor` | Finds problems and gives the fix for each. `--fix` applies the safe fixes. |
| `snapsbrew:setup-url` | Shows the setup URL again. |
| `snapsbrew:user:password` | Sets a new password for a user. |
| `snapsbrew:starter-templates` | Adds the starter templates to the company of a user. |
| `snapsbrew:mcp:connect` | Prints the line that connects an AI agent. |

Run a command in the container: `docker exec snapsbrew php artisan snapsbrew:doctor`.

## Documentation

The documentation is at [snapsbrew.com/docs](https://snapsbrew.com/docs). The source is the `snapsbrew-docs` repository.

- [Configuration](https://snapsbrew.com/docs/self-hosting/configuration): all the environment variables.
- [Reverse proxy and TLS](https://snapsbrew.com/docs/self-hosting/reverse-proxy-and-tls): Cloudflare, NGINX, Traefik and `TRUSTED_PROXIES`.
- [Accounts and registration](https://snapsbrew.com/docs/self-hosting/accounts-and-registration): the owner, the invitations, and how to deactivate or delete a user.
- [Recover access](https://snapsbrew.com/docs/self-hosting/recover-access): a new password without mail.
- [REST API](https://snapsbrew.com/docs/api/overview) and [MCP server](https://snapsbrew.com/docs/mcp/overview).
- [ARCHITECTURE.md](ARCHITECTURE.md): how the code works.

## Development

You need PHP 8.3 or 8.4, Composer, Node 22 and a Chrome. SQLite is the default database.

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan snapsbrew:install --prepare
npm run build && php artisan serve
```

Then run `php artisan snapsbrew:setup-url` and open the URL. Run the tests with `composer test`, the code style check with `composer lint` and the JavaScript tests with `npm run test:unit`. Read [Development setup](https://snapsbrew.com/docs/contributing/development-setup) for the renderer settings.

Read [CONTRIBUTING.md](CONTRIBUTING.md) before you open a pull request. Report a security problem in private, as [SECURITY.md](SECURITY.md) describes. One person maintains this project, so answers are not immediate.

## License

Snapsbrew is free software under the [GNU Affero General Public License version 3](LICENSE) (`AGPL-3.0-only`). If you change the code and let people use the changed app over a network, you must offer those people the source code of your changed version.
