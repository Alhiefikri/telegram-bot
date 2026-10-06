#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
IMAGE="${BALANCE_V1_REDIS_IMAGE:-redis:7-alpine}"
CONTAINER="${BALANCE_V1_REDIS_CONTAINER:-telegram-bot-balance-v1-redis-${RUN_ID}}"

fail() {
    printf 'Balance V1 Redis loss probe failed: %s\n' "$1" >&2
    exit 1
}

cleanup() {
    docker rm --force "$CONTAINER" >/dev/null 2>&1 || true
}
trap cleanup EXIT

command -v docker >/dev/null 2>&1 || fail 'Docker CLI is required.'
docker info >/dev/null 2>&1 || fail 'Docker daemon is unavailable.'
docker container inspect "$CONTAINER" >/dev/null 2>&1 && fail "container already exists: $CONTAINER"

docker run --detach \
    --name "$CONTAINER" \
    "$IMAGE" \
    redis-server --save '' --appendonly no --maxmemory-policy noeviction >/dev/null

redis() {
    docker exec "$CONTAINER" redis-cli "$@"
}

ready=0
for _ in $(seq 1 30); do
    if redis ping 2>/dev/null | grep -qx PONG; then
        ready=1
        break
    fi
    sleep 1
done
[[ "$ready" = '1' ]] || fail 'Redis did not become ready in time.'

redis flushall >/dev/null
redis set balance-v1-volatile-wakeup pending >/dev/null
before="$(redis dbsize)"
redis flushall >/dev/null
after="$(redis dbsize)"

[[ "$before" = '1' ]] || fail "expected one disposable Redis key before loss, found $before"
[[ "$after" = '0' ]] || fail "Redis loss probe left $after keys after FLUSHALL"

printf '{"image":"%s","container":"%s","before_keys":%s,"after_flush_keys":%s,"result":"passed"}\n' \
    "$IMAGE" "$CONTAINER" "$before" "$after"
