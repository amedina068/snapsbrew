# Creative package access paths

This document records the T-018 evidence: the access plan of every hot
creative-package query, the query count of one item against ten, the ledger
cardinality with the p95 of the live sum, and the release decision between the
indexed sum and an atomic counter.

Every number below comes from a test. The tests run on MySQL against
production-shaped rows. The `mysql-ledger` job in `.github/workflows/ci.yml`
runs the three classes on every push with `REQUIRE_MYSQL_LEDGER_GATE=true`, so
a skip is a failure there, and it stores both evidence files as an artifact:
`creative-package-access-plans.json` and
`creative-package-ledger-cardinality.json`.

## The seed

`tests/Support/SeedsProductionShapedRows.php` writes one seed per test class
with bulk inserts of 1,000 rows. The seed lands in about 3 seconds and the
class deletes it once. The process holds 45.7 MB after the seed, with a peak of
67.2 MB, because the seed runs with the connection's query listeners detached.
With the listeners attached, the framework exception renderer and Ignition keep
every bulk-insert binding, about 340 MB, and the suite runs near a 128 MB
limit.

| Table | Rows | Shape |
|---|---|---|
| `companies` | 5 | One user, one active subscription and four usage periods each. |
| `api_logs` | 50,000 | One hot current period with 20,000 rows, 16,000 of them billable. Nineteen periods share 30,000. |
| `templates` | 2,000 | 400 per company. |
| `template_versions` | 6,000 | Three per template. Version three is published. |
| `creative_packages` | 3,000 | 600 per company. Half ready, one sixth partial, one twelfth failed, one quarter building, one twelfth building with an expired lease. One in ten is past its replay window. |
| `creative_package_items` | 19,875 | One to twelve per package. Ready, failed, pending and rendering rows. Every rendering row holds a live reservation. |

A billable row is a `POST` with status 200 or 201, the rows that
`CreditLedger::availableCapacity()` sums. Nine of ten seeded rows are `POST`
and four of five of those carry 200 or 201.

## Access plans

`tests/Feature/Performance/MySqlCreativePackageAccessPlanTest.php` captures the
SQL that each service runs with `DB::listen`, then runs `EXPLAIN` on that SQL
with its real bindings. Each test asserts the named index in `key`, the access
type, no `Using temporary`, no `Using filesort`, and an estimate under a stated
bound. The bound is relative to the seeded matching rows, never to the table.

| Query | Source | Index | Type | Rows | Extra | Bound |
|---|---|---|---|---|---|---|
| Source lookup, ten templates | `CreativePackageSourcePreflight::preflight()` | `templates_company_id_uuid_unique` | range | 10 | Using index condition | 10, one seek per pair |
| Version lookup, ten pairs | `CreativePackageSourcePreflight::versions()` | `template_versions_template_id_version_number_unique` | range | 10 | Using index condition | 10, one seek per pair |
| Replay lookup, `FOR UPDATE` | `CreativePackageClaim::existing()` | `creative_packages_company_claim_unique` | const | 1 | | 1 |
| Active count | `CreativePackageClaim::claim()` | `creative_packages_active_limit_index` | range | 100 | Using where; Using index | 150 building rows per company |
| Ledger sum | `CreditLedger::availableCapacity()` | `api_logs_period_usage_covering` | range | 29,282 | Using where; Using index | 40,000, twice the period cardinality |
| Reservation sum | `CreditLedger::availableCapacity()` | `creative_package_items_capacity_index` | range | 400 | Using index condition | 1,000 |
| Reconciler item scan, pending | `CreativePackageReconciler::unowned()` | `creative_package_items_reconciliation_index` | range | 3,500 | Using index condition | 10,000 |
| Reconciler item scan, rendering | same | same | range | 999 | Using index condition | 10,000 |
| Reconciler item scan, failed | same | same | range | 752 | Using index condition | 10,000 |
| Reconciler package scan | `CreativePackageReconciler::reconcile()` | `creative_packages_reconciliation_index` | range | 150 | Using index condition | 1,000 |
| Retention package seek, page one | `CreativePackageRetention::prunePackages()` | `creative_packages_replay_cleanup_index` | range | 125 | Using index condition | 10,000 |
| Retention package seek, page two | same, `id >` tuple seek | same | range | 126 | Using index condition | 10,000 |
| Diagnostic seek, page one | `CreativePackageRetention::clearDiagnostics()` | `creative_package_items_diagnostic_cleanup_index` | range | 674 | Using index condition | 10,000 |
| Diagnostic seek, page two | same, `id >` tuple seek | same | range | 675 | Using index condition | 10,000 |

