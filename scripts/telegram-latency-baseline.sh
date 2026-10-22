#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)

IMAGE="${LATENCY_BASELINE_IMAGE:-telegram-bot:local}"
POSTGRES_IMAGE="${LATENCY_BASELINE_POSTGRES_IMAGE:-postgres:16-alpine}"
REDIS_IMAGE="${LATENCY_BASELINE_REDIS_IMAGE:-redis:7-alpine}"
RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
POSTGRES_DB="${LATENCY_BASELINE_DATABASE:-telegram_bot_latency_baseline}"
POSTGRES_USER="${LATENCY_BASELINE_POSTGRES_USER:-baseline}"
POSTGRES_PASSWORD="${LATENCY_BASELINE_POSTGRES_PASSWORD:-baseline}"
WAIT_SECONDS="${LATENCY_BASELINE_WAIT_SECONDS:-60}"
SCENARIO="${LATENCY_BASELINE_SCENARIO:-all}"
NETWORK="tb-latency-baseline-${RUN_ID}"
POSTGRES_CONTAINER="tb-latency-baseline-pg-${RUN_ID}"
REDIS_CONTAINER="tb-latency-baseline-redis-${RUN_ID}"
ARTIFACT_ROOT="${LATENCY_BASELINE_ARTIFACT_ROOT:-${ROOT_DIR}/artifacts/telegram-latency-baseline}"
ARTIFACT_DIR="${ARTIFACT_ROOT}/${RUN_ID}"

if [[ "${1:-}" == '--help' || "${1:-}" == '-h' ]]; then
    cat <<'HELP'
Usage: scripts/telegram-latency-baseline.sh [artisan options]

Starts disposable PostgreSQL 16 and Redis 7 containers, applies migrations,
and runs one bounded local paid-receipt latency baseline in the application
image with real queue workers. Containers and the network are removed on exit;
reports stay under artifacts/telegram-latency-baseline/<run-id>/.

Environment:
  LATENCY_BASELINE_IMAGE              Application image (default: telegram-bot:local)
  LATENCY_BASELINE_POSTGRES_IMAGE     PostgreSQL image (default: postgres:16-alpine)
  LATENCY_BASELINE_REDIS_IMAGE        Redis image (default: redis:7-alpine)
  LATENCY_BASELINE_DATABASE           Disposable database name (must contain baseline/test)
  LATENCY_BASELINE_SCENARIO           Comma-separated scenarios or all (default: all)
  LATENCY_BASELINE_ARTIFACT_ROOT      Report root (default: artifacts/telegram-latency-baseline)
  LATENCY_BASELINE_WAIT_SECONDS       Container readiness timeout (default: 60)

Artisan options are passed through, e.g.:
  scripts/telegram-latency-baseline.sh --batches=2 --samples=3 --warmup-batches=1
  scripts/telegram-latency-baseline.sh --scenario=slow-payment --worker-topology=combined

Worker topology:
  split      one worker per queue (receipts, notifications, interactions, payments)
  combined   receipt+notification and payments+interactions share a worker; this
             reproduces the pre-cutover head-of-line blocking for comparison

This runner clears the disposable Redis queue/cache and never installs a
scheduler, cron entry or production monitor.
HELP
    exit 0
fi

fail() {
    printf 'Latency baseline failed: %s\n' "$1" >&2
    exit 1
}

command -v docker >/dev/null 2>&1 || fail 'Docker CLI is required.'
docker info >/dev/null 2>&1 || fail 'Docker daemon is unavailable.'
docker image inspect "$IMAGE" >/dev/null 2>&1 || fail "application image not found: $IMAGE (build it or set LATENCY_BASELINE_IMAGE)"
[[ "$POSTGRES_DB" =~ ^[a-zA-Z_][a-zA-Z0-9_]*$ ]] || fail 'database name must be a safe PostgreSQL identifier'
[[ "$POSTGRES_DB" =~ (baseline|test) ]] || fail 'database name must contain baseline or test'
[[ "$SCENARIO" =~ ^[a-z,-]+$ ]] || fail 'scenario must contain only lowercase names and commas'
mkdir -p "$ARTIFACT_DIR"

cleanup() {
    local status=$?
    docker rm --force "$POSTGRES_CONTAINER" "$REDIS_CONTAINER" >/dev/null 2>&1 || true
    docker network rm "$NETWORK" >/dev/null 2>&1 || true
    printf 'Baseline artifacts retained at: %s\n' "$ARTIFACT_DIR" >&2
    exit "$status"
}
trap cleanup EXIT

docker network create "$NETWORK" >/dev/null

printf 'Starting disposable PostgreSQL and Redis for run %s...\n' "$RUN_ID" >&2
docker run --detach \
    --name "$POSTGRES_CONTAINER" \
    --network "$NETWORK" \
    --env "POSTGRES_DB=${POSTGRES_DB}" \
    --env "POSTGRES_USER=${POSTGRES_USER}" \
    --env "POSTGRES_PASSWORD=${POSTGRES_PASSWORD}" \
    "$POSTGRES_IMAGE" >/dev/null
