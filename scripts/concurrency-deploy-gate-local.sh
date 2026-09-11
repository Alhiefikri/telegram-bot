#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
GATE_SCRIPT="$ROOT_DIR/scripts/concurrency-deploy-gate.sh"
RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"

POSTGRES_IMAGE="${CONCURRENCY_POSTGRES_IMAGE:-postgres:16-alpine}"
POSTGRES_DB="${CONCURRENCY_POSTGRES_DB:-telegram_bot_concurrency}"
POSTGRES_USER="${CONCURRENCY_POSTGRES_USER:-postgres}"
POSTGRES_PASSWORD="${CONCURRENCY_POSTGRES_PASSWORD:-postgres}"
POSTGRES_PORT="${CONCURRENCY_POSTGRES_PORT:-55432}"
WAIT_SECONDS="${CONCURRENCY_POSTGRES_WAIT_SECONDS:-60}"
CONTAINER_NAME="${CONCURRENCY_POSTGRES_CONTAINER:-telegram-bot-concurrency-postgres-${RUN_ID}}"

if [[ "${1:-}" == '--help' || "${1:-}" == '-h' ]]; then
    cat <<'HELP'
Usage: scripts/concurrency-deploy-gate-local.sh

Starts a disposable PostgreSQL 16 Docker container, runs the repository's
PostgreSQL concurrency deploy gate, and removes the container on exit.

Environment:
  CONCURRENCY_POSTGRES_IMAGE       Docker image (default: postgres:16-alpine)
  CONCURRENCY_POSTGRES_DB          Maintenance database (default: telegram_bot_concurrency)
  CONCURRENCY_POSTGRES_USER        PostgreSQL user (default: postgres)
  CONCURRENCY_POSTGRES_PASSWORD    PostgreSQL password (default: postgres)
  CONCURRENCY_POSTGRES_PORT        Host port (default: 55432)
  CONCURRENCY_POSTGRES_WAIT_SECONDS Startup wait (default: 60)
  CONCURRENCY_POSTGRES_CONTAINER   Explicit temporary container name
  CONCURRENCY_GATE_ARTIFACT_DIR    Gate artifact root; default is a writable temp directory

The default credentials and port are local disposable-test values only.
HELP
    exit 0
fi

fail() {
    printf 'Local PostgreSQL concurrency gate failed: %s\n' "$1" >&2
    exit 1
}

[[ -x "$GATE_SCRIPT" ]] || fail "gate script is not executable: $GATE_SCRIPT"
command -v docker >/dev/null 2>&1 || fail 'Docker CLI is required.'
docker info >/dev/null 2>&1 || fail 'Docker daemon is unavailable.'

[[ "$POSTGRES_DB" =~ ^[a-zA-Z_][a-zA-Z0-9_]*$ ]] || fail 'CONCURRENCY_POSTGRES_DB must be a safe PostgreSQL identifier.'
[[ "$POSTGRES_USER" =~ ^[a-zA-Z_][a-zA-Z0-9_]*$ ]] || fail 'CONCURRENCY_POSTGRES_USER must be a safe PostgreSQL identifier.'
[[ "$CONTAINER_NAME" =~ ^[a-zA-Z0-9][a-zA-Z0-9_.-]*$ ]] || fail 'CONCURRENCY_POSTGRES_CONTAINER must be a safe Docker container name.'
[[ "$POSTGRES_PORT" =~ ^[0-9]+$ && "$POSTGRES_PORT" -ge 1 && "$POSTGRES_PORT" -le 65535 ]] || fail 'CONCURRENCY_POSTGRES_PORT must be between 1 and 65535.'
[[ "$WAIT_SECONDS" =~ ^[0-9]+$ && "$WAIT_SECONDS" -ge 1 ]] || fail 'CONCURRENCY_POSTGRES_WAIT_SECONDS must be a positive integer.'
[[ -n "$POSTGRES_PASSWORD" ]] || fail 'CONCURRENCY_POSTGRES_PASSWORD must not be empty.'

docker container inspect "$CONTAINER_NAME" >/dev/null 2>&1 && fail "container already exists: $CONTAINER_NAME"

ARTIFACT_ROOT="${CONCURRENCY_GATE_ARTIFACT_DIR:-}"
TEMP_ARTIFACT_ROOT=0
if [[ -z "$ARTIFACT_ROOT" ]]; then
    ARTIFACT_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/telegram-bot-concurrency-gate.XXXXXX")"
    TEMP_ARTIFACT_ROOT=1
fi

container_started=0
cleanup() {
    local status=$?

    if [[ "$container_started" -eq 1 ]]; then
        docker rm --force "$CONTAINER_NAME" >/dev/null 2>&1 || true
        printf 'Removed disposable PostgreSQL container: %s\n' "$CONTAINER_NAME" >&2
    fi

    if [[ "$TEMP_ARTIFACT_ROOT" -eq 1 ]]; then
        printf 'Concurrency gate artifacts retained at: %s\n' "$ARTIFACT_ROOT" >&2
    fi

    exit "$status"
}
trap cleanup EXIT

printf 'Starting disposable PostgreSQL container %s on 127.0.0.1:%s...\n' "$CONTAINER_NAME" "$POSTGRES_PORT"
docker run --detach \
    --name "$CONTAINER_NAME" \
    --publish "127.0.0.1:${POSTGRES_PORT}:5432" \
    --env "POSTGRES_DB=${POSTGRES_DB}" \
    --env "POSTGRES_USER=${POSTGRES_USER}" \
    --env "POSTGRES_PASSWORD=${POSTGRES_PASSWORD}" \
    "$POSTGRES_IMAGE" >/dev/null
container_started=1

deadline=$((SECONDS + WAIT_SECONDS))
until docker exec "$CONTAINER_NAME" pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB" >/dev/null 2>&1; do
    running="$(docker inspect --format '{{.State.Running}}' "$CONTAINER_NAME" 2>/dev/null || printf 'false')"
    [[ "$running" == 'true' ]] || {
        docker logs --tail 40 "$CONTAINER_NAME" >&2 || true
        fail 'PostgreSQL container exited before becoming ready.'
    }

    (( SECONDS < deadline )) || {
        docker logs --tail 40 "$CONTAINER_NAME" >&2 || true
        fail "PostgreSQL did not become ready within ${WAIT_SECONDS}s."
    }

    sleep 1
done

printf 'PostgreSQL is ready; running the concurrency deploy gate.\n'
CONCURRENCY_DB_HOST=127.0.0.1 \
CONCURRENCY_DB_PORT="$POSTGRES_PORT" \
CONCURRENCY_DB_ADMIN_DATABASE="$POSTGRES_DB" \
CONCURRENCY_DB_ADMIN_USERNAME="$POSTGRES_USER" \
CONCURRENCY_DB_ADMIN_PASSWORD="$POSTGRES_PASSWORD" \
CONCURRENCY_DB_APP_USERNAME="$POSTGRES_USER" \
CONCURRENCY_DB_APP_PASSWORD="$POSTGRES_PASSWORD" \
CONCURRENCY_GATE_ARTIFACT_DIR="$ARTIFACT_ROOT" \
RUN_CONCURRENCY_TESTS=1 \
"$GATE_SCRIPT"
