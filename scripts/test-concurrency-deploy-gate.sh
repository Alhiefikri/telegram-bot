#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
SUBJECT="$ROOT_DIR/scripts/concurrency-deploy-gate.sh"
TMP_DIR=$(mktemp -d "${TMPDIR:-/tmp}/concurrency-deploy-gate-test.XXXXXX")
trap 'rm -rf "$TMP_DIR"' EXIT

artifact_path="$TMP_DIR/artifacts"
: > "$artifact_path"

exit_code=0
CONCURRENCY_GATE_ARTIFACT_DIR="$artifact_path" "$SUBJECT" > "$TMP_DIR/stdout" 2> "$TMP_DIR/stderr" || exit_code=$?

if [ "$exit_code" -eq 0 ]; then
    printf 'FAIL: the concurrency gate accepted a non-directory artifact path.\n' >&2
    exit 1
fi

grep -Fq 'Concurrency gate preflight failed:' "$TMP_DIR/stderr"
grep -Fq 'artifact path exists but is not a directory' "$TMP_DIR/stderr"

if grep -Fq 'Created PostgreSQL test database' "$TMP_DIR/stdout" "$TMP_DIR/stderr"; then
    printf 'FAIL: artifact preflight ran database setup before rejecting the path.\n' >&2
    exit 1
fi

printf 'concurrency deploy gate preflight tests passed.\n'