docker run --detach \
    --name "$REDIS_CONTAINER" \
    --network "$NETWORK" \
    "$REDIS_IMAGE" >/dev/null

deadline=$((SECONDS + WAIT_SECONDS))
until docker exec "$POSTGRES_CONTAINER" pg_isready -h 127.0.0.1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" >/dev/null 2>&1; do
    running="$(docker inspect --format '{{.State.Running}}' "$POSTGRES_CONTAINER" 2>/dev/null || printf 'false')"
    [[ "$running" == 'true' ]] || {
        docker logs --tail 40 "$POSTGRES_CONTAINER" >&2 || true
        fail 'PostgreSQL container exited before becoming ready.'
    }
    (( SECONDS < deadline )) || {
        docker logs --tail 40 "$POSTGRES_CONTAINER" >&2 || true
        fail "PostgreSQL did not become ready within ${WAIT_SECONDS}s."
    }
    sleep 1
done
until docker exec "$REDIS_CONTAINER" redis-cli ping 2>/dev/null | grep -q PONG; do
    (( SECONDS < deadline )) || fail "Redis did not become ready within ${WAIT_SECONDS}s."
    sleep 1
done

APP_KEY="base64:$(openssl rand -base64 32)"

common_env=(
    --env APP_NAME=LHIE-Store-Latency-Baseline
    --env APP_ENV=local
    --env APP_DEBUG=false
    --env "APP_KEY=${APP_KEY}"
    --env APP_URL=http://localhost
    --env APP_FORCE_HTTPS=false
    --env APP_ALLOW_DOGFOOD_RUNTIME=true
    --env APP_MAINTENANCE_DRIVER=file
    --env APP_AUTO_MIGRATE=false
    --env "LATENCY_BASELINE_IMAGE=${IMAGE}"
    --env DB_CONNECTION=pgsql
    --env DB_URL=
    --env "DB_HOST=${POSTGRES_CONTAINER}"
    --env DB_PORT=5432
    --env "DB_DATABASE=${POSTGRES_DB}"
    --env "DB_USERNAME=${POSTGRES_USER}"
    --env "DB_PASSWORD=${POSTGRES_PASSWORD}"
    --env CACHE_STORE=redis
    --env QUEUE_CONNECTION=redis
    --env SESSION_DRIVER=array
    --env REDIS_CLIENT=phpredis
    --env "REDIS_HOST=${REDIS_CONTAINER}"
    --env REDIS_PORT=6379
    --env REDIS_DB=1
    --env REDIS_CACHE_DB=2
    --env CACHE_PREFIX=latency_baseline
    --env MAIL_MAILER=log
    --env TELEGRAM_BOT_TOKEN=baseline-synthetic-token
    --env TELEGRAM_WEBHOOK_SECRET=baseline-synthetic-secret
    --env TELEGRAM_ADMIN_CHAT_ID=100000001
    --env TELEGRAM_ADMIN_USER_ID=100000001
    --env DOGFOOD_TIMING_ENABLED=true
    --env DOGFOOD_TIMING_LOG_CHANNEL=stderr
    --env DOGFOOD_FAKE_TELEGRAM_OUTBOUND=true
    --env DOGFOOD_FAKE_TELEGRAM_LATENCY_US=1000
    --env LOG_CHANNEL=stderr
    --env LOG_LEVEL=debug
    --env BINANCE_PAY_ID=
    --env BINANCE_PAY_UID=
    --env BINANCE_API_KEY=
    --env BINANCE_API_SECRET=
    --env PAKASIR_SLUG=
    --env PAKASIR_API_KEY=
    --env KLIKQRIS_SANDBOX_API_KEY=
    --env KLIKQRIS_SANDBOX_MERCHANT_ID=
    --env KLIKQRIS_PRODUCTION_API_KEY=
    --env KLIKQRIS_PRODUCTION_MERCHANT_ID=
    --env SAKURUPIAH_SANDBOX_API_ID=
    --env SAKURUPIAH_SANDBOX_API_KEY=
    --env SAKURUPIAH_PRODUCTION_API_ID=
    --env SAKURUPIAH_PRODUCTION_API_KEY=
    --env BSC_RPC_URL=
    --env HTTP_PROXY=
    --env HTTPS_PROXY=
)

run_app() {
    docker run --rm --network "$NETWORK" \
        -v "${ROOT_DIR}/src:/var/www/html" \
        -v "${ARTIFACT_DIR}:/artifacts" \
        -w /var/www/html \
        --entrypoint php \
        "${common_env[@]}" \
        "$IMAGE" "$@"
}

printf 'Applying migrations to the disposable database...\n' >&2
run_app artisan migrate --force

printf 'Running baseline scenario(s): %s\n' "$SCENARIO" >&2
run_app artisan telegram:latency-baseline \
    --confirm-disposable \
    --scenario="$SCENARIO" \
    --output=/artifacts \
    "$@"
