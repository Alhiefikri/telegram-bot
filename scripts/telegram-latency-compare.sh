#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
ROOT=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
BASELINE="${LATENCY_BASELINE_REVISION:-ca408381}"
IMAGE="${LATENCY_BASELINE_IMAGE:-telegram-bot:php-security-8.4.27}"
BASELINE=$(git -C "$ROOT" rev-parse "${BASELINE}^{commit}")
INTEGRATION=$(git -C "$ROOT" rev-parse HEAD)
TEMP=$(mktemp -d /tmp/telegram-latency-comparison.XXXXXXXX)
OUTPUT="${LATENCY_BASELINE_ARTIFACT_ROOT:-${ROOT}/artifacts/telegram-latency-gate/$(date -u +%Y%m%dT%H%M%SZ)}"
mkdir -p "$OUTPUT"
cleanup() {
    local status=$?
    docker run --rm --network none -v "${TEMP}:/comparison" --entrypoint rm "$IMAGE" -rf /comparison/src /comparison/integration >/dev/null 2>&1 || true
    rm -rf "$TEMP"
    exit "$status"
}
trap cleanup EXIT

git -C "$ROOT" archive "$BASELINE" src | tar -x -C "$TEMP"
# Overlay only measurement code. Payment, receipt, recovery and pin owners remain immutable.
cp -a "$ROOT/src/app/Services/Telegram/LatencyBaseline/." "$TEMP/src/app/Services/Telegram/LatencyBaseline/"
cp "$ROOT/src/app/Console/Commands/RunTelegramLatencyBaseline.php" "$TEMP/src/app/Console/Commands/RunTelegramLatencyBaseline.php"
if [[ ! -f "$TEMP/src/app/Support/QueueTopology.php" ]]; then
    cp "$ROOT/src/app/Support/QueueTopology.php" "$TEMP/src/app/Support/QueueTopology.php"
fi
mkdir -p "$TEMP/integration/src"
tar -C "$ROOT/src" --exclude='./vendor' --exclude='./node_modules' --exclude='./.env*' \
    --exclude='./bootstrap/cache/*.php' --exclude='./storage/logs/*' \
    --exclude='./storage/framework/views/*' --exclude='./storage/framework/cache/data/*' \
    -cf - . | tar -x -C "$TEMP/integration/src"
(
    cd "$TEMP/integration/src"
    find app config routes database -type f -print0 | sort -z | xargs -0 sha256sum
) > "$OUTPUT/integration-source-sha256.txt"
{
    printf 'baseline_revision=%s\nintegration_revision=%s\nimage=%s\n' "$BASELINE" "$INTEGRATION" "$IMAGE"
    printf 'measurement_overlay=LatencyBaseline directory, RunTelegramLatencyBaseline, QueueTopology name list\n'
    printf 'integration_worktree_changes:\n'
    git -C "$ROOT" diff --name-only
    printf 'harness_sha256:\n'
    sha256sum "$ROOT"/src/app/Services/Telegram/LatencyBaseline/*.php "$ROOT/src/app/Console/Commands/RunTelegramLatencyBaseline.php" "$ROOT/scripts/telegram-latency-baseline.sh"
    docker image inspect --format 'image_id={{.Id}}' "$IMAGE"
    uname -srmo
} > "$OUTPUT/provenance.txt"

# Run sequentially on the same machine; neither revision competes with the other.
LATENCY_BASELINE_IMAGE="$IMAGE" LATENCY_BASELINE_SOURCE_ROOT="$TEMP/src" \
LATENCY_BASELINE_SOURCE_REVISION="$BASELINE" LATENCY_BASELINE_ARTIFACT_ROOT="$OUTPUT/baseline" \
    "$ROOT/scripts/telegram-latency-baseline.sh" "$@" --worker-topology=combined
LATENCY_BASELINE_IMAGE="$IMAGE" LATENCY_BASELINE_SOURCE_ROOT="$TEMP/integration/src" \
LATENCY_BASELINE_SOURCE_REVISION="${INTEGRATION}+measurement-worktree" LATENCY_BASELINE_ARTIFACT_ROOT="$OUTPUT/integration" \
    "$ROOT/scripts/telegram-latency-baseline.sh" "$@" --worker-topology=split
printf 'Comparison evidence: %s\n' "$OUTPUT"