Notes on the table:

- The ledger sum reads 16,000 billable rows out of 20,000 in the period. The
  optimizer estimates a range from index dives, so the estimate is above the
  real count. A full scan reads as `type: ALL` and fails the test, so the bound
  is a guard against a scan, not a measurement of the sum.
- The reconciler item scan carries a second plan row: the `whereHas('package')`
  subquery reads `creative_packages` by `PRIMARY` with `eq_ref` and one row.
  MySQL 8 flattens the `EXISTS` into a semijoin, so an explicit join buys
  nothing.
- The small-scale plan tests stay where they were:
  `CreativePackageReconcilerTest` pins the two reconciler scans and
  `CreativePackageRetentionTest` pins both seeks on every page. The tests here
  add the scale and the estimate bound only.
- The `template_version_assets` link lookup has no plan row. The seed carries
  no editor assets, so the preflight skips that query. Its key is the primary
  key `(template_version_id, editor_asset_id)`.

## Query counts, one item against ten

`tests/Feature/Performance/CreativePackageQueryCountTest.php` runs on both
drivers over the real `/mcp` route with a bearer token.

| Call | One item | Ten items | Delta | Why |
|---|---|---|---|---|
| `export-creative-package` claim | 17 | 26 | 9 | One `creative_package_items` insert per item. The source lookup, the lock, the replay lookup, the entitlement, the active count, the two capacity sums, the package insert, the dispatcher pluck and the response load are fixed. |
| `get-creative-package` on a ready package | 6 | 6 | 0 | Token, user, package, item load, one `media` load, one `snaps` load. The signer signs URLs in memory. |
| `creative-packages:reconcile` with pending items | 7 | 7 | 0 | Three item scans, one package scan, and one eager load each for packages, creators and companies. No `templates`, `template_versions`, `media` or `snaps` read. |

PLAN.md section 13 says that package creation uses fixed query batches. The
claim writes one insert per item on purpose: `HasUuid` writes the uuid in the
`creating` model event, and a bulk `insert()` skips that event. The cost is
linear and bounded by `CreativePackageContract::MAX_ITEMS`, which is 10.

Sanctum writes `personal_access_tokens.last_used_at` on the first call of a
token. The test clock is frozen, so the second save is not dirty and writes
nothing. The test warms the token before it measures.

## Ledger cardinality and p95

`tests/Feature/Performance/MySqlCreativePackageLedgerCardinalityTest.php`
measures `CreditLedger::availableCapacity()` one hundred times at the seeded
period. Then it grows the same period until its billable count is exactly
100,000, adds 120,000 cold rows to the other periods so the hot period stays
under half of `api_logs`, and measures again.

| Measure | Value |
|---|---|
| `api_logs` rows per period, mean | 2,500 |
| `api_logs` rows per period, max | 20,000 (16,000 billable) |
| `api_logs` rows per company, mean | 10,000 |
| `api_logs` rows per company, max | 24,734 |
| p50 / p95 / max at 20,000 rows | 3.53 ms / 3.86 ms / 4.02 ms |
| Rows at the gate | 104,000 in the period, 100,000 billable, 41 percent of 253,985 `api_logs` rows |
| p50 / p95 / max at the gate | 17.97 ms / 18.53 ms / 19.43 ms |
| Plan at the gate | `api_logs_period_usage_covering`, range, 173,116 rows, Using where; Using index |

