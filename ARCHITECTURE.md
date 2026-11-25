# Snapsbrew Architecture

## Overview

Snapsbrew is a self-hosted app that renders images and PDFs from templates. A person designs a template in the browser editor. Then the app renders "snaps" from the template, with new text, images, colors, QR codes or barcodes for each render.

A render can come from the web app, the REST API, a signed URL, an embedded form, a spreadsheet import, a batch, a creative package or an AI agent over MCP. The app also captures web pages as screenshots.

**Tech stack:** Laravel 13 with the classic `Http/Kernel` and `Console/Kernel` skeleton, PHP 8.3 or 8.4, Vue 3 built with Vite, Tailwind CSS 4, Fortify (web authentication), Sanctum (API tokens), Passport (OAuth 2.0 for the MCP server), laravel/mcp (the MCP server), Horizon (Redis queues), Spatie Media Library (files) and Spatie Activity Log. Headless Chrome renders each snap, through Browserless or through Spatie Browsershot.

This file describes the code on `main`. When you change a table, a route group, a renderer or a usage rule, change this file in the same pull request.

---

## Domain Concepts

| Concept | Description |
|---------|-------------|
| **Company** | The tenant. It owns the templates, snaps, forms, fonts, brand tokens and creative packages. It has the webhook signing secret. |
| **User** | Belongs to one company. The role is `admin` or `customer`, and both roles can create content. The `is_owner` flag marks a person who runs the instance. `deactivated_at` blocks every login and every token of the user. |
| **Template** | A design: a canvas size, a list of layers and a list of groups. The live row is the draft. A v2 template also has a versioned editor document (`editor_document`, `editor_revision`). |
| **TemplateVersion** | An immutable snapshot of a template draft. Publishing creates one and sets `templates.published_version_id`. Snaps render the published version. |
| **Snap** | One rendered output (image or PDF) of a template. It records the variants, the format and the source template version. It can be sync or async, with a webhook. |
| **SnapImport** | A group of snaps that render as one Laravel job batch. The spreadsheet import (CSV or XLSX) and the batch API (`POST /v1/snaps/batches`) both create one. |
| **CreativePackage** | Up to 10 renders from published template versions, made as one package with a manifest. The MCP tool `export-creative-package` creates one. Each render is a **CreativePackageItem**. |
| **Screenshot** | A captured image of a URL. |
| **Form** | A form tied to a template or to a template collection. A person fills in layer values, and the form renders a snap. A form can be embedded on another site. |
| **TemplateCollection** | A group of templates that one form can render. |
| **Tag** | A company label for templates, through the polymorphic `taggables` pivot. |
| **Font** | A custom font that a company uploaded. |
| **BrandToken** | A named brand value (for example a color) that layers of the company can use. |
| **EditorAsset** | An image that the v2 editor stored, by content hash. Template versions link to the assets that they use. |
| **IdempotencyKey** | The `Idempotency-Key` of a snap or batch request, kept for 24 hours so a retry does not render twice. |
| **ApiLog** | The usage log. One row for each API request or render, with the number of renders. |
| **ApiKey** | An encrypted signed-URL key. It wraps a Sanctum token with the `signed_url` ability. |
| **CompanyInvitation** | An email invitation to join a company. |
| **InstanceSetting** | A key and a value that the owner sets in **Admin > Settings**, for example the registration mode. |
| **UserEvent** | A product event of a user, for example a finished onboarding step. |

---

## Database Schema

These are the application tables and their columns. Run `php artisan migrate` on an empty database to see the full schema.

### Accounts

```
companies
├── id, uuid, name, first_snap_at
├── webhook_secret (encrypted, signs every webhook of the company)
└── timestamps

users
├── id, company_id (FK), name, email, password, email_verified_at
├── role (admin|customer), is_owner (bool), deactivated_at, newsletter (bool)
├── onboarding_completed_at, onboarding_categories
├── two_factor_secret, two_factor_recovery_codes, two_factor_confirmed_at
└── remember_token, timestamps

company_invitations
├── id, company_id (FK), invited_by (FK → users)
├── email, token, expires_at, accepted_at, accepted_by (FK → users)
└── timestamps

instance_settings
├── key, value
└── timestamps

user_events
└── id, user_id, company_id, name, payload, created_at
```

### Templates

