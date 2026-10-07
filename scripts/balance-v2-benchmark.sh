#!/usr/bin/env bash
# Issue #560: isolated application benchmark; never accepts an existing DB.
set -Eeuo pipefail
umask 077
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [[ "${1:-}" == --help ]]; then
    printf '%s\n' 'Usage: scripts/balance-v2-benchmark.sh [--smoke|--quick]' \
        'Default: 10k Customers, 1m entries/currency, 1800s steady + 60s 5x burst + 60s hot account.' \
        'Smoke: 100 Customers, 10k entries/currency, 5s steady + 2s burst + 2s hot; never capacity evidence.' \
        'Quick: representative dataset, smoke durations; diagnostic evidence only.'
    exit 0
fi
[[ $# -eq 0 || ( $# -eq 1 && ( "$1" == --smoke || "$1" == --quick ) ) ]] || exit 2
command -v docker >/dev/null
docker info >/dev/null
RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
CONTAINER_NAME="balance-v2-postgres-$RUN_ID"
ARTIFACT_DIR="$ROOT_DIR/artifacts/balance-v2/$RUN_ID"
mkdir -p "$ARTIFACT_DIR"
started=0
redis_started=0
REDIS_CONTAINER="balance-v2-redis-$RUN_ID"
cleanup() {
    local status=$?
    if [[ "$started" == 1 ]]; then
        docker logs "$CONTAINER_NAME" >"$ARTIFACT_DIR/postgres.log" 2>&1 || true
        docker rm --force "$CONTAINER_NAME" >/dev/null
    fi
    if [[ "$redis_started" == 1 ]]; then docker rm --force "$REDIS_CONTAINER" >/dev/null; fi
    printf 'Evidence retained: %s\n' "$ARTIFACT_DIR"
    exit "$status"
}
trap cleanup EXIT
docker run --detach --name "$CONTAINER_NAME" --publish 127.0.0.1::5432 \
    --env POSTGRES_DB=balance_v2_disposable --env POSTGRES_PASSWORD=benchmark \
    postgres:16-alpine -c log_lock_waits=on -c deadlock_timeout=100ms >"$ARTIFACT_DIR/container-id"
started=1
for attempt in {1..60}; do
    if docker exec "$CONTAINER_NAME" pg_isready -U postgres -d balance_v2_disposable >/dev/null 2>&1; then break; fi
    [[ "$attempt" -lt 60 ]] || exit 1
    sleep 1
done
PORT="$(docker inspect --format '{{(index (index .NetworkSettings.Ports "5432/tcp") 0).HostPort}}' "$CONTAINER_NAME")"
docker run --detach --name "$REDIS_CONTAINER" --publish 127.0.0.1::6379 \
    redis:7-alpine redis-server --save '' --appendonly no >"$ARTIFACT_DIR/redis-container-id"
redis_started=1
REDIS_PORT="$(docker inspect --format '{{(index (index .NetworkSettings.Ports "6379/tcp") 0).HostPort}}' "$REDIS_CONTAINER")"
# Keep optional benchmark tooling out of the application's dependency graph.
composer install --working-dir="$ROOT_DIR/scripts/fixtures/balance-v2" --no-interaction --no-progress \
    >"$ARTIFACT_DIR/tooling.log" 2>&1
docker inspect "$CONTAINER_NAME" >"$ARTIFACT_DIR/container.json"
lscpu >"$ARTIFACT_DIR/cpu.txt"
free -b >"$ARTIFACT_DIR/memory.txt"
df -T "$ROOT_DIR" >"$ARTIFACT_DIR/disk.txt"
docker info --format 'CPUs={{.NCPU}} MemoryBytes={{.MemTotal}} StorageDriver={{.Driver}}' >"$ARTIFACT_DIR/docker-hardware.txt"
for constraint in cpu.max memory.max; do
    if [[ -r "/sys/fs/cgroup/$constraint" ]]; then
        cp "/sys/fs/cgroup/$constraint" "$ARTIFACT_DIR/cgroup-$constraint"
    fi
done
git -C "$ROOT_DIR" rev-parse HEAD >"$ARTIFACT_DIR/commit.txt"
git -C "$ROOT_DIR" diff --binary >"$ARTIFACT_DIR/worktree.patch"
git -C "$ROOT_DIR" status --short >"$ARTIFACT_DIR/worktree-status.txt"
cp "$ROOT_DIR/scripts/balance-v2-benchmark.php" "$ROOT_DIR/scripts/balance-v2-benchmark.sh" \
    "$ROOT_DIR/scripts/balance-v2-operational-probes.php" "$ROOT_DIR/scripts/balance-v2-acceptance.php" \
    "$ROOT_DIR/scripts/balance-v2-evaluate.php" "$ARTIFACT_DIR/"
cp "$ROOT_DIR/scripts/fixtures/balance-v2/composer.lock" "$ARTIFACT_DIR/tooling-composer.lock"
sha256sum "$ARTIFACT_DIR"/balance-v2-* >"$ARTIFACT_DIR/sources.sha256"
cd "$ROOT_DIR/src"
benchmark_status=0
php "$ROOT_DIR/scripts/balance-v2-benchmark.php" "$PORT" "$ARTIFACT_DIR" "${1:-}" "$REDIS_PORT" \
    2>&1 | tee "$ARTIFACT_DIR/run.log" || benchmark_status=$?
# A completed run may miss its SLOs. Retain its evaluation and negative
# controls anyway, without masking the original benchmark failure.
evaluation_status=0
if [[ -f "$ARTIFACT_DIR/result.json" ]]; then
    php "$ROOT_DIR/scripts/balance-v2-evaluate.php" "$ARTIFACT_DIR" \
        2>&1 | tee "$ARTIFACT_DIR/evaluation.log" || evaluation_status=$?
fi
if [[ "$benchmark_status" -ne 0 ]]; then exit "$benchmark_status"; fi
exit "$evaluation_status"
