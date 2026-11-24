# Snapsbrew

Snapsbrew renders images and PDFs from reusable templates. You design a template once in the browser editor. Then you render it many times with new text, images, colors or QR codes. A render can come from the REST API, a signed URL, an embedded form, a spreadsheet import or an AI agent over MCP.

Snapsbrew is a Laravel 13 app with a Vue 3 editor. It renders with headless Chrome through [Browserless](https://www.browserless.io/).

Documentation: https://snapsbrew.com/docs (source: the snapsbrew-docs repo)

One person maintains this project. Answers to issues and pull requests are not immediate.

## Features

- **Template editor.** Text, image, shape, SVG pattern and QR code layers, groups, custom fonts and brand tokens. Each publish freezes a template version.
- **REST API.** Render one snap, render a collection, or start a batch of up to 100 items. Sync and async modes, webhooks and idempotency keys. The docs at `/documentation` come from one spec, with an OpenAPI schema and a Postman collection. The repository also holds the [OpenAPI spec](docs/api/openapi.yaml), the [Postman collection](docs/api/postman_collection.json) and the [Postman environment](docs/api/postman_environment.json).
- **Signed URLs.** Put a signed image URL in an `og:image` tag. The first request renders the image and later requests read the cache.
- **Embedded forms.** Share a form that renders a template from the values that a person types.
- **Spreadsheet imports.** Upload a CSV or XLSX file and get one snap per row.
- **Screenshots.** Capture a web page as an image.
- **MCP server.** An AI agent (Claude, Cursor and others) can list templates, preview a snap, render the final image and export a creative package. It connects with an API token or with OAuth 2.0.
- **Usage log.** Each request and each render is in the usage log of the account.
- **Teams.** Invite people to your company. Two-factor authentication is available.

## Self-host with Docker

You need Docker with the Compose plugin. The stack has six containers: the web server, Horizon (the queue worker), the scheduler, MySQL 8.4, Redis and Browserless.

1. Clone the repository and go into its folder.
2. Copy the settings file:

   ```bash
   cp .env.docker.example .env
   ```

3. Generate the app key and the secrets:

   ```bash
   echo "APP_KEY=base64:$(openssl rand -base64 32)"
   echo "DB_PASSWORD=$(openssl rand -hex 16)"
   echo "DB_ROOT_PASSWORD=$(openssl rand -hex 16)"
   echo "BROWSERLESS_TOKEN=$(openssl rand -hex 24)"
   ```

4. Put the four values in `.env`.
5. Set `APP_URL` to the URL that people open, for example `https://images.example.com`.
6. Build and start the stack:

   ```bash
   docker compose up -d --build
   ```

7. Wait until the `web` container is healthy. The first start runs every migration and can take some minutes:

   ```bash
   docker compose ps
   ```

8. Read the setup URL in the log of the `web` container:

   ```bash
   docker compose logs web
   ```

9. Open the setup URL and create the owner account. Then the app signs you in.

The setup page has the checkbox **Add the starter templates**, which is on by default. When it is on, Horizon adds six starter templates to the company of the owner within a minute.

The setup page is open only while the instance has no account. The URL holds a token that comes from `APP_KEY`. If you lose the URL, run `docker compose exec web php artisan snapsbrew:setup-url`.

To create the owner from the command line instead of the setup page, run `docker compose exec web php artisan snapsbrew:install`.

The app answers on port 8080 of the loopback interface (`127.0.0.1`) of the host. Set `APP_PORT` to change the port. To take traffic from other hosts with no proxy, set `APP_BIND=0.0.0.0`.

To serve HTTPS, put a reverse proxy (Caddy, Traefik or NGINX) on the host in front of the app. The [`deploy/`](deploy/README.md) folder has example files for Caddy and NGINX. Then set `TRUSTED_PROXIES` to the address of each proxy that sends requests to the app:

- For a proxy on the same host, use the gateway address of the Docker network. Requests from the host reach the app from this address. To find it, run `docker network inspect <project>_default --format '{{(index .IPAM.Config 0).Gateway}}'`.
- If Cloudflare is in front of the proxy, also add the Cloudflare ranges. Copy the current list from https://www.cloudflare.com/ips/. Then make sure that the proxy adds the address of Cloudflare to `X-Forwarded-For`.

Do not trust the whole Docker range. The other containers, for example Browserless, are in that range too.

```dotenv
TRUSTED_PROXIES=172.18.0.1,173.245.48.0/20,103.21.244.0/22,103.22.200.0/22,103.31.4.0/22,141.101.64.0/18,108.162.192.0/18,190.93.240.0/20,188.114.96.0/20,197.234.240.0/22,198.41.128.0/17,162.158.0.0/15,104.16.0.0/13,104.24.0.0/14,172.64.0.0/13,131.0.72.0/22,2400:cb00::/32,2606:4700::/32,2803:f800::/32,2405:b500::/32,2405:8100::/32,2a06:98c0::/29,2c0f:f248::/32
```

Do not set `TRUSTED_PROXIES` to `*`. With `*`, the app trusts the `X-Forwarded-For` header of any client, so a client can choose its own IP and pass the limits for each IP.

The app answers 400 to a request for a host that is not the host of `APP_URL`, a host of `APP_PREVIOUS_URLS` or a loopback host. The proxy must send the original `Host` header, or send `X-Forwarded-Host` from a trusted address.

### Use the published image

Each release publishes a Docker image to the GitHub Container Registry, for `linux/amd64` and `linux/arm64`. With the image, you do not need to clone the repository or build:

1. Put `docker-compose.image.yml` and `.env.docker.example` in an empty folder.
2. Do steps 2 to 5 above.
3. Start the stack:

   ```bash
   docker compose -f docker-compose.image.yml up -d
   ```

4. Do steps 7 to 9 above. Add `-f docker-compose.image.yml` to each `docker compose` command.

The default tag is `latest`. To stay on one version, set `SNAPSBREW_VERSION` in `.env`, for example `SNAPSBREW_VERSION=0.1.0`.

### What the stack does

- The `web` container runs the migrations, the storage link and the Laravel caches each time that it starts (serversideup `AUTORUN`).
- Then the `web` container creates the Passport keys if they are missing. While no account exists, it prints the setup URL to its log.
- The health check of `web` reads `GET /up`. Horizon and the scheduler start after `web` is healthy.
- The `storage` volume keeps the rendered images, the uploads, the Passport keys and the logs. The `mysql` and `redis` volumes keep the data.
- The renderer is Browserless. The app sends the snap HTML to it inside the Compose network.

### Update

```bash
git pull
docker compose up -d --build
```

With the published image, run `docker compose -f docker-compose.image.yml pull`, then `docker compose -f docker-compose.image.yml up -d`.

The `web` container runs the new migrations when it starts. Make a backup of the `mysql` volume before you update.

### Known limit

Browserless renders inside its own container. If a template loads an image from your instance, Browserless must reach that image through `APP_URL`. A public domain works. A `localhost` URL does not work for those images.

## Configuration

`.env.example` lists every setting with a comment. These are the settings that a self-hosted instance uses most:

| Setting | Default | What it does |
|---|---|---|
| `APP_URL` | | The public URL. Links, API docs and signed URLs use it. |
| `REGISTRATION_ENABLED` | `false` | The default registration mode. `false` is invite only and `true` is open. The owner can change the mode in the admin area. |
| `MCP_ENABLED` | `true` | When it is `false`, the app has no `/mcp` route and no OAuth route. |
| `SNAPSBREW_PREVIEW_WATERMARK` | `true` | Shows a "PREVIEW" overlay on REST and MCP previews. |
| `RENDER_DRIVER` | `browserless` in Docker | `browserless` or `browsershot` (a local Chrome through Node). |
| `MEDIA_DISK` | `public` | Where the rendered images go: `public`, `s3`, `spaces` or `r2`. |
| `MCP_REDIRECT_DOMAINS` | `https://claude.ai,https://claude.com` | The hosts that can receive an OAuth code. |
| `ROLLBAR_TOKEN` | | Sends errors to Rollbar when it is set. |
| `SNAPSBREW_PRIVACY_URL`, `SNAPSBREW_TERMS_URL` | | Your legal pages. `llms.txt` links them. |

The app ships no privacy policy and no terms of service. Write your own pages if your instance is public.

### Free tools API

The app has no tools web pages. The marketing site at snapsbrew.com serves the free tools pages and their forms.

The forms call four public endpoints from the browser. These endpoints take no token. They return 404 until `SNAPSBREW_FREE_TOOLS_ENABLED=true`:

- `POST /api/v1/tools/certificate-creator`
- `POST /api/v1/tools/twitter-graph-image-creator`
- `POST /api/v1/tools/testimonial-image-creator`
- `POST /api/v1/tools/screenshot-creator`

The screenshot endpoint stores each screenshot under the user that `SNAPSBREW_FREE_TOOLS_USER_ID` names. If that user does not exist, the endpoint returns 503.

Each template tool renders one template that you choose. The request cannot choose the template, and it can change only the fixed fields of the tool. Set the uuid of each template:

| Variable | Tool | Fields that the request can change |
|---|---|---|
| `SNAPSBREW_FREE_TOOLS_CERTIFICATE_TEMPLATE` | certificate | `awardee`, `awarder`, `date`, `description` |
| `SNAPSBREW_FREE_TOOLS_TESTIMONIAL_TEMPLATE` | testimonial | `author`, `testimonial` |
| `SNAPSBREW_FREE_TOOLS_TWITTER_GRAPH_TEMPLATE` | Twitter graph | `title`, `website`, and the `image` upload (2 MB maximum) |

If a template variable is empty or names no template, that tool returns 503.

One IP can send 5 requests each minute and 50 requests each day to the free tools. Change the limits with `SNAPSBREW_FREE_TOOLS_REQUESTS_PER_MINUTE_PER_IP` and `SNAPSBREW_FREE_TOOLS_REQUESTS_PER_DAY_PER_IP`.

An instance without a marketing site can ignore these endpoints.

### The owner

The owner runs the instance. Only the owner can open Horizon at `/horizon` and the admin area at `/admin`, in every environment, `local` included. The setup page at `/setup` or the `snapsbrew:install` command creates the owner. To make an existing user the owner, run:

```bash
docker compose exec web php artisan snapsbrew:install --promote=person@example.com
```

An instance can have more than one owner. To remove the owner flag from a user, run the command with `--demote`. The command refuses to demote the last owner:

```bash
docker compose exec web php artisan snapsbrew:install --demote=person@example.com
```

### Starter templates

Six starter templates help a new account start: a link preview (1200x630), a quote card (1080x1080), a certificate (A4 landscape), a product launch post (1080x1350), a blog header (1600x900) and a YouTube thumbnail (1280x720). They use no images. Their fonts come from Google Fonts, under the SIL Open Font License.

The setup page and `snapsbrew:install` offer them. To add them to the company of a user later, run:

```bash
docker compose exec web php artisan snapsbrew:starter-templates --user=person@example.com
```

Without `--user`, the command uses the owner. The command is safe to run again: it skips each starter template that the company already has, and it adds back a starter template that was deleted or renamed.

### Accounts and registration

The instance has two registration modes:

- **Invite only.** Only a person with an invitation from a company can sign up. The person joins that company.
- **Open.** Anyone can sign up. Each new account gets its own company.

In both modes, an invitation works. `REGISTRATION_ENABLED` gives the mode until the owner sets one. To change the mode, open **Admin > Settings**. The mode that the owner sets stays in the database, and it wins over `REGISTRATION_ENABLED`.

### Deactivate a user

The owner can deactivate a user in **Admin > Users**. A deactivated user keeps all data, but cannot authenticate:

- The login form refuses the user and shows the reason.
- An open web session ends at the next request.
- API tokens, OAuth tokens of the MCP server, signed URLs and embedded forms of the user stop working.

To restore access, click **Reactivate**. The tokens and signed URLs of the user work again. The owner cannot deactivate an owner.

### Delete a user

For a privacy request, click **Delete** in **Admin > Users**. You cannot undo a delete. It removes:

- The account, the profile image and the sessions of the user.
- The API tokens, the signed URL keys and the OAuth tokens of the user.
- The usage log rows, the activity log rows and the screenshots of the user.

The templates, snaps and forms belong to the company, so they stay while the company has another member. If the user is the last member of the company, the delete also removes the company and all of its content. The owner cannot delete an owner.

### Recover access

The "forgot password" form sends an email. If your instance has no SMTP, use these commands to recover an account.

Find the user:

```bash
docker compose exec web php artisan snapsbrew:user:list --search=person
```

Set a new password. The command asks for the password two times:

```bash
docker compose exec web php artisan snapsbrew:user:password person@example.com
```

- To set the password from a script, add `--password=<new password>`.
- To get a random password, add `--generate`. The command shows the password one time.
- If the user lost the two-factor device, add `--reset-two-factor`. This option turns off two-factor authentication for the user.

The new password must obey the same rules as the web form. The command also ends the "Remember me" logins of the user. With the `database` session driver, it removes the web sessions of the user. With other drivers, each web session ends at its next request.

## MCP setup

The MCP server is at `APP_URL/mcp`. An agent can connect in two ways.

**With an API token.** This path needs no OAuth configuration.

1. Open **Account > Developers** and create an API token.
2. Add the server to your MCP client with the token as a bearer token. The page shows a configuration example.

**With OAuth 2.0.** Connector directories, for example Claude, use this path.

1. Make sure that the Passport keys exist. The `web` container and `snapsbrew:install` create them in `storage/` when they are missing. You can also run `php artisan passport:keys`, or set `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY`.
2. Set `MCP_REDIRECT_DOMAINS` to the hosts of the connectors that you accept. `POST /oauth/register` is open to every client, so this list controls where an authorization code can go.
3. Add `APP_URL/mcp` as a custom connector in your client. The client reads the discovery documents and opens the consent screen.

`POST /oauth/register` allows 3 registrations each minute and 20 each day for one IP. A registration can have 5 redirect URIs or fewer, each 2000 characters or fewer, and a body of 16 KB or less. The scheduler runs `oauth-clients:prune` every day. This command deletes each registered client that got no access token in 7 days.

The guide at `/documentation/guides/mcp` and the agent workflow at `/skill.md` describe the tools.

## Local development

You need PHP 8.3 or 8.4, Composer, Node 22 and a Chrome. SQLite is the default database.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan snapsbrew:install --prepare
npm run build
php artisan serve
```

`.env.example` sets `APP_ENV=production` and `APP_DEBUG=false`. For local development, set `APP_ENV=local` and `APP_DEBUG=true` in `.env`.

Then open `/` and create the owner account on the setup page. The page asks for a setup token. Run `php artisan snapsbrew:setup-url` to get the URL with the token. To create the owner in the terminal instead, run `php artisan snapsbrew:install` without `--prepare`.

To get demo data instead of an empty instance, run `php artisan migrate:fresh --seed`. The seed deletes all data. It creates demo companies, templates and snaps, and the owner account `admin@example.com` with the password `password`. Then sign in at `/login`. The setup page stays closed, because the instance has users.

The default renderer for local development is `browsershot`. It starts Chrome through Node and Puppeteer:

- If Node is not at `/usr/local/bin/node`, set `BROWSERSHOT_NODE_PATH` to the output of `which node`. Set `BROWSERSHOT_NPM_PATH` to the output of `which npm`.
- If Puppeteer did not download a Chrome, set `PUPPETEER_EXECUTABLE_PATH` to the path of a local Chrome.
- To use Browserless instead, start `deploy/browserless/docker-compose.yml` and set `RENDER_DRIVER=browserless`.

With `QUEUE_CONNECTION=sync`, each job runs in the request, so you do not need a queue worker. With `sync`, `php artisan migrate` does not render the preview images of the starter templates. The templates still render snaps.

Run the tests and the code style check:

```bash
composer test
composer lint
npm run test:unit
```

`composer test` runs the tests in parallel processes. `vendor/bin/phpunit` runs them in one process. `npm run test:unit` runs the JavaScript unit tests with Vitest.

Read [CONTRIBUTING.md](CONTRIBUTING.md) before you open a pull request.

## License

Snapsbrew is free software under the [GNU Affero General Public License version 3](LICENSE) (`AGPL-3.0-only`). If you change the code and let people use the changed app over a network, you must offer those people the source code of your changed version.
