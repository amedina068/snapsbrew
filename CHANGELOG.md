# Changelog

All notable changes to this project are in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

This file lists the changes to the code for each release. The release notes for users are on the [release notes page](https://snapsbrew.com/docs/getting-started/release-notes) of the docs.

## [Unreleased]

### Added

- `php artisan snapsbrew:user:role <email> owner|member [--json]` sets the company role of a user. It uses the same rule as **Account > Users**. The exit code is 2 for bad input and 3 when the user is the last active owner of the company.
- A company owner can remove a user from **Account > Users**. The page asks for a confirmation first. The removal deactivates the account with the same rule as **Admin > Users**. The user cannot sign in, and the API tokens, OAuth connections and signed URLs of the user stop working. The templates and snaps of the user stay in the company. The last active owner and the owner of the instance cannot be removed. A member cannot remove users.
- `FontPolicy::create` decides who can upload a font. Each active user of the company, owner or member, can upload a font. `POST /fonts` now checks this policy.
- `snapsbrew:doctor` has the check `company_owners`. It warns about each company that has active users but no active owner. The fix line gives a `snapsbrew:user:role` command for each company.

### Fixed

- A company that has active users always keeps an active owner. **Admin > Users** refuses to deactivate the last active owner of such a company. **Account > Users** refuses to make the last active owner a member. A deactivated owner does not count as an owner for these rules.
- The `company_role` migration skips a deactivated founder. It makes the active user with the lowest id the owner. A delete of the last owner also makes the oldest active user the owner.

- `snapsbrew:doctor --fix --json` keeps its output one JSON object when it creates the `public/storage` link or the Passport keys. Before, a caller that ran the command in the same process with `Artisan::call()` read the line of `storage:link` instead of the JSON object. The repair now runs `storage:link` and `passport:keys` with no output.
- A new account gets a copy of the `[Onboarding] Showcase` template. This copy now gets a published version and a queued `GenerateTemplatePreview` job, as a starter template does. Before, the copy had no published version and no preview image, and the template list showed "The preview is on its way." for it with no end.
- In the Docker image, Chromium keeps the fontconfig cache in `/var/www/.cache/fontconfig`. The build fills this folder with `fc-cache`. The check `fonts_cache` of `snapsbrew:doctor` reads the same folder and now reports `ok`.

## [0.1.0] - 2026-11-25

The first public release.

### Added

- The Docker image runs all of Snapsbrew in one container with no settings: `docker run -d -p 8080:8080 -v snapsbrew:/data ghcr.io/amedina068/snapsbrew`. s6 runs the web server, `queue:work`, the scheduler and a headless Chromium. The `/data` volume holds the SQLite database, the app key (made on the first start), the uploads and the rendered images. The last log line of the boot is the URL to open.
- `APP_DOMAIN` sets the app URL to `https://<domain>`. In the Docker image, Caddy then gets a certificate and serves the ports 80 and 443.
- `database/schema` holds a schema dump for MySQL and for SQLite. A new MySQL database loads it in about 2 seconds instead of running 85 migrations. `scripts/dump-schemas.sh` writes the two files again.

- Each `snapsbrew:*` command accepts `--json`. With `--json`, stdout holds one JSON object (`ok`, `command`, `schema`, `data`, `errors`). The exit code is 0 for success, 1 for a failed check, 2 for bad input and 3 for a conflict.
- `php artisan snapsbrew:install` returns a one-time `login_url` and does not need `--password`. `--with-token=<name>` also returns an API token and ready `claude mcp add` and `codex mcp add` lines. `--password` still works, but it is deprecated.
- New commands: `snapsbrew:status`, `snapsbrew:doctor [--fix]`, `snapsbrew:token:create`, `snapsbrew:token:list`, `snapsbrew:token:revoke` and `snapsbrew:mcp:connect --client=claude|codex|cursor|json`.
- An instance-admin MCP server with 16 tools runs over stdio: `php artisan mcp:start snapsbrew-admin`. With `MCP_ADMIN_ENABLED=true`, `/mcp/admin` also serves it to an owner with a short-lived `instance_admin` token. Each call writes an audit entry.
- The scheduler writes a heartbeat each minute, and a queue worker writes a second one. `snapsbrew:status` and `snapsbrew:doctor` read them.
- `/llms.txt` and `/skill.md` have the sections "Install with an agent" and "Operate this instance".
- `composer analyse` runs Larastan at level 5, and the CI runs it on each pull request. `phpstan-baseline.neon` holds the errors that existed before.
- The template page has a **Delete template** button with a confirmation.
- **Admin > Users** has a **Delete** button next to **Reactivate** and **Deactivate**. It deletes the user and the personal data of the user. If the user is the last member of the company, it also deletes the company and its content.
- `php artisan snapsbrew:install --demote=<email>` removes the owner flag from a user. It refuses to demote the last owner.
- The collection page has a **Remove from collection** button on each template and a **Delete collection** button. Both ask for a confirmation. A collection delete also deletes the forms of the collection. The templates stay.
- Each webhook carries `X-Snapsbrew-Delivery`, `X-Snapsbrew-Timestamp` and `X-Snapsbrew-Signature`. The signature is the HMAC-SHA256 of `{timestamp}.{body}` with the secret of the company. **Account > Developers** shows the secret and rotates it.
- An async snap that fails to render posts a `snap.failed` webhook. The snap in the body has `status: failed` and `last_error: render_failed`.
- A snap renders with no internet access. The renderer embeds the Google fonts of the snap in its HTML. The fonts of the starter templates and the onboarding templates ship in `resources/fonts`. The first render with another Google font downloads it one time to `storage/app/fonts`.
- A snap has a `warnings` list. If the server cannot load a font, the render continues, logs a warning and adds a `font_unavailable` item to `warnings`.
- `RENDER_ASSET_URL` sets the app address that the render browser uses for the images and the fonts of a snap on the `public` disk. The host of this address is a trusted host.
- `BROWSERSHOT_NODE_MODULES_PATH`, `BROWSERSHOT_CHROME_PATH` and `BROWSERSHOT_NO_SANDBOX` configure the Browsershot renderer.
- Each pending invitation on **Account > Users** has a **Copy invite link** button. After a new invitation, the page shows the invite link.
- When `MAIL_MAILER` is `log`, the invitation pages say "Mail is not set up. Send this link yourself." The onboarding wizard shows the links too.
- `/setup` without a correct token shows how to get the setup URL (`docker logs snapsbrew` or `php artisan snapsbrew:setup-url`).
- `install.sh` installs Snapsbrew on a server with Docker in one command. `INSTALL.md` gives the same steps to an AI agent.
- Each user has a role in the company: `owner` or `member`. **Account > Users** and **Admin > Users** show the role. `snapsbrew:user:list` and the `list-users` admin tool return it as `company_role`. An owner can make a member an owner, or make another owner a member. The last owner of a company stays an owner.
- Template editor in the browser with text, image, shape, SVG pattern, QR code and barcode layers, groups, custom fonts and brand tokens. Each publish freezes a template version.
- REST API to render one snap, a collection or a batch of up to 100 items, in sync or async mode, with webhooks and idempotency keys. The API docs at `/documentation` come from one spec, with an OpenAPI schema and a Postman collection.
- Signed image URLs for `og:image` tags. The first request renders the image and later requests read the cache.
- Embedded forms that render a template from the values that a person types.
- Spreadsheet imports: one snap for each row of a CSV or XLSX file.
- Screenshots of web pages.
- MCP server for AI agents, with API token or OAuth 2.0 access, design guidance tools and creative packages.
- Usage log of each request and each render.
- Teams with invitations and two-factor authentication.
- Self-hosting with Docker Compose: web, Horizon, scheduler, MySQL, Redis and Browserless.
- A Docker image on GHCR for each release, for `linux/amd64` and `linux/arm64`, and `docker-compose.image.yml` to run it without a build.
- First-run setup page at `/setup` and the `snapsbrew:install` command to create the owner of the instance.
- Six starter templates: a link preview, a quote card, a certificate, an announcement, a blog header and a YouTube thumbnail. The setup page and `snapsbrew:install` offer them, and `snapsbrew:starter-templates` adds them to any account.
- Invite-only and open registration modes, an owner role, user deactivation and account recovery commands.
- Optional Rollbar error reports and S3-compatible media storage.

### Changed

- `docker-compose.yml` starts one container with SQLite. The `mysql` profile adds MySQL 8.4, Redis and Browserless, and the web container then runs Horizon. The Compose stack no longer has separate Horizon and scheduler containers, so `docker compose up -d` no longer waits for the migrations. The volume is `data` (mounted at `/data`), not `storage`.
- Horizon loads only when `QUEUE_CONNECTION=redis`. With another queue, `/horizon` and the `horizon` commands do not exist.
- The app stores each email in lowercase, and the login form, the registration form and the CLI commands lowercase the email that a person types. Existing rows do not change.
- SQLite connections use WAL, a busy timeout of 5 seconds and `IMMEDIATE` transactions (`DB_JOURNAL_MODE`, `DB_BUSY_TIMEOUT`, `DB_SYNCHRONOUS`, `DB_TRANSACTION_MODE`).
- The `database` queue connection gives a job 180 seconds before a retry, the same as the `redis` connection.
- When `APP_KEY` is empty, the app reads the key from the file in `APP_KEY_FILE`.
- A release tag `v0.x.y` also publishes the image tag `0`.

- The Browsershot renderer finds `node` and `npm` on `PATH` when `BROWSERSHOT_NODE_PATH` and `BROWSERSHOT_NPM_PATH` are empty. It reads Puppeteer from the `node_modules` folder of the app and no longer runs `npm root -g` for each render. In a container or as root, it turns off the Chrome sandbox. `BROWSERSHOT_INSTANCE` also accepts `host:port`.
- The owner that `/setup` or `snapsbrew:install` creates goes to the dashboard, not to the onboarding wizard.
- The setup page adds the starter templates in the request, so the first dashboard shows them. Their preview images render on the queue.
- The invitation page says "Invitation sent" only when a mail went out.
- Only a company owner can rotate the webhook secret, invite people, cancel invitations, delete fonts and rename the company. A member gets 403 and sees "Only a company owner can do this." on the page. Templates, snaps and collections stay shared by all users of the company.
- The migration makes the first user of each existing company the owner. Every other user becomes a member.
- An accepted invitation cannot be deleted. `DELETE /account/invitations/{id}` answers 404 for it.
- A member skips the invite step of the onboarding wizard.
- Each REST API route with a bearer token answers an error with a JSON body, also when the request has no `Accept: application/json` header. Before, such a request got a 302 to `/login` for a bad token, a 302 for a validation error and an HTML page for a missing record.
- The `409` and `422` bodies of the template and editor document routes have a `message` next to `code`. The message says how to correct the request.
- `composer test` runs the test suite in parallel processes, and `composer lint` runs the code style check. The tests now write the files of the `public` disk to `storage/framework/testing`, so a test run no longer adds or deletes files in `storage/app/public` of a checkout.
- The scheduled commands that use `withoutOverlapping()` also use `onOneServer()`. On more than one server, set a shared cache (`CACHE_DRIVER=redis`), so that only one server runs each command.
- `HORIZON_MAX_PROCESSES` sets the number of Horizon workers. Without it, the counts stay 10 in production and 3 in local.
- The default `BROWSERLESS_TIMEOUT` is 15 seconds, not 60. Horizon stops a render job at 60 seconds, so with 60 seconds for each attempt, attempts 2 and 3 never ran. The Compose files set the Browserless `TIMEOUT` to 15000 ms.
- A signed URL accepts the key of any active user in the company of the template, not only the keys of the template owner. The usage log and the snap name the user whose key signed the URL.
- `PATCH /v1/templates/{templateIdentifier}`, the MCP tool `edit-template` and the template settings form render a new preview image only when `layers`, `groups`, `width` or `height` changes, or when the template has no preview. A repeat of the same request, or a change to the title only, makes no render and keeps the preview URL. Before, each API and MCP call rendered the preview again.

### Removed

- The app loads no Plausible analytics script. The app does not read `PLAUSIBLE_DOMAIN` and `PLAUSIBLE_SCRIPT_URL`, so you can remove them from `.env`.

### Fixed

- A template update that fails to render its preview saves nothing. Before, `PATCH /v1/templates/{templateIdentifier}` and the MCP tool `edit-template` saved the template and then answered `500`, and the template kept the old preview. The render now runs before the save, so a retry of the same request renders again.
- The OpenAPI spec and the Postman collection show `media_url` of a snap as a string that is never null. A snap with no finished asset has `""`. The API response did not change. Before, the spec said `null`.
- The OpenAPI spec says that `media_url` of a batch item is null when the item is not complete (pending, processing, failed or canceled), and never an empty string. The `Snap` and `BatchItem` schemas each name the value that the other object uses. The API response did not change.
- The MCP tools `get-editor-document` and `put-editor-document` answer a full rate limit and a revision conflict with a tool error (`isError`, `code`, `message`, `fix`). Before, they answered with a plain result. The conflict of `put-editor-document` now has `current_revision` and `current_document` under `error.details`, the same as `apply-editor-operations`.
- `php artisan migrate` no longer starts Chrome when `QUEUE_CONNECTION=sync`. Before, a new local setup from `.env.example` failed at the migration `queue_preview_images_for_existing_template_versions` when Node was not at `/usr/local/bin/node`.
- `php artisan db:seed` makes `admin@example.com` an owner, and prints the sign-in details. Before, the seeded account could not open `/admin` or `/horizon`.
- `POST /v1/snaps` with the `collection_id` of a collection that has no templates answers the documented `404`. Before, it answered `500` with `internal_error`.
- An embedded form of a collection with no templates answers the documented `422`. Before, it answered `500`.
- The spreadsheet import maps a barcode column to `barcode_content`. Before, it read the cell as the background color of the layer. The downloaded import template shows the barcode value in the example row.
- The browser editor shows a message when the API refuses a save with 409, because the template has a v2 editor document. Before, the **Save** button showed nothing.
- A delete of a published template with rendered snaps failed with a foreign key error, in the API too. The delete now removes the snaps, the images, the versions and the forms of the template, and the links to its collections.
- The edit page of a template form checks the layers that the form already uses. Before, every box was empty, so a save without new checks failed. The form pages show each error next to its field and keep the typed values after a failed save. Before, every error of a template form said "You have to select at least one layer". A form title is 255 characters or fewer. The form buttons say **Create form** and **Save form**, and the layer and format groups are fieldsets with a legend.
- Each signed-in page has a `lang` attribute, a document title that names the page, one level-one heading and one banner landmark. The keyboard reaches **Log out** in the account menu. The phone menu button tells a screen reader if the menu is open. Success and error messages show on every signed-in page.
- A snap with no image shows its status in the snap lists and on the snap page, not an empty image. **Delete selected** with no snap selected shows a message, not a server error. A single snap delete asks for a confirmation. The pages say "snap", not "snapshot". The empty states of the snaps, screenshots and imports pages no longer link to pages that do not exist.
- **Account** asks for a confirmation before it revokes an API token, a signed URL key or an OAuth connection, cancels an invitation, disables two-factor authentication or makes new recovery codes. After a password change, the page says "Your password is updated." Before, it showed the code `password-updated`. A new API token has a **Copy** button. The **Security** tab uses the same buttons as the other tabs.
- The **Generate a snap** page opens the "first snap" dialog only for the first snap of the company. The dialog traps the focus and closes with Esc. A screen reader announces the result and the errors. After a render, the page has an **Open image** link.
- The onboarding wizard shows an error next to an invite email that is not valid. Before, **Finish** did nothing and showed no message. A step that fails with a rate limit or a server error shows a message. The invite fields have labels, the category cards say if they are selected, and a screen reader hears the step number.
- The template edit page shows the reason when a save fails, and an unknown template returns 404 instead of a server error. The starting template gallery fits a phone screen and says when no template has the chosen size. An empty filtered template list offers **Clear the filters**. The template page says **Duplicate template**, the same as the template actions menu. The spreadsheet uploader no longer puts the file field inside the drop zone button.

### Security

- The free tools render only the template that the operator sets for each tool, change only the fixed fields of the tool, take an image of 2 MB or less and allow 5 requests each minute for each IP.
- The invitation mail shows the company name and the inviter name as text, not as Markdown. The onboarding steps close after onboarding. A company can send 20 invitations each day (`SNAPSBREW_INVITATIONS_PER_COMPANY_PER_DAY`).
- `POST /oauth/register` allows 3 registrations each minute for each IP, takes 5 redirect URIs or fewer and a body of 16 KB or less. The daily `oauth-clients:prune` command deletes registered clients that got no token in 7 days.
- The Docker Compose files publish the app on `127.0.0.1` by default (`APP_BIND`). The docs name the proxy addresses for `TRUSTED_PROXIES` instead of `*`. A login also has a limit of 10 attempts each minute for one email from all IPs together.
- A failed render stores the fixed code `render_failed` in `last_error`. The API, the MCP server and the import page no longer show the message of the renderer. The log keeps that message.
- A screenshot takes a width and a height from 1 to 4000 pixels, the same bounds as a template canvas.
- The import template writes every cell as text, so layer text that starts with `=` is not a formula in Excel. An import reads a cell that starts with `=` as text and never computes it.
- Only the owner of a signed URL key can revoke it. Before this change, every user of the same company was able to revoke it.
- `.env.example` sets `APP_ENV=production` and `APP_DEBUG=false`. Only the owner opens Horizon, also in the `local` environment. The session cookie is secure by default when `APP_URL` starts with `https`.
- `SafeUrl` refuses the shared range `100.64.0.0/10`, the NAT64 prefixes, 6to4 and the other non-global ranges. It trusts the host of `APP_URL` only on the scheme and port of `APP_URL`.
- A spreadsheet import checks each cell with the same rules as the `variants` of `POST /v1/snaps`. An image cell must hold a public http or https URL, and a color cell must hold a CSS color. The error names the row and the column. An import file takes at most 5000 data rows on each sheet (`SNAPSBREW_IMPORT_MAX_ROWS`).
- The public embedded form page prints only the form title, the submit URL and the fields of the form. Before, the page source held the name and the email of the form owner and the stored rows of the templates behind the form.
- A webhook checks the address of its receiver again when the job sends it, and every attempt connects to that checked address. If the host now resolves to an internal or reserved address, the app sends nothing and `last_error` holds `webhook_address_refused`. The editor image download refuses the same non-global ranges as `SafeUrl`.
- The Fortify routes that had no limit now have one. A reset link request allows 5 each minute for each IP and 5 each hour for each email. A password reset allows 5 each minute for each IP. A sign-up allows 5 each minute and 20 each day for each IP. The password confirmation and the password change allow 5 each minute for each user. A request over a limit gets 429.
- A failed Browserless render no longer writes `BROWSERLESS_TOKEN` to the log. The demo `DatabaseSeeder`, which creates users with a public password, refuses to run when `APP_ENV=production`, also with `--force`.

[Unreleased]: https://github.com/amedina068/snapsbrew/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/amedina068/snapsbrew/releases/tag/v0.1.0
