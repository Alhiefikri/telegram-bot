#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_DIR="$ROOT_DIR/src"
RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
TEST_DATABASE="${CONCURRENCY_DB_DATABASE:-telegram_bot_concurrency_test_${RUN_ID//[^a-zA-Z0-9_]/_}}"
ARTIFACT_DIR="${CONCURRENCY_GATE_ARTIFACT_DIR:-$ROOT_DIR/artifacts/concurrency-gate/$RUN_ID}"
if [[ "$ARTIFACT_DIR" != /* ]]; then
    ARTIFACT_DIR="$ROOT_DIR/$ARTIFACT_DIR"
fi
LOG_PATH="$ARTIFACT_DIR/phpunit.log"
JUNIT_PATH="$ARTIFACT_DIR/junit.xml"
REPORT_PATH="$ARTIFACT_DIR/report.md"

fail_preflight() {
    echo "Concurrency gate preflight failed: $1" >&2
    exit 1
}

if [[ -e "$ARTIFACT_DIR" && ! -d "$ARTIFACT_DIR" ]]; then
    fail_preflight "artifact path exists but is not a directory: $ARTIFACT_DIR"
fi

if ! mkdir -p -- "$ARTIFACT_DIR"; then
    fail_preflight "unable to create artifact directory: $ARTIFACT_DIR"
fi

if [[ ! -w "$ARTIFACT_DIR" ]]; then
    fail_preflight "artifact directory is not writable: $ARTIFACT_DIR"
fi

WRITE_PROBE="$ARTIFACT_DIR/.concurrency-gate-write-probe-$$"
if ! : > "$WRITE_PROBE"; then
    fail_preflight "artifact directory cannot accept files: $ARTIFACT_DIR"
fi
rm -f -- "$WRITE_PROBE"

if ! php -r 'if (! extension_loaded("pdo_pgsql")) { fwrite(STDERR, "pdo_pgsql is required.\n"); exit(1); } if (! function_exists("pcntl_fork")) { fwrite(STDERR, "pcntl_fork is required.\n"); exit(1); }'; then
    fail_preflight "the PHP CLI is missing a required PostgreSQL/process-concurrency extension"
fi

if [[ ! -x "$APP_DIR/vendor/bin/phpunit" ]]; then
    fail_preflight "PHPUnit is not installed at $APP_DIR/vendor/bin/phpunit"
fi

cleanup() {
    php "$ROOT_DIR/scripts/concurrency-gate-db.php" drop "$TEST_DATABASE" || true
}
trap cleanup EXIT

php "$ROOT_DIR/scripts/concurrency-gate-db.php" create "$TEST_DATABASE"

set +e
(
    cd "$APP_DIR"

    APP_ENV=testing \
    RUN_CONCURRENCY_TESTS=1 \
    DB_CONNECTION=pgsql \
    DB_HOST="${CONCURRENCY_DB_HOST:-${DB_HOST:-127.0.0.1}}" \
    DB_PORT="${CONCURRENCY_DB_PORT:-${DB_PORT:-5432}}" \
    DB_DATABASE="$TEST_DATABASE" \
    DB_USERNAME="${CONCURRENCY_DB_APP_USERNAME:-${CONCURRENCY_DB_ADMIN_USERNAME:-${POSTGRES_USER:-${DB_USERNAME:-postgres}}}}" \
    DB_PASSWORD="${CONCURRENCY_DB_APP_PASSWORD:-${CONCURRENCY_DB_ADMIN_PASSWORD:-${POSTGRES_PASSWORD:-${DB_PASSWORD:-}}}}" \
    DB_URL= \
    ./vendor/bin/phpunit \
        --configuration=phpunit.concurrency.xml \
        --group=concurrency \
        --fail-on-skipped \
        --display-skipped \
        --log-junit "$JUNIT_PATH" \
        --no-coverage
) 2>&1 | tee "$LOG_PATH"
phpunit_status="${PIPESTATUS[0]}"
set -e

php "$ROOT_DIR/scripts/junit-gate-report.php" \
    "PostgreSQL Concurrency Deploy Gate Result" \
    "$JUNIT_PATH" \
    "$phpunit_status" \
    "$LOG_PATH" \
    "$TEST_DATABASE" > "$REPORT_PATH"
cat "$REPORT_PATH"

exit "$phpunit_status"
