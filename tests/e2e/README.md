# End-to-end tests (Playwright)

The specs in this folder drive the app in Chromium through the main flows: the guest pages, sign-in, sign-up with the first onboarding step, and the copy of a starting template into an account.

## Run the suite

```bash
composer install
npm ci
npx playwright install chromium
npm run test:e2e
```

The suite needs no `.env`. Playwright starts the server itself (`webServer` in `playwright.config.ts`) with these steps:

1. `node tests/e2e/support/prepare-env.mjs` writes `.env.e2e` from `.env.example`, with a new `APP_KEY`, and an empty SQLite file in the temp directory of the OS.
2. `npm run build` builds the Vite assets. The suite does not use the Vite dev server. If `public/hot` exists, step 1 stops with an error.
3. `php artisan migrate:fresh --seed --seeder=E2eSeeder --env=e2e` makes the database. `database/seeders/E2eSeeder.php` creates the user `e2e@example.test` with the password `password`, one starting template and one template of that user.
4. `php artisan serve --env=e2e` serves the app on `http://127.0.0.1:8787`.

Each run starts from the same state. Playwright never reuses a running server.

## Settings

| Variable | Default | Use |
|---|---|---|
| `E2E_PORT` | `8787` | The port of the server. Set it when the port is in use. |
| `E2E_DB_DATABASE` | `<temp dir>/snapsbrew-e2e-<hash>.sqlite` | The SQLite file. On a btrfs disk, a file in the checkout makes `migrate:fresh` take minutes, because each migration syncs the file to disk. |
| `E2E_BROWSER_CHANNEL` | empty | Set `chrome` to use the installed Google Chrome when the Playwright Chromium does not run on your OS. |

`.env.e2e` is in `.gitignore`. Do not commit it, because it holds the generated `APP_KEY`.

## No outside services

- Mail goes to the `array` mailer, the queue is `sync`, and broadcasting is off.
- The MCP routes are off (`MCP_ENABLED=false`).
- The renderer points to `http://127.0.0.1:9`, so a render fails at once. No browserless service and no local Chrome renders run.
- Each page aborts every request to a host other than the app, for example Google Fonts (`tests/e2e/fixtures.ts`).

## Flows that the suite does not cover

- Generate a snap, and "Finish" in the onboarding wizard. Both render an image through the browserless service. The sign-up spec stops at the second onboarding step for this reason.
- The template editor. `npm run test:editor` covers it (`tests/browser`).

## Write a spec

- Import `test` and `expect` from `./fixtures`, not from `@playwright/test`. The fixture blocks the outside hosts.
- Find elements by role, label or text. Do not use CSS classes, and do not use fixed waits.
- Vue mounts on `#app` and renders the server HTML again. A value typed before the mount is lost. After a click that opens a page with a form, call `waitForVue(page)` before you type. `signIn()` does this for the sign-in form.

## Run it in CI

The `e2e` job in `.github/workflows/ci.yml` runs these steps from a clean checkout. It needs no repository secrets.

1. Check out the repository.
2. Set up PHP 8.3 with the extensions `mbstring, dom, ctype, fileinfo, openssl, pdo, sqlite3, pdo_sqlite, gd, zip, exif, bcmath, intl`.
3. Set up Node 22.
4. Run `composer install --no-interaction --prefer-dist --no-progress`.
5. Run `npm ci` with `PUPPETEER_SKIP_DOWNLOAD=true`.
6. Run `npx playwright install --with-deps chromium`.
7. Run `npm run test:e2e`. GitHub Actions sets `CI=true`, which turns on the HTML report and `forbidOnly`.
8. If the job fails, upload `playwright-report/` and `test-results/` as artifacts.

Do not copy `.env.example` to `.env` in this job. The suite does not read `.env`. Do not set `DB_CONNECTION` or `DB_DATABASE` in the job, because a process variable wins over `.env.e2e`.