```
templates
├── id, uuid, user_id (FK), company_id (FK)
├── title, width, height, size (preset key from TemplateSizes)
├── layers (JSON text), groups (JSON text)
├── published_version_id (FK → template_versions)
├── editor_document (JSON), editor_schema_version, editor_revision
└── timestamps

template_versions
├── id, template_id (FK), version_number, published (bool), created_by (FK → users)
├── layers, groups, width, height
├── editor_document, editor_schema_version, editor_revision
└── timestamps

template_version_assets (pivot)
└── template_version_id, editor_asset_id, timestamps

editor_assets
├── id, uuid, company_id (FK), sha256, mime_type, byte_size, width, height
└── timestamps

template_collections
└── id, uuid, user_id (FK), company_id (FK), name, timestamps

template_template_collection (pivot)
└── template_id, template_collection_id, timestamps

tags
└── id, company_id (FK), name, slug, timestamps (unique: company_id + slug)

taggables (polymorphic pivot)
└── id, tag_id, taggable_id, taggable_type, timestamps

fonts
└── id, uuid, company_id (FK), user_id (FK), name, family, timestamps

brand_tokens
└── id, uuid, company_id (FK), kind, name, value, version, retired_at, timestamps
```

### Renders

```
snaps
├── id, uuid, template_id (FK), user_id (FK), company_id (FK)
├── source_template_version_id (FK → template_versions)
├── form_id (FK, nullable), snap_import_id (FK, nullable)
├── identifier, type (image|pdf), format, transparent (bool)
├── status (pending|processing|completed|failed|canceled)
├── is_async (bool), webhook_url, last_error, completed_at
├── metadata (JSON), variants (JSON)
└── timestamps

snap_imports
├── id, uuid, template_id (FK), user_id (FK), company_id (FK)
├── title, status, total_rows, processed_rows, batch_id (→ job_batches)
├── original_filename, stored_path, error_message, webhook_url
├── completed_at, canceled_at
└── timestamps

idempotency_keys
└── id, company_id (FK), key, status, snap_id, snap_import_id, timestamps

creative_packages
├── id, uuid, company_id (FK), created_by (FK → users)
├── operation_namespace, idempotency_key, request_fingerprint, status
├── total_items and one count for each item state, manifest_version
├── failure_code, failure_details, lease_expires_at
├── started_at, completed_at, replay_expires_at
└── timestamps

creative_package_items
├── id, uuid, company_id (FK), creative_package_id (FK), position
├── the client fields (client_creative_id, label, caption, alt_text, metadata)
├── the source (source_template_uuid, source_template_version_id, requested_format)
├── status, attempts, retryable, the lease fields
├── snap_id, media_id, api_log_id, the output fields (filename, mime type, bytes, sha256, size)
├── failure_code, failure_details and the diagnostic fields
└── started_at, completed_at, timestamps

screenshots
└── id, uuid, user_id (FK), company_id (FK), url, width, height, full_page (bool), timestamps

forms
├── id, uuid, user_id (FK), company_id (FK)
├── formable_id, formable_type (polymorphic → Template or TemplateCollection)
├── title, type, available_layers (JSON), signed_url, submit_signed_url
└── timestamps
```

### Usage and keys

```
api_logs
├── id, uuid, user_id (FK), personal_access_token_id (FK)
├── url, ip_address, method, request, response, status_code
├── renders, feature, type
└── timestamps

api_keys
├── id, user_id (FK), company_id (FK), token_id (FK → personal_access_tokens)
├── type (signed_url), key (encrypted), invalidated_at
└── timestamps
```

### Framework and package tables

- `personal_access_tokens`: Laravel Sanctum
- `oauth_clients`, `oauth_auth_codes`, `oauth_access_tokens`, `oauth_refresh_tokens`: Laravel Passport, for the OAuth flow of the MCP server
- `media`: Spatie Media Library
- `activity_log`: Spatie Activity Log
- `jobs`, `job_batches`, `failed_jobs`: the Laravel queue
- `password_resets`: Fortify

---

## Model Relationships

