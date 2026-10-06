#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_DIR="$ROOT_DIR/src"
RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
ARTIFACT_DIR="${BALANCE_V1_ARTIFACT_DIR:-$ROOT_DIR/artifacts/balance-v1/$RUN_ID}"
if [[ "$ARTIFACT_DIR" != /* ]]; then
    ARTIFACT_DIR="$ROOT_DIR/$ARTIFACT_DIR"
fi
mkdir -p "$ARTIFACT_DIR"

COMMIT_SHA="$(git -C "$ROOT_DIR" rev-parse HEAD)"
PHP_VERSION="$(php -r 'echo PHP_VERSION;')"
LARAVEL_VERSION="$(cd "$APP_DIR" && composer show laravel/framework --format=json 2>/dev/null | php -r '$data=json_decode(stream_get_contents(STDIN), true); echo $data["versions"][0] ?? "unknown";')"
PHPUNIT_VERSION="$(cd "$APP_DIR" && ./vendor/bin/phpunit --version | head -1)"
SEED="${BALANCE_V1_SEED:-559}"
overall=0

declare -a STEP_NAMES=()
declare -a STEP_STATUSES=()

run_step() {
    local name="$1"
    shift
    local log="$ARTIFACT_DIR/${name}.log"
    local status

    printf '\n== %s ==\n' "$name"
    set +e
    "$@" 2>&1 | tee "$log"
    status="${PIPESTATUS[0]}"
    set -e
    STEP_NAMES+=("$name")
    STEP_STATUSES+=("$status")
    if [[ "$status" -ne 0 ]]; then
        overall=1
        printf 'Step %s failed with exit code %s; see %s\n' "$name" "$status" "$log" >&2
    fi
}

sqlite_suite() {
    cd "$APP_DIR"
    composer test -- --parallel --no-coverage --log-junit="$ARTIFACT_DIR/sqlite-junit.xml"
}

foundation_suite() {
    cd "$APP_DIR"
    composer test:foundation
}

javascript_suite() {
    cd "$APP_DIR"
    npm run test:js
}

style_suite() {
    cd "$APP_DIR"
    ./vendor/bin/pint --test
}

postgres_suite() {
    CONCURRENCY_GATE_ARTIFACT_DIR="$ARTIFACT_DIR/postgres" \
        "$ROOT_DIR/scripts/concurrency-deploy-gate-local.sh"
}

redis_loss_probe() {
    "$ROOT_DIR/scripts/balance-v1-redis-loss-probe.sh"
}

run_step sqlite sqlite_suite
run_step foundation foundation_suite
run_step javascript javascript_suite
run_step style style_suite
run_step redis_loss redis_loss_probe
run_step postgres postgres_suite

REPORT_PATH="$ARTIFACT_DIR/report.md"
{
    printf '# Balance V1 verification evidence\n\n'
    printf -- '- Generated: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf -- '- Commit SHA: `%s`\n' "$COMMIT_SHA"
    printf -- '- PHP: `%s`\n' "$PHP_VERSION"
    printf -- '- Laravel: `%s`\n' "$LARAVEL_VERSION"
    printf -- '- PHPUnit: `%s`\n' "$PHPUNIT_VERSION"
    printf -- '- Fixture seed: `%s` (fixed balance IDs/hashes; non-economic Faker fields are not asserted)\n' "$SEED"
    printf -- '- Provider/RPC: synthetic `BscUsdtRailRpcFixture` and Laravel HTTP fakes only; no staging or production provider calls\n'
    printf -- '- Database: disposable PostgreSQL 16 for the process race gate; SQLite is behavior evidence only\n'
    printf -- '- PostgreSQL process counts: up to 4 concurrent forked workers plus the coordinating PHPUnit process\n'
    printf -- '- Redis: disposable Redis 7 loss probe; money state remains PostgreSQL-owned and recovery is covered by the durable-work tests\n\n'
    printf -- '- Invariant matrix: [docs/balance-v1-verification.md](../../../docs/balance-v1-verification.md)\n\n'
    printf '## Steps\n\n| Step | Result | Artifact |\n| --- | --- | --- |\n'
    for index in "${!STEP_NAMES[@]}"; do
        name="${STEP_NAMES[$index]}"
        status="${STEP_STATUSES[$index]}"
        result='passed'
        [[ "$status" -eq 0 ]] || result='failed'
        printf '| `%s` | %s (exit %s) | [%s.log](%s) |\n' "$name" "$result" "$status" "$name" "$name.log"
    done
    printf '\n## Required interpretation\n\n'
    printf '%s\n' '- PostgreSQL process workers are independent forked PHP processes; the gate refuses non-test databases and fails on skipped tests.'
    printf '%s\n' '- The release manifest is guarded by `ProductionReleaseHardeningTest::test_postgresql_release_manifest_covers_every_tagged_feature_file`.'
    printf '%s\n' '- Provider fixtures prove parser, ownership, finality, receipt-claim and state-machine behavior only. They do not qualify custody, legal permission, or production funding.'
    printf '%s\n' '- H0-v1 uses the accepted single-Owner per-action password policy; the superseded draft maker-checker requirement is not silently reintroduced.'
    printf '%s\n' '- A failure in any step leaves V1 readiness blocked.'
    printf '\nOverall: %s\n' "$([[ "$overall" -eq 0 ]] && printf passed || printf failed)"
} > "$REPORT_PATH"

cat "$REPORT_PATH"
exit "$overall"
