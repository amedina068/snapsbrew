#!/usr/bin/env bash
#
# Write the schema dumps database/schema/mysql-schema.sql and
# database/schema/sqlite-schema.sql.
#
# Laravel loads a dump when the migrations table does not exist, and then runs
# only the migrations that the dump does not list. A new MySQL database then
# needs about 2 seconds instead of minutes.
#
# A dump holds the tables and the rows of the migrations table, not other rows.
# The migrations in DATA_MIGRATIONS write rows on a new database (the
# onboarding templates). The script removes them from the migrations rows of
# each dump, so they run after the dump loads.
#
# The script does these steps:
#   1. It starts a temporary mysql:8.4 container.
#   2. It runs every migration on that MySQL database and on a new SQLite file.
#   3. It dumps MySQL with the mysqldump of MySQL 8.4 and the flags of Laravel.
#   4. It dumps SQLite with `php artisan schema:dump` and the sqlite3 client
#      of Ubuntu 24.04 in a container.
#   5. It removes the container.
#
# The text of `.schema` changes between sqlite3 versions. For example, sqlite3
# 3.45 writes `CREATE TABLE IF NOT EXISTS` and sqlite3 3.54 writes
# `CREATE TABLE`. A dump from the sqlite3 client of the host thus fails the
# "Schema dumps" check of CI. The container gives the same client to each
# machine.
#
# Usage: scripts/dump-schemas.sh
# Needs: docker, php with pdo_mysql and pdo_sqlite.
#
# Run it again after you add migrations. It is not required: Laravel runs a new
# migration after the dump loads.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(git -C "$SCRIPT_DIR" rev-parse --show-toplevel)"
SCHEMA_DIR="$REPO_ROOT/database/schema"
CONTAINER="snapsbrew-schema-dump-$$"
SQLITE_IMAGE="snapsbrew-schema-sqlite3:ubuntu-24.04"
WORK_DIR="$(mktemp -d)"

DATA_MIGRATIONS=(
    2026_05_15_000200_seed_onboarding_templates
    2026_06_23_000400_backfill_template_versions
    2026_06_23_000500_queue_preview_images_for_existing_template_versions
)

cleanup() {
    docker rm -f "$CONTAINER" > /dev/null 2>&1 || true
    rm -rf "$WORK_DIR"
}
trap cleanup EXIT

cd "$REPO_ROOT"
mkdir -p "$SCHEMA_DIR"

# Laravel loads an existing dump before it migrates. Start from the migrations.
rm -f "$SCHEMA_DIR/mysql-schema.sql" "$SCHEMA_DIR/sqlite-schema.sql"

echo "Starting MySQL 8.4..."
docker run -d --name "$CONTAINER" --tmpfs /var/lib/mysql:rw,size=2g \
    -e MYSQL_DATABASE=snapsbrew -e MYSQL_ROOT_PASSWORD=root \
    -p 127.0.0.1::3306 mysql:8.4 --skip-log-bin > /dev/null
port="$(docker port "$CONTAINER" 3306/tcp | head -1 | sed 's/.*://')"
until docker exec "$CONTAINER" mysql -uroot -proot -e 'select 1' snapsbrew > /dev/null 2>&1; do
    sleep 1
done

# A data migration can dispatch a job. The null queue discards it.
export APP_ENV=local QUEUE_CONNECTION=null CACHE_DRIVER=array

echo "Running the migrations on MySQL..."
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT="$port" DB_DATABASE=snapsbrew \
    DB_USERNAME=root DB_PASSWORD=root php artisan migrate --force --no-interaction > /dev/null

dump=(docker exec "$CONTAINER" mysqldump -uroot -proot --no-tablespaces --skip-add-locks
    --skip-comments --skip-set-charset --tz-utc --column-statistics=0 --set-gtid-purged=OFF)
"${dump[@]}" --routines --no-data snapsbrew 2> /dev/null \
    | sed -E 's/[[:space:]]+AUTO_INCREMENT=[0-9]+//I' > "$WORK_DIR/mysql-schema.sql"
"${dump[@]}" snapsbrew migrations --no-create-info --skip-extended-insert --skip-routines \
    --compact --complete-insert 2> /dev/null >> "$WORK_DIR/mysql-schema.sql"

echo "Preparing the sqlite3 client of Ubuntu 24.04..."
docker build -q -t "$SQLITE_IMAGE" - > /dev/null <<'DOCKERFILE'
FROM ubuntu:24.04
RUN apt-get update \
    && apt-get install -y --no-install-recommends sqlite3 \
    && rm -rf /var/lib/apt/lists/*
DOCKERFILE

# `php artisan schema:dump` runs `sqlite3` from PATH. This wrapper runs the
# client of the container on the database file in WORK_DIR.
mkdir -p "$WORK_DIR/bin"
cat > "$WORK_DIR/bin/sqlite3" <<WRAPPER
#!/usr/bin/env bash
exec docker run --rm -i -u "$(id -u):$(id -g)" -v "$WORK_DIR:$WORK_DIR" "$SQLITE_IMAGE" sqlite3 "\$@"
WRAPPER
chmod +x "$WORK_DIR/bin/sqlite3"

echo "Running the migrations on SQLite..."
touch "$WORK_DIR/database.sqlite"
DB_CONNECTION=sqlite DB_DATABASE="$WORK_DIR/database.sqlite" \
    php artisan migrate --force --no-interaction > /dev/null
PATH="$WORK_DIR/bin:$PATH" DB_CONNECTION=sqlite DB_DATABASE="$WORK_DIR/database.sqlite" \
    php artisan schema:dump --no-interaction > /dev/null

mv "$WORK_DIR/mysql-schema.sql" "$SCHEMA_DIR/mysql-schema.sql"

for migration in "${DATA_MIGRATIONS[@]}"; do
    sed -i "/'${migration}'/d" "$SCHEMA_DIR/mysql-schema.sql" "$SCHEMA_DIR/sqlite-schema.sql"
done

# The sqlite3 client commits each statement and syncs the disk each time. One
# transaction loads the dump in less than a second instead of about 10 seconds.
{
    echo 'BEGIN TRANSACTION;'
    cat "$SCHEMA_DIR/sqlite-schema.sql"
    echo 'COMMIT;'
} > "$WORK_DIR/sqlite-schema.sql"
mv "$WORK_DIR/sqlite-schema.sql" "$SCHEMA_DIR/sqlite-schema.sql"

echo "Wrote $SCHEMA_DIR/mysql-schema.sql and $SCHEMA_DIR/sqlite-schema.sql."