The plan at the gate stayed `range` with the same estimate over three full
MySQL lanes in a row. When the hot period was about 77 percent of the table,
the optimizer chose a full index scan (`type: index`) instead. The cold rows
exist to keep the seed away from that ratio.

The measurements come from a laptop MySQL with a warm buffer pool. A
production replica has a different buffer pool, disk and load, so treat the
numbers as a shape, not a promise.

## Decision: the live indexed sum, conditional on the live count

The gate is the one in PLAN.md section 11: a period that can pass 100,000
billable rows needs an atomic settled-credit counter on the locked
`usage_logs` row in place of the lock-held sum. The 50 ms p95 in the
cardinality test is a secondary guard under that gate. The sum runs under the
`usage_logs` row lock in the claim, in every reservation and in every
settlement, and the heartbeat is 30 seconds, so 50 ms holds the lock for less
than 0.2 percent of one heartbeat.

On synthetic rows the sum is within both guards: the p95 at the gate is
18.53 ms and the plan is a covering range. By a two-point line through both
measurements, the sum crosses 50 ms near 280,000 rows in one period. That
number is a shape for a reader, not an alarm threshold.

The decision stays conditional until someone runs this query on a production
replica and records the top periods:

```sql
SELECT usage_log_id, COUNT(*)
FROM api_logs
WHERE method = 'POST' AND status_code IN (200, 201)
GROUP BY usage_log_id
ORDER BY 2 DESC
LIMIT 5;
```

If the largest count is under 100,000, the live indexed sum ships and no
counter is due. If any period is at or above 100,000, add the atomic counter
to `usage_logs`, maintain it in the same transaction as every `api_logs` write,
and read the counter in place of the sum, before the package writers go live.

## Release blocker

Before the deploy, run the replica query, record the result under "Live measurement" in `docs/creative-packages-access-paths.md`, and release only when the top period is under 100,000 rows.

## Live measurement

No result is recorded yet. Record the date, the replica and the five rows the
query returns, then update the decision above.

## Bounded work

- The reconciler reads at most `limit` rows per status pair per pass, with a
  default of 100. The schedule runs it every minute without overlap.
- The retention passes read 1,000 rows per hourly pass by default
  (`CreativePackageRetention::LIMIT`). The `creative-packages.limits.retention_limit`
  key raises it, up to the 10,000 ceiling (`MAX_LIMIT`). Each page seeks past
  the last `(expires_at, id)` tuple, so a row the pass cannot delete does not
  make the next page read it again.
- The claim accepts at most 10 items (`CreativePackageContract::MAX_ITEMS`) and
  3 building packages per company (`MAX_ACTIVE_PACKAGES`).

## Reversion probes

| Probe | Outcome |
|---|---|
| `ALTER TABLE api_logs DROP INDEX api_logs_period_usage_covering`, then run the ledger plan test | Red. The plan read `type: ALL`, `key: null`, `rows: 49816`. The index was added back and the test passed again. |
| One `$item->package()->exists()` per item in `CreativePackageSerializer::item()`, then run the poll count test | Red. One item ran 7 queries and ten items ran 16. The file was restored and the test passed again. |
| Remove the S18 `up()` from the `finally` of the schema rollback test, then run it | Red. The `SHOW INDEX` snapshot differed after the round trip. The line was restored and the test passed again. |

## How to run

```sh
DB_CONNECTION=mysql DB_DATABASE=<schema> php artisan test --filter=Performance
```

The two `MySql*` classes skip on SQLite unless `REQUIRE_MYSQL_LEDGER_GATE` is
set, and then they fail. The query-count class runs on both drivers. Every
class deletes its own rows. Run the lanes one at a time. Two PHPUnit processes
on one checkout share `storage/`, and six `SnapImportRealBatchTest` tests
failed when both lanes ran at once and passed alone.
