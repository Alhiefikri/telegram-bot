#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
PROJECT=${BENCHMARK_COMPOSE_PROJECT:-telegram-bot-dogfood-issue327}
RUNS=${PAID_INVOICE_BENCHMARK_RUNS:-5}
WARMUP=${PAID_INVOICE_BENCHMARK_WARMUP:-1}
OUTPUT=${PAID_INVOICE_BENCHMARK_OUTPUT:-docs/benchmarks/issue-327-paid-invoice.json}

cd "$ROOT_DIR"
mkdir -p "$(dirname "$OUTPUT")"

compose=(docker compose -f docker-compose.yml -f docker-compose.benchmark.yml -p "$PROJECT")
"${compose[@]}" build app

set +e
report=$("${compose[@]}" run --rm --no-deps --entrypoint php app \
    scripts/benchmark-paid-invoice.php \
    --runs="$RUNS" \
    --warmup="$WARMUP")
status=$?
set -e

printf '%s\n' "$report" > "$OUTPUT"
printf '%s\n' "$report"

exit "$status"