```
Company
└── hasMany: Users, Templates, TemplateCollections, Screenshots, Snaps, Forms, CreativePackages, invitations (CompanyInvitation)

User
├── belongsTo: Company
├── hasMany: Screenshots, Templates
└── uses: HasApiTokens (Sanctum and Passport), TwoFactorAuthenticatable (Fortify)

Template
├── belongsTo: User, Company, publishedVersion (TemplateVersion)
├── hasMany: versions (TemplateVersion), Snaps, imports (SnapImport)
├── belongsToMany: collections (TemplateCollection)
├── morphMany: Forms
└── morphToMany: Tags (through taggables)

TemplateVersion
├── belongsTo: Template, creator (User)
└── belongsToMany: editorAssets (EditorAsset)

Snap
├── belongsTo: Template, sourceTemplateVersion (TemplateVersion), Company, import (SnapImport), Form
└── media collections: image, html_content, html_head

SnapImport
├── belongsTo: Template, User, Company
└── hasMany: Snaps

CreativePackage
├── belongsTo: Company, creator (User)
└── hasMany: items (CreativePackageItem)

CreativePackageItem
└── belongsTo: Company, package (CreativePackage), sourceVersion (TemplateVersion), Snap, Media, ApiLog

Form
├── belongsTo: User, Company
└── morphTo: formable (Template or TemplateCollection)

TemplateCollection
├── belongsTo: Company
├── belongsToMany: Templates
└── morphMany: Forms

Font, BrandToken
└── belongsTo: Company

EditorAsset
├── belongsTo: Company
└── belongsToMany: versions (TemplateVersion)

ApiKey
└── belongsTo: Company, User, accessToken (PersonalAccessToken)

ApiLog
└── belongsTo: token (PersonalAccessToken)
```

---

## Application Layers

### Builders (`app/Builders`)

**`SnapBuilder`** builds the HTML of a snap:
1. It takes the layers and the groups of a template, the variants and the canvas size.
2. It sends each layer to `LayerBuilder`.
3. It returns `buildContent()` (the HTML body) and `buildHead()` (the font links and the CSS).

**`LayerBuilder`** builds the HTML and the CSS of one layer: colors, gradients, SVG patterns, images, text, fonts, borders, shadows, rotation, QR codes and barcodes. `overwrite()` applies the variant of the layer.

**`FormLayerBuilder`** reads the layers that a form shows. `PropertyBuilders/` and `SvgPatterns/` hold the background and pattern builders.

### Editor document (`app/Editor`)

The v2 editor stores one JSON document for each template. `TemplateDocumentService` reads and replaces it with a revision check. `EditorDocumentOperationApplier` applies the closed list of operations of `PATCH /v1/templates/{templateUuid}/document` and of the MCP tool `apply-editor-operations`. `EditorDocumentProjector` writes the document back to `layers` and `groups`, so the render path reads one shape. `App\Support\EditorDocumentContract` describes the operations and the limits.

### Rendering

`App\Contracts\SnapRenderer` and `App\Contracts\PageScreenshotter` are the two render contracts. `AppServiceProvider` binds both to one driver, from `RENDER_DRIVER`:

- `browserless`: `BrowserlessRenderer` posts the HTML to a Browserless service. The Docker setup uses it.
- `browsershot`: `BrowsershotRenderer` drives Chrome through Node and Puppeteer. With `BROWSERSHOT_INSTANCE`, each render opens a tab in a Chrome that is already running. Without it, each render starts a new Chrome.

Both drivers send the HTML through `App\Rendering\RenderDocument` first. It replaces the Google Fonts link with `@font-face` rules that have `data:` URIs, so a render needs no internet access. `FontLibrary` reads the fonts from `resources/fonts` (the starter fonts) and from `storage/app/fonts` (a cache that the first render with another Google font fills). When `MEDIA_DISK` is `public` and `RENDER_ASSET_URL` is set, it also moves the asset URLs from the `APP_URL` origin to that address. A font that is not available adds a `font_unavailable` item to `RenderWarnings`. `Snap::getImage()` stores the warnings with the image, and the API returns them in `warnings`. The stored snap HTML and the HTML preview keep the Google Fonts link.

`PreviewRenderer` returns watermarked HTML for previews. It starts no browser and writes no usage row.

### Services (`app/Services`)

