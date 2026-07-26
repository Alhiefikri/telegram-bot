#!/usr/bin/env bash
# Rejects duplicate Laravel migration filename prefixes.
#
# Laravel orders migrations by filename and records applied migrations by name.
# Two files sharing the same YYYY_MM_DD_HHMMSS prefix leave their relative order
# to the filesystem sort, so a dependent pair can silently run in the wrong
# order on a fresh database. Renaming an already-applied migration is not a fix
# because the `migrations` table is keyed by filename; instead this guard keeps
# new migrations unambiguous while historical duplicates stay allowlisted below.
set -euo pipefail

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
ROOT_DIR=$(dirname -- "$SCRIPT_DIR")
MIGRATIONS_DIR=${MIGRATIONS_DIR:-"$ROOT_DIR/src/database/migrations"}
ALLOWLIST_FILE=${ALLOWLIST_FILE:-"$SCRIPT_DIR/migration-prefix-allowlist.txt"}

if [ ! -d "$MIGRATIONS_DIR" ]; then
    printf 'Migrations directory not found: %s\n' "$MIGRATIONS_DIR" >&2
    exit 1
fi

# Prefixes that already shipped to production. They must never be renamed, so
# they are tolerated here and must not grow.
allowlisted_prefixes=()
if [ -r "$ALLOWLIST_FILE" ]; then
    while IFS= read -r line || [ -n "$line" ]; do
        case "$line" in
            ''|\#*) continue ;;
        esac
        allowlisted_prefixes+=("$line")
    done < "$ALLOWLIST_FILE"
fi

is_allowlisted() {
    local candidate=$1
    local allowed
    for allowed in ${allowlisted_prefixes[@]+"${allowlisted_prefixes[@]}"}; do
        if [ "$candidate" = "$allowed" ]; then
            return 0
        fi
    done

    return 1
}

duplicate_prefixes=$(
    find "$MIGRATIONS_DIR" -maxdepth 1 -type f -name '*.php' -printf '%f\n' \
        | sed -n -E 's/^([0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6})_.*\.php$/\1/p' \
        | sort \
        | uniq -d
)

violations=0
for prefix in $duplicate_prefixes; do
    if is_allowlisted "$prefix"; then
        continue
    fi

    violations=$((violations + 1))
    printf 'Duplicate migration prefix %s is shared by:\n' "$prefix" >&2
    find "$MIGRATIONS_DIR" -maxdepth 1 -type f -name "${prefix}_*.php" -printf '  %f\n' | sort >&2
done

if [ "$violations" -gt 0 ]; then
    printf '\nGive each new migration a unique timestamp prefix so migration order is deterministic.\n' >&2
    printf 'Never rename an applied migration; Laravel keys the migrations table by filename.\n' >&2
    exit 1
fi

# Guard against allowlist rot: an entry that is no longer duplicated should go.
stale=0
for allowed in ${allowlisted_prefixes[@]+"${allowlisted_prefixes[@]}"}; do
    matches=$(find "$MIGRATIONS_DIR" -maxdepth 1 -type f -name "${allowed}_*.php" | wc -l)
    if [ "$matches" -lt 2 ]; then
        stale=$((stale + 1))
        printf 'Allowlisted prefix %s is no longer duplicated; remove it from %s\n' \
            "$allowed" "$(basename "$ALLOWLIST_FILE")" >&2
    fi
done

if [ "$stale" -gt 0 ]; then
    exit 1
fi

printf 'Migration prefixes are unique (%d historical duplicate(s) allowlisted).\n' \
    "${#allowlisted_prefixes[@]}"
