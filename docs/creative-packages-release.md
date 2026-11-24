# Creative packages release runbook

This runbook covers the first deploy of the `export-creative-package` and
`get-creative-package` MCP tools. Read it before you deploy. Every claim
below names the code or the test that holds it.

## Deploy requirements

### Cache store

Set `CACHE_DRIVER=redis` on every application and worker host.

`ProcessCreativePackageItem` is `ShouldBeUnique`. The unique lock lives in
the default cache store. A `file` cache is local to one host. With a file
cache, two hosts can queue the same item at the same time. The database
fence still refuses the second render. The duplicate job only wastes a
worker slot.

The three schedule entries use `withoutOverlapping()`. That mutex lives in
the same cache store. With a file cache, two cron hosts run the same pass
at the same time.

### Queue retry window

Keep `config/queue.php` redis `retry_after` above the job timeout.

The value is 180 seconds. The job timeout is 120 seconds
(`CreativePackageContract::ATTEMPT_TIMEOUT_SECONDS`). The longest backoff is
45 seconds. If `retry_after` drops below the timeout, Redis hands a running
job to a second worker.
`CreativePackageItemAttemptsTest::test_the_redis_retry_window_is_longer_than_the_attempt_timeout`
pins the order of the two numbers.

### Application key rotation

If you rotate `APP_KEY`, put the old key in `APP_PREVIOUS_KEYS` first.

`config/app.php` has no `previous_keys` entry. Laravel 11 merges the
framework's own `vendor/laravel/framework/config/app.php` under the
application file, and that file reads `APP_PREVIOUS_KEYS` into
`app.previous_keys`. The URL signer verifies a signature against the
current key and every previous key. Two tests hold this:
`CreativePackageMediaTest::test_a_file_url_signed_before_a_key_rotation_verifies_through_the_previous_keys`
and
`CreativePackageMediaTest::test_the_previous_keys_are_read_from_the_environment_through_the_framework_config`.

A signed file URL lives at most 15 minutes
(`CreativePackageContract::URL_MAX_TTL_SECONDS`, 900 seconds). The default
TTL is 10 minutes. Keep the old key in the list for at least 15 minutes
after the rotation. After that, every URL signed with the old key has
expired.

### Passport keys

Run `php artisan passport:keys` before the first request reaches `/mcp`.

The route reads two guards: `auth:sanctum,api`. A request that the Sanctum
guard rejects reaches the Passport guard. Without the key pair, that guard
throws and the request answers 500 instead of 401.

### Queue workers

Horizon is the queue manager. Its `supervisor-1` in `config/horizon.php`
reads `Queues::all()`, which includes `imports`. After every deploy, run
`php artisan horizon:terminate`. The supervisor restarts on the new code.
Without it, the running workers keep the old job class in memory.

If Horizon does not run on a host, run one worker there:
`php artisan queue:work redis --queue=imports`.

`ProcessCreativePackageItem` dispatches to `Queues::IMPORTS`. With no
worker on `imports`, a package with a pending item stays `building`. The
reconciler redispatches the item to the same queue and renews the package
lease. The package keeps its active slot until a worker reads the queue.
It does not recover by itself.

### Schedule

Run `php artisan schedule:run` from cron every minute.

`app/Console/Kernel.php` registers three package entries:

| Entry | Cadence | Mutex |
| --- | --- | --- |
| `creative-packages:reconcile` | every minute | `withoutOverlapping()` |
| `creative-packages:prune` | hourly | `withoutOverlapping()` |
| `creative-packages:prune-diagnostics` | hourly | `withoutOverlapping()` |

## Migrations

Two migrations change live tables, `api_logs` and `subscriptions`. Each one
runs its ALTER with `ALGORITHM=INPLACE, LOCK=NONE` and a 5-second
`lock_wait_timeout`, in every environment. If the lock wait times out,
`migrate` stops and changes nothing. Run it again when the table is quieter.
To apply the ALTER by hand in a chosen window instead, follow
`docs/billing-ledger-online-ddl.md` and
`docs/subscription-entitlement-online-ddl.md`. The migrations then verify
and record what exists.