| Service | Job |
|---------|-----|
| `SnapService` | Builds and dispatches one snap render. The REST API and the MCP tools share it. |
| `SnapBatchService` | Creates a `SnapImport` and its snaps in one transaction, then starts the job batch. The spreadsheet import and the batch API share it. |
| `SnapImportFinalizer` | Writes the end status of a batch from its item counts. |
| `TemplateVersioning` | Publishes the draft as a new version, and reverts the draft to an old version. |
| `TemplateDocumentService` | Reads and replaces the v2 editor document. |
| `CreativePackage*` | Claim, dispatch, render, lease, reconcile and prune the creative packages. |
| `UsageRecorder` | Writes every usage row. Read [Usage Log](#usage-log). |
| `WebhookSender` | Signs and posts each webhook, with 3 attempts. |
| `IdempotencyClaimer` | Claims an `Idempotency-Key` for a snap or a batch. |
| `StarterTemplateInstaller` | Adds the starter templates to a company. |

### Jobs (`app/Jobs`)

**`ProcessSnap`** renders one snap. A single snap runs on the `renders` queue, and a batch item runs on the queue of its batch:
1. It skips the snap if its import was canceled.
2. It builds the HTML with `SnapBuilder` and stores it as media.
3. It renders the image or the PDF through the `SnapRenderer` contract.
4. It sets the status, logs the activity and records the render with `UsageRecorder::recordCompletedAsyncSnap()`.
5. It updates the progress of the import, if the snap has one.
6. It posts the `snap.completed` or `snap.failed` webhook for an async snap.

`ProcessCreativePackageItem` renders one creative package item. `GenerateTemplateVersionPreview` renders the preview image of a version. `InstallStarterTemplates` runs the starter template installer for the setup page. `RemoveFreeToolSnap` deletes a snap of the free tools one day later.

`App\Support\Queues` names the queues: `renders`, `imports`, `mail` and `default`. `config/horizon.php` gives each queue its workers.

### Accounts and setup

**`CreateNewUser`** (Fortify) registers a person:
1. With an invitation, the person joins the company of the invitation.
2. Without an invitation, the action creates a new company and a signed-URL key. An invite-only instance answers 404 to this case.

`App\Support\Registration` reads the registration mode. The owner sets it in the admin area (`instance_settings`), and `REGISTRATION_ENABLED` is the default.

`App\Actions\Instance\CreateInstanceOwner` creates the owner for two callers: the `snapsbrew:install` command and the web setup page at `/setup`. The setup page is open only while the `users` table is empty. It needs a token that `App\Support\InstanceSetup` derives from `APP_KEY` with HMAC. While no user exists, `GET /`, `/login` and `/register` redirect to `/setup`.

`App\Actions\Instance\DeleteUser` deletes a user and the personal data of the user for the admin area.

`StarterTemplateInstaller` adds the starter templates of `App\Support\StarterTemplates` to the company of a user. It writes each template through `TemplateDocumentService`, publishes it with `TemplateVersioning` and renders its preview. It skips a title that the company already has. Three callers use it: the `snapsbrew:starter-templates` command, the `snapsbrew:install` command and the `InstallStarterTemplates` job.

### Catalogs (`app/Support`)

One class holds each list that more than one surface reads:

| Class | List |
|-------|------|
| `ApiDocs\ApiSpec` | The REST API. The docs pages, `docs/api/openapi.yaml`, the Postman files, `llms.txt` and `skill.md` come from it. |
| `TemplateSizes` | The canvas size presets. |
| `LayerSchema` | The design-time layer properties, for the MCP tool `describe-layers`. |
| `VariantProperties`, `VariantRules` | The variant (override) properties and their validation. |
| `DesignGuide`, `PlatformGuide`, `ImagePurposeGuide` | The design guidance of the MCP tools and the guide endpoints. |
| `EditorDocumentContract` | The v2 editor document operations and limits. |
| `CreativePackageContract` | The creative package limits, states and error codes. |

---

## Routing Architecture

### Web routes (`routes/web.php`)

| Area | Routes | Controller |
|------|--------|------------|
| Setup | `GET/POST /setup` | SetupController |
| Onboarding | `/onboarding/*` | OnboardingController |
| Dashboard | `GET /dashboard` | DashboardController |
| Templates | CRUD, editor, rename, versions, publish, revert | TemplateController, TemplateEditorController, TemplateVersionController |
| Generate | `/templates/{templateId}/generate`, snap status | SnapCreateController |
| Snaps | list, show, delete, bulk delete (`/snapshots`) | SnapshotController |
| Imports | create, list, show, status, cancel, example file | SnapImportController |
| Collections | CRUD, templates and forms of a collection | TemplateCollection*Controller |
| Forms | CRUD for the forms of a template | FormController |
| Screenshots | list, show, create | ScreenshotController |
| Fonts | list, upload, delete | FontController |
| Account | profile, company, API tokens, signed-URL keys, webhook secret, invitations, OAuth connections | AccountController and its helpers |
| Usage log | `GET /logs` | LogController |
| Admin (owner only) | `/admin/users`, `/admin/settings` | Admin\UserController, Admin\SettingsController |
| Horizon (owner only) | `/horizon` | Horizon |

### REST API (`routes/api.php`, under `/api`)

Every route takes a Sanctum token with the `api_requests` ability, except the free tools. `ApiSpec` documents each route, and `RoutesToApiSpecTest` fails when a route and the spec disagree.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/v1/auth` | The account of the token |
| GET | `/v1/guides/*` | Static catalogs: design, layers, variant properties, editor document, platforms, purposes |
| GET/POST/PATCH/DELETE | `/v1/templates`, `/v1/templates/{id}` | Template list, read, create, update, delete |
| GET | `/v1/templates/sizes` | The size presets |
| POST | `/v1/templates/{templateUuid}/publish` | Publish the draft |
| GET/PUT/PATCH | `/v1/templates/{templateUuid}/document` | The v2 editor document, and `/document/preflight` |
| POST | `/v1/templates/{templateUuid}/tags` | Tag a template |
| GET | `/v1/templates/{templateUuid}/preview`, `/v1/snaps/preview` | Watermarked HTML previews |
| POST | `/v1/snaps` | Render one snap, sync or async |
| POST/GET | `/v1/snaps/batches`, `/v1/snaps/batches/{batchUuid}` | Start and read a batch of up to 100 snaps |
| POST | `/v1/snaps/batches/{batchUuid}/cancel` | Cancel a batch |
| GET/POST | `/v1/screenshots` | Screenshots |
| GET/POST | `/v1/media` | The image library of the user |
| GET/POST | `/v1/tags` | Tags |

Two more route files sit under `/api`:

- `routes/api-embedded-form.php`: `POST /api/v1/embedded-forms/{formUuid}`, the submit of an embedded form. It takes no token. The signed URL of the form is the credential.
- `routes/api-creative-package-media.php`: the signed file and preview URLs of creative package items. `ApiSpec` does not document them.

### Free tools API (public, no token)

The app has no tools web pages. A marketing site serves those pages, and its forms call these routes from the browser. Each route renders one fixed template. `EnsureFreeToolsAreEnabled` returns 404 for these routes until `SNAPSBREW_FREE_TOOLS_ENABLED` is true.

| Method | Endpoint | Controller |
|--------|----------|------------|
| POST | `/v1/tools/certificate-creator` | `Api\V1\Tools\CertificateCreatorController` |
| POST | `/v1/tools/twitter-graph-image-creator` | `Api\V1\Tools\TwitterGraphImageCreatorController` |
| POST | `/v1/tools/testimonial-image-creator` | `Api\V1\Tools\TestimonialImageCreatorController` |
| POST | `/v1/tools/screenshot-creator` | `Api\V1\Tools\ScreenshotCreatorController` |

The screenshot route stores each screenshot under the user that `SNAPSBREW_FREE_TOOLS_USER_ID` names. In production, the `RemoveFreeToolSnap` job removes each template snap one day later. `ApiSpec` does not document these routes.

### MCP server (`routes/ai.php`)

`/mcp` serves `App\Mcp\Servers\SnapsbrewServer` and its tools in `app/Mcp/Tools`. It takes a Sanctum token or a Passport OAuth access token (`auth:sanctum,api`, in this order). `routes/ai.php` also registers the OAuth discovery and registration routes. When `MCP_ENABLED` is false, the app registers none of these routes.

Each tool declares its name with `#[Name]`, and `McpToolNamesTest` holds the list. A tool error goes through `App\Mcp\ToolError`, which returns `code`, `message`, `fix` and `retryable`.

### Public routes

- `/` sends a guest to `/login` and a signed-in user to `/dashboard`.
- The API documentation: `/documentation*`, `/llms.txt` and `/skill.md`.
- Signed URL images: `/signed-url/image.jpg`.
- Embedded forms: `/embedded-form/{formUuid}`.
- The health check: `/up`.

---

## Middleware

| Middleware | Purpose |
|-----------|---------|
| `LogApiRequestMiddleware` (`logApiRequest`) | Opens the usage log row of an API request. |
| `SignedUrlMiddleware` | Checks the signature and the key of a signed image URL. |
| `RejectNonScalarSignature` | Refuses a signed URL whose `signature` is an array. |
| `EmbeddedFormMiddleware` | Finds the form and its owner, and opens the usage log row. |
| `AllowIframeMiddleware` | Lets another site show an embedded form in an iframe. |
| `EnsureMcpAccess` | Checks the token ability or the OAuth scope on `/mcp`. |
| `LimitOAuthClientRegistration` | Limits `POST /oauth/register`. |
| `ValidateCreativePackageEnvelope` | Checks the JSON shape of a creative package call. |
| `EnsureFreeToolsAreEnabled` | Returns 404 for the free tools until they are on. |
| `EnsureUserIsOwner` (`owner`) | Opens the admin area to an owner only. |
| `EnsureUserOnboarded` (`onboarded`), `EnsureOnboardingIsOpen` | Send a new user through onboarding. |
| `RedirectToSetup` | Sends every web request to `/setup` while the instance has no user. |
| `LogoutDeactivatedUser` | Ends the session of a deactivated user. |

The rate limiters are in `RouteServiceProvider`: `api`, `preview`, `editor-document`, `mcp`, `embedded-form-view`, `embedded-form-submit`, `signed-url` and `free-tools`.

---

## Authentication & Authorization

- **Fortify** handles registration, login, password reset, email verification and two-factor authentication (TOTP).
- **Sanctum** gives API tokens with abilities: `api_requests` for the REST API and the MCP server, and `signed_url` for signed-URL keys.
- **Passport** gives OAuth 2.0 access tokens to MCP clients. Clients register themselves at `POST /oauth/register`. `MCP_REDIRECT_DOMAINS` limits where an authorization code can go.
- **Roles**: `admin` and `customer` can both create content (`User::actsAsCustomer()`). Only an owner (`is_owner`) can open the admin area and Horizon.
- **Company scoping**: queries filter by the `company_id` of the user. A record of another company answers 404.
- **Policies**: `TemplatePolicy` and `FontPolicy` in `app/Policies` check the company of the record and the role of the user.

---

## Usage Log

Rendering is not limited. The usage log is a history only.

1. **ApiLog** records each request with `renders`, the number of images that the request rendered.
2. **UsageRecorder** (`app/Services/UsageRecorder.php`) writes every usage row. A sync surface settles the row that the middleware opened. Async work records its render when the job completes. A `202` response records no render.
3. The account page and the logs page read the usage log.

---

## Webhooks

An async snap and a batch post a webhook to their `webhook_url`. The events are `snap.completed`, `snap.failed`, `snap-import.completed`, `snap-import.failed` and `snap-import.canceled`. `WebhookSender` signs each request with the secret of the company (`X-Snapsbrew-Signature`, the HMAC-SHA256 of `{timestamp}.{body}`) and makes 3 attempts. The `App\Rules\SafeUrl` rule refuses a private or local webhook host.

---

## Media Storage

**Spatie Media Library** stores the files on the disk that `MEDIA_DISK` names (`public`, `s3`, `spaces` or `r2`):

- **Snap**: `image` (the rendered output), `html_content` (the source HTML), `html_head` (fonts and CSS)
- **Template**: `preview` (with `thumb` and `description` conversions)
- **Screenshot**: `image` (with an `image` conversion)
- **User**: `imagesMedia` (the image library of `GET /v1/media`)
- **CreativePackageItem**: the file of each package output

---

## Scheduled Commands

`app/Console/Kernel.php` runs these commands. The `scheduler` container of the Docker setup runs `schedule:work`.

| Command | When |
|---------|------|
| `snaps:prune-idempotency-keys` | Hourly |
| `creative-packages:reconcile` | Every minute |
| `creative-packages:prune` | Hourly |
| `creative-packages:prune-diagnostics` | Hourly |
| `oauth-clients:prune` | Daily |

---

## Tests

| Suite | Folder | Command |
|-------|--------|---------|
| PHPUnit (feature and unit) | `tests/Feature`, `tests/Unit` | `vendor/bin/phpunit` |
| Vitest (JavaScript unit) | `tests/js` | `npm run test:unit` |
| Playwright (end to end) | `tests/e2e` | `npm run test:e2e` |

Vitest reads `vitest.config.mjs`, with jsdom and the Vue plugin. It does not load `vite.config.mjs`, so the production build does not change. The Vitest config excludes `tests/e2e`. `.github/workflows/ci.yml` runs each suite in its own job.

---

## Key Patterns & Conventions

- **HasUuid trait**: Creates the UUID of a model on creation, and gives `scopeUuid()` for queries.
- **Taggable trait**: Gives `tags()`, `syncTags()` and `attachTags()`.
- **Guarded = []**: Most models allow mass assignment. Controllers validate the input.
- **Constants for statuses and types**: The models hold them, for example `Snap::STATUS_COMPLETED`.
- **One source for each list**: Read [Catalogs](#catalogs-appsupport). `CONTRIBUTING.md` lists the rules that keep the surfaces in sync.
