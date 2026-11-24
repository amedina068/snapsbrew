# Contributing to Snapsbrew

Thank you for your help. This file tells you how the code is organized and which rules a pull request must obey.

## Before you start

- Read the [code of conduct](CODE_OF_CONDUCT.md). It applies to issues, pull requests and reviews.
- To report a bug or to ask for a feature, open an issue with the matching template.
- If the change is large, open an issue first and describe the change.
- One pull request does one thing.
- If a pull request changes the database, the configuration or a public API, say so in its description.
- If a pull request changes the UI, add a screenshot.
- The project uses the [AGPL-3.0 license](LICENSE). When you open a pull request, you agree that your contribution uses the same license.

## Set up the project

Follow "Local development" in [README.md](README.md). Then run the tests, the code style check and the front-end build:

```bash
composer test
composer lint
npm run build
```

- `composer test` runs the PHPUnit suite in parallel processes, one for each CPU core. On a 12-core machine, it took about one minute, and one process took more than two minutes.
- `vendor/bin/phpunit` runs the same suite in one process, as the CI does. Use it to run one file: `vendor/bin/phpunit tests/Unit/ApiSpecTest.php`.
- `composer lint` runs `vendor/bin/pint --test`. To correct the code style, run `vendor/bin/pint`.
- `npm run test:e2e` runs the Playwright end-to-end suite in `tests/e2e`. Run `npx playwright install chromium` one time first. The suite starts its own server and needs no `.env` and no outside service. [tests/e2e/README.md](tests/e2e/README.md) tells you how it works.
- `npm run test:unit` runs the Vitest unit tests in `tests/js` in jsdom. The tests cover the Vuex store, `LayersImporter` and Vue components. They need no server and no browser.

The tests write the files of the `public` disk to `storage/framework/testing`, not to `storage/app/public`. A test run does not touch the media of your local instance.

The CI (`.github/workflows/ci.yml`) runs these jobs:

| Job | What it checks |
|---|---|
| Frontend build | `npm ci` and `npm run build` |
| JavaScript tests (Vitest) | `npm ci` and `npm run test:unit` |
| Code style (Pint) | `vendor/bin/pint --test` |
| Static analysis (Larastan) | `composer analyse` finds no error that is not in `phpstan-baseline.neon` |
| API files | `composer api-docs` makes no change in `docs/api` |
| PHP 8.3 and PHP 8.4 | `php artisan migrate:fresh` on an empty database, `vendor/bin/phpunit` and `composer audit --no-dev` |
| MySQL lane | A fixed list of MySQL-only tests against MySQL 8.4 |
| E2E (Playwright) | `npm run test:e2e` in Chromium. If it fails, the job uploads the Playwright report. |

A pull request with a red check is not merged.

## Project structure

| Folder | Content |
|---|---|
| `app/Http/Controllers` | Web and API controllers. `Api/V1` holds the REST API. |
| `app/Mcp` | The MCP server, its tools and its error format. |
| `app/Services` | The render, import, batch, usage and creative package logic. |
| `app/Builders` | The HTML builders that turn a template into the markup that Chrome renders. |
| `app/Support` | Catalogs and contracts: the API spec, the layer schema, the size presets and the guides. |
| `resources/js` | The Vue 3 app. One Vue app mounts on `#app`. |
| `resources/views` | Blade views. |
| `routes` | `web.php`, `api.php` and `ai.php` (the MCP server and its OAuth routes). |
| `tests` | PHPUnit tests: `tests/Feature` and `tests/Unit`. Vitest unit tests: `tests/js`. Playwright end-to-end tests: `tests/e2e`. |

`ARCHITECTURE.md` describes the domain model and the tables.

## Code style