Then run `php artisan migrate --force` before the deploy of the workers.

This feature adds ten migrations
(`git diff --name-status main...HEAD -- database/migrations`). With them,
`database/migrations` holds 79 files (`ls database/migrations | wc -l`),
and `migrate:fresh` on MySQL reports 79 migrations DONE:

| Migration | Adds |
| --- | --- |
| `2026_09_16_000000_add_billing_identity_to_api_logs` | `api_logs.billing_key` with its unique index, the settled-credit index |
| `2026_09_16_000100_create_creative_packages_table` | The package table, its unique keys and its lookup indexes |
| `2026_09_16_000200_create_creative_package_items_table` | The item table, its unique keys and the source version index |
| `2026_09_17_000300_add_execution_state_to_creative_package_items_table` | Lease, billing and output columns with the fence and capacity indexes |
| `2026_09_17_000400_add_company_created_at_index_to_subscriptions_table` | `subscriptions_company_created_at_index`, which the claim entitlement read uses |
| `2026_09_20_000000_add_output_dimensions_to_creative_package_items_table` | `output_width` and `output_height` |
| `2026_09_20_000500_add_reconciliation_index_to_creative_packages_table` | `creative_packages_reconciliation_index` |
| `2026_09_22_000100_add_diagnostic_cleanup_index_to_creative_package_items_table` | `creative_package_items_diagnostic_cleanup_index` |
| `2026_09_22_000200_add_uuid_unique_index_to_creative_packages_table` | `creative_packages_uuid_unique`, which the signed media route reads (T-084) |
| `2026_09_22_000300_drop_unread_creative_package_indexes` | Drops the two indexes with no reader (T-085) |

Every migration runs on MySQL and on SQLite.

## Commands

| Command | What it does |
| --- | --- |
| `creative-packages:reconcile` | Redispatches items whose lease expired, and closes spent attempt cycles |
| `creative-packages:prune` | Deletes packages past the 90-day replay window, which frees their keys |
| `creative-packages:prune-diagnostics` | Clears the hidden diagnostic columns past the 30-day window |
| `creative-packages:diagnostic {item}` | Prints the hidden diagnostic fields of one item, and nothing else |

Each command logs its counts. The count names are in
`CreativePackageContract::LOG_FIELDS`.

## Release blocker

Before the deploy, run the replica query, record the result under "Live measurement" in `docs/creative-packages-access-paths.md`, and release only when the top period is under 100,000 rows.

The production release stays blocked until the T-018 live measurement is
recorded. Every local number comes from a seeded local database, not from
live data.
`docs/creative-packages-access-paths.md` holds the seed, the access plans
and the decision that depends on the live count.

## Known gaps

These gaps are recorded in the run and have no task.

- `snaps.last_error` of a legacy sync snap is public by design (B-046).
  A package snap never stores a message there.
- A replay inside the release window of a failed write loses its hold
  (B-050). The next heartbeat throws, the job surrenders, and the retry
  claims again. Measured on MySQL: no double charge.
- A retention pass is bounded, so a large backlog needs more than one
  hourly pass. The "Bounded work" section of
  `docs/creative-packages-access-paths.md` owns the pass and page numbers
  and the config keys that raise them. If the backlog grows faster than
  one pass clears, raise the limit or run the command more often.

## Evidence

The golden journey test is
`tests/Feature/Mcp/CreativePackageGoldenJourneyTest.php`. It runs one
export of three published versions at 1:1, 4:5 and 9:16 over the real
`/mcp` route with the `database` queue.

### CI

`.github/workflows/ci.yml` holds these gates:

| Gate | Job | Step |
| --- | --- | --- |
| `npm run prod` | `frontend` | Build frontend assets |
| `migrate:fresh` on SQLite | `test` | Verify fresh migration build |
| Full PHPUnit suite | `test` | Run test suite |
| `composer audit --no-dev` | `test` | Security audit |
| `migrate:fresh` on MySQL 8.4 | `mysql-ledger` | Prepare MySQL schema |
| MySQL schema and race tests | `mysql-ledger` | Run mandatory ledger gate |

