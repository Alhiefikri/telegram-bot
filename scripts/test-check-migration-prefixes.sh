#!/usr/bin/env bash
# Exercises scripts/check-migration-prefixes.sh, including its failure paths.
set -euo pipefail

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
SUBJECT="$SCRIPT_DIR/check-migration-prefixes.sh"

TMP_DIR=$(mktemp -d "${TMPDIR:-/tmp}/check-migration-prefixes-test.XXXXXX")
trap 'rm -rf "$TMP_DIR"' EXIT

failures=0

make_migrations() {
    local dir="$TMP_DIR/$1"
    shift
    rm -rf "$dir"
    mkdir -p "$dir"
    local name
    for name in "$@"; do
        printf '<?php\n' > "$dir/$name"
    done
    printf '%s' "$dir"
}

make_allowlist() {
    local file="$TMP_DIR/$1"
    shift
    : > "$file"
    local prefix
    for prefix in "$@"; do
        printf '%s\n' "$prefix" >> "$file"
    done
    printf '%s' "$file"
}

assert_exit() {
    local label=$1
    local expected=$2
    local migrations_dir=$3
    local allowlist_file=$4

    local actual=0
    MIGRATIONS_DIR="$migrations_dir" ALLOWLIST_FILE="$allowlist_file" \
        "$SUBJECT" > "$TMP_DIR/stdout" 2> "$TMP_DIR/stderr" || actual=$?

    if [ "$actual" -eq "$expected" ]; then
        printf 'ok   %s (exit %d)\n' "$label" "$actual"
        return
    fi

    failures=$((failures + 1))
    printf 'FAIL %s: expected exit %d, got %d\n' "$label" "$expected" "$actual" >&2
    sed 's/^/     stderr: /' "$TMP_DIR/stderr" >&2
}

# Passes when every prefix is unique and no allowlist is needed.
unique_dir=$(make_migrations unique \
    2026_01_01_000001_create_a_table.php \
    2026_01_01_000002_create_b_table.php)
empty_allowlist=$(make_allowlist empty-allowlist)
assert_exit 'unique prefixes pass' 0 "$unique_dir" "$empty_allowlist"

# Fails when a new duplicate prefix appears. This is the regression the guard exists for.
duplicate_dir=$(make_migrations duplicate \
    2026_01_01_000001_create_a_table.php \
    2026_01_01_000001_create_b_table.php)
assert_exit 'unallowlisted duplicate fails' 1 "$duplicate_dir" "$empty_allowlist"

# The failure message must name the offending prefix and both files.
if ! grep -q '2026_01_01_000001' "$TMP_DIR/stderr" \
    || ! grep -q 'create_a_table.php' "$TMP_DIR/stderr" \
    || ! grep -q 'create_b_table.php' "$TMP_DIR/stderr"; then
    failures=$((failures + 1))
    printf 'FAIL duplicate report should name the prefix and both files\n' >&2
fi

# A historical duplicate stays tolerated so applied migrations are never renamed.
tolerated_allowlist=$(make_allowlist tolerated-allowlist 2026_01_01_000001)
assert_exit 'allowlisted duplicate passes' 0 "$duplicate_dir" "$tolerated_allowlist"

# A duplicate outside the allowlist still fails even when another is tolerated.
mixed_dir=$(make_migrations mixed \
    2026_01_01_000001_create_a_table.php \
    2026_01_01_000001_create_b_table.php \
    2026_02_02_000002_create_c_table.php \
    2026_02_02_000002_create_d_table.php)
assert_exit 'second duplicate still fails' 1 "$mixed_dir" "$tolerated_allowlist"

# A stale allowlist entry fails so the allowlist cannot outlive its reason.
assert_exit 'stale allowlist entry fails' 1 "$unique_dir" "$tolerated_allowlist"

# A missing migrations directory is an error, not a silent pass.
assert_exit 'missing directory fails' 1 "$TMP_DIR/does-not-exist" "$empty_allowlist"

# The real repository tree must satisfy the guard.
assert_exit 'repository tree passes' 0 \
    "$(dirname -- "$SCRIPT_DIR")/src/database/migrations" \
    "$SCRIPT_DIR/migration-prefix-allowlist.txt"

if [ "$failures" -gt 0 ]; then
    printf '\n%d check(s) failed.\n' "$failures" >&2
    exit 1
fi

printf '\nAll migration prefix guard checks passed.\n'