- PHP follows the Laravel preset of Pint. Run `vendor/bin/pint` before you commit.
- Larastan checks the code in `app/` at level 5. Run `composer analyse`. `phpstan-baseline.neon` holds the errors that existed when the check started, so only a new error fails. Correct a new error in the code. Do not add it to the baseline.
- Classes stay in the `App\` namespace, grouped by responsibility.
- Use `camelCase` for methods, `snake_case` for configuration keys and `kebab-case.blade.php` for Blade files.
- Vue single-file components in `resources/js/components` use PascalCase names (`EditorPanel.vue`).
- Interactive UI is Vue. Do not add Alpine.js.

## Tests

- Write the test first when you add behavior.
- Name a test class `SomethingTest` and a test method `test_it_does_something`.
- Use the factories in `database/factories` and the `RefreshDatabase` trait.
- A test for a validation error sends `Accept: application/json` and asserts the message, not only the field name.
- The default test database is SQLite in memory. Some queries behave differently on MySQL. A test that must run on MySQL uses `Tests\Support\RequiresMysql`. Run it with `DB_CONNECTION=mysql DB_DATABASE=<schema> vendor/bin/phpunit`.
- Put a JavaScript unit test in `tests/js`, in a file that ends in `.test.js`. The app uses one store instance, so call `store.replaceState()` before each test. Replace `window.axios` with `vi.stubGlobal('axios', ...)`.

## Rules that keep the surfaces in sync

These rules come from real bugs. A pull request that breaks one of them goes back to its author.

1. **The API spec is the single source of the API docs.** `app/Support/ApiDocs/ApiSpec.php` generates the docs pages, the OpenAPI schema, the Postman collection and the Markdown. If you add an endpoint to `routes/api.php`, add it to `ApiSpec.php` in the same change. Then run `composer api-docs` (see [API files](#api-files)). Do not write endpoint tables by hand in Blade.
2. **A new layer property goes to every surface in the same change.** A design-time property needs `App\Builders\LayerBuilder` and `App\Support\LayerSchema`. A variant property needs `LayerBuilder`, `App\Support\VariantProperties`, `App\Support\VariantRules` and `ApiSpec`.
3. **Canvas size presets live only in `App\Support\TemplateSizes`.** Never copy a pixel size into another catalog. You can add a preset, but never rename the `value` of an existing preset, because the database stores it.
4. **Every usage row goes through `App\Services\UsageRecorder`.** Do not call `ApiLog::create()` or update `renders` in another class. Async work records its render when the job completes, never when the request is accepted.
5. **An editor layer has two shapes.** The API stores `key` and `properties`. The editor also needs `id`, `visible`, `type`, `title` and `locked`. `resources/js/LayersImporter.js` (`normalizeLayer`) adds them when the editor loads a layer.
6. **A new Vue component must be registered in `resources/js/app.js`.** An unregistered component renders nothing and shows no error.
7. **An MCP tool declares its name with `#[Name]`.** `McpToolNamesTest` holds the full list of tool names. Update it when you add a tool.
8. **A data migration runs on MySQL and on SQLite.** Write backfills as `chunkById()` loops with one `UPDATE` per row.
9. **A file upload rule pairs `mimes` with `extensions` and sets `max`.**
10. **Write a user-facing message for a failure only after a test proves that the failure exists.**

## API files

`docs/api` holds three generated files:

- `openapi.yaml`: the OpenAPI 3.0.3 spec.
- `postman_collection.json`: the Postman collection (v2.1), with one folder for each tag.
- `postman_environment.json`: the Postman environment, with the `baseUrl` and `token` variables.

Do not edit these files by hand. `ApiSpec.php` is the source. After you change `ApiSpec.php`, write the files again:

```bash
composer api-docs
```

The script runs `php artisan api:export`. The command writes the spec, then runs the pinned `openapi-to-postmanv2` with `npx`. You need Node 20 or later and access to the npm registry.

Commit the changed files with the change to `ApiSpec.php`. The CI runs the same script and fails when `git diff` shows a change in `docs/api`.

## Release notes

If a pull request has a change that users can see, add a `## Release note` section to its body. Write one or two short sentences in plain text. You can put code and names in backticks. Do not use Markdown links or HTML. Tell the user what changed.

The release notes are in `getting-started/release-notes.md` of the [docs repository](https://github.com/amedina068/snapsbrew-docs). The docs site shows them at [snapsbrew.com/docs/getting-started/release-notes](https://snapsbrew.com/docs/getting-started/release-notes). When a release goes out, the maintainer copies the notes of the merged pull requests to that page. Do not add a release notes file to this repository.

## Changelog

If a pull request changes the behavior for users, add one line under `[Unreleased]` in [CHANGELOG.md](CHANGELOG.md). Use the sections of Keep a Changelog: `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed` and `Security`.

`CHANGELOG.md` lists the changes to the code for each version. A change for users goes in `CHANGELOG.md` and in the `## Release note` section of the pull request.

## Commit messages

- Write the first line in the imperative mood, in 72 characters or fewer: `Add the batch cancel endpoint`.
- In the body, say what changed and why.

## Security

Do not open a public issue for a security problem. Report it in private through a GitHub security advisory. [SECURITY.md](SECURITY.md) gives the steps.