The `test` matrix runs PHP 8.3 only. `composer.json` requires `php ^8.3`
and pins the platform to 8.3.0. A PHP 8.2 job cannot install the
dependencies, so no 8.2 job was added.

### Local gates, 2026-09-22

Golden journey test:

| Lane | Result |
| --- | --- |
| SQLite | 1 passed (103 assertions) |
| MySQL | 1 passed (103 assertions) |

Regression list. The classes are the test files whose content names
`BillingPaths`, `CreditLedger`, `Embedded`, `SignedUrl`, `Onboarding` or
`Preview`. This command selects them by class name and runs them:

```sh
F=$(grep -rlE "BillingPaths|CreditLedger|Embedded|SignedUrl|Onboarding|Preview" tests \
  | xargs -n1 basename | sed 's/\.php$//' | paste -sd'|' -)
php artisan test --filter="^Tests\\\\.*\\\\($F)::"
```

The grep selects 25 classes. A plain
`--filter='BillingPaths|CreditLedger|...'` matches test names, not file
content, and selects a smaller set (110 passed, 7 skipped on SQLite).

| Lane | Result |
| --- | --- |
| SQLite | 300 passed, 25 skipped (the MySQL-only tests) |
| MySQL | 322 passed, 3 failed. The 3 are the `McpToolsTest` editor-document tests on B-012 |

Full suite, `php artisan test`, after the merge of `main` at `89c9eff`:

| Lane | Result |
| --- | --- |
| SQLite | 1,356 passed, 53 skipped, 0 failed. The 53rd skip is the T-094 test, which runs on MySQL only |
| MySQL | 1,399 passed, 1 skipped, 9 failed. Same nine classes as after T-090 |

The 9 MySQL failures are the nine that also fail on `main`, and nothing
else. Five are editor-document tests: `McpToolsTest` x3,
`ApplyEditorOperationsToolTest` and `PutEditorDocumentToolTest`. The other
four are `MySqlTemplateVersionLockTest`, `ScreenshotApiTest` x2 and
`MediaLibraryAdderCharacterizationTest`.

Before T-090 the lane showed eleven. The `StripeWebhookListenerTest`
unknown-customer test and the `TemplateVersioningTest` backfill fixture
failed on rows that three MySQL-only classes left committed:
`MySqlBillingLedgerSchemaTest`, `MySqlCreativePackageItemSchemaTest` and
`MySqlCreativePackageCreditRaceTest`. Each class now deletes every company
it makes. A `RefreshDatabase` class runs `migrate:fresh` one time per
process, so a pair filter reproduces the leak only when a `RefreshDatabase`
class runs before the leaking class.

Before this change the base `9bc1cf7` had 18 failures on MySQL. The seven
extra were `SnapImportRealBatchTest`, which passed alone and failed in a
full run. Its in-process worker stopped on the 128 MB default memory limit
late in the run. The worker now runs with `--memory=512`. The class passed
in two full SQLite runs and two full MySQL runs in a row.

Other gates:

| Gate | Result |
| --- | --- |
| `php artisan migrate:fresh --force` on MySQL | 79 migrations DONE, through `2026_09_22_000300_drop_unread_creative_package_indexes` |
| `npm run prod` | Compiled. `git status` then showed `public/js/app.js` and `public/mix-manifest.json` modified. No `.vue` or `.js` source changed on the branch, so both were restored and not committed |
| `composer audit --no-dev` | Exit 0. 3 ignored advisories on `laravel/framework`, each with a recorded reason. No new advisory |

Reversion probes on the golden journey test, each run one time:

| Probe | Result |
| --- | --- |
| The 9:16 manifest dimension changed to 1:1 in the assertion | Red: `1 => 1080` expected, `1920` actual |
| The sha256 compared against other bytes | Red: the two digests differ |
| The replay expected to render again (6 calls) | Red: `6` expected, `3` actual |
