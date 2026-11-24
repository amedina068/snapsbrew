# Editor document v2: production rollout

This rollout is additive and guarded by `editor_document_v2` (default off). It
does not render from the v2 document until the later document and immutable
dispatch slices are deployed.

## Preflight

Run these checks against a production replica before adding constraints. Stop
on any returned row; resolve the data issue before continuing.

```sql
SELECT company_id, uuid, COUNT(*) AS duplicates
FROM templates
GROUP BY company_id, uuid
HAVING COUNT(*) > 1;

SELECT template_id, version_number, COUNT(*) AS duplicates
FROM template_versions
GROUP BY template_id, version_number
HAVING COUNT(*) > 1;

SELECT s.id
FROM snaps AS s
LEFT JOIN template_versions AS v
  ON v.id = s.source_template_version_id AND v.template_id = s.template_id
WHERE s.source_template_version_id IS NOT NULL AND v.id IS NULL;
```

## Schema deployment

1. Deploy application code with the flag off.
2. Add nullable columns first. Do not run a production table scan in a Laravel
   migration.
3. Have the DBA run the following MySQL 8 DDL in the maintenance window. Set a
   short `lock_wait_timeout`; abort rather than waiting behind a metadata lock.
   Record the actual `SHOW WARNINGS` result. The migration detects these exact
   objects, so `php artisan migrate --force` can run afterwards only to record
   it in Laravel's migration ledger.

```sql
ALTER TABLE templates
  ADD COLUMN editor_document JSON NULL,
  ADD COLUMN editor_schema_version SMALLINT UNSIGNED NULL,
  ADD COLUMN editor_revision BIGINT UNSIGNED NULL,
  ALGORITHM=INSTANT;
ALTER TABLE template_versions
  ADD COLUMN editor_document JSON NULL,
  ADD COLUMN editor_schema_version SMALLINT UNSIGNED NULL,
  ADD COLUMN editor_revision BIGINT UNSIGNED NULL,
  ALGORITHM=INSTANT;
ALTER TABLE snaps ADD COLUMN source_template_version_id BIGINT UNSIGNED NULL, ALGORITHM=INSTANT;

ALTER TABLE templates ADD UNIQUE INDEX templates_company_id_uuid_unique (company_id, uuid), ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE template_versions
  ADD UNIQUE INDEX template_versions_template_id_version_number_unique (template_id, version_number),
  ADD UNIQUE INDEX template_versions_template_id_id_unique (template_id, id),
  ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE template_versions DROP INDEX template_versions_template_id_version_number_index, ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE snaps
  ADD INDEX snaps_source_template_version_id_index (source_template_version_id),
  ADD INDEX snaps_template_id_source_template_version_id_index (template_id, source_template_version_id),
  ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE snaps
  ADD CONSTRAINT snaps_template_id_source_template_version_id_foreign
  FOREIGN KEY (template_id, source_template_version_id)
  REFERENCES template_versions (template_id, id)
  ON DELETE RESTRICT ON UPDATE RESTRICT,
  ALGORITHM=INPLACE, LOCK=SHARED;
```

4. Verify each index and foreign key with `SHOW CREATE TABLE` and rerun the
   preflight queries. Only then run `php artisan migrate --force` to record the
   checked migration and enable writes that depend on the constraints.

## Resumable backfill

Backfill outside requests and migrations, in small observable batches. The
command only fills rows where `editor_document` is null and is safe to rerun.

```bash
php artisan editor:backfill-template-documents --table=templates --limit=500
php artisan editor:backfill-template-documents --table=templates --after-id=500 --limit=500
php artisan editor:backfill-template-documents --table=template_versions --limit=500
```

Save the reported `--after-id` after every batch. Monitor query duration,
replica lag, errors, and the remaining nullable count. Legacy snaps deliberately
keep a null source in this phase; S01c assigns an immutable source transactionally.

## Rollback

Disable `editor_document_v2` before rollback. The legacy `layers` and `groups`
columns remain untouched, so application rollback is safe. Do not drop columns,
indexes, or source foreign keys until no deployed code reads them and the DBA
has approved the reverse online-DDL plan.
