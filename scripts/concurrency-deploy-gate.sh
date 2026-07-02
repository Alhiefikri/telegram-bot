#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_DIR="$ROOT_DIR/src"
RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
TEST_DATABASE="${CONCURRENCY_DB_DATABASE:-telegram_bot_concurrency_test_${RUN_ID//[^a-zA-Z0-9_]/_}}"
ARTIFACT_DIR="${CONCURRENCY_GATE_ARTIFACT_DIR:-$ROOT_DIR/artifacts/concurrency-gate/$RUN_ID}"
LOG_PATH="$ARTIFACT_DIR/phpunit.log"
JUNIT_PATH="$ARTIFACT_DIR/junit.xml"
REPORT_PATH="$ARTIFACT_DIR/report.md"

mkdir -p "$ARTIFACT_DIR"

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

php "$ROOT_DIR/scripts/concurrency-gate-report.php" "$JUNIT_PATH" "$phpunit_status" "$LOG_PATH" "$TEST_DATABASE" > "$REPORT_PATH"
cat "$REPORT_PATH"

exit "$phpunit_status"
