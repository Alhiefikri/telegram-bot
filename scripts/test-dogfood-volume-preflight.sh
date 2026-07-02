#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
ROOT_DIR=$(dirname -- "$SCRIPT_DIR")
SUBJECT="$ROOT_DIR/scripts/dogfood-volume-preflight.sh"

TMP_DIR=$(mktemp -d "${TMPDIR:-/tmp}/dogfood-volume-preflight-test.XXXXXX")
trap 'rm -rf "$TMP_DIR"' EXIT

LAST_STDOUT="$TMP_DIR/stdout"
LAST_STDERR="$TMP_DIR/stderr"

make_fake_docker() {
  local scenario=$1
  local log_file=$TMP_DIR/docker-$scenario.log
  local fake_docker=$TMP_DIR/docker-$scenario

  cat > "$fake_docker" <<'FAKE'
#!/usr/bin/env bash
set -euo pipefail

printf '%s\n' "$*" >> "$FAKE_DOCKER_LOG"

if [ "$1 $2" = "volume ls" ]; then
  case "$FAKE_DOCKER_SCENARIO" in
    none)
      exit 0
      ;;
    volumes)
      printf 'telegram-bot-dogfood_postgres_data\n'
      printf 'telegram-bot-dogfood_laravel_storage\n'
      exit 0
      ;;
    ci-volumes)
      printf 'telegram-bot-dogfood-ci_postgres_data\n'
      exit 0
      ;;
  esac
fi

if [ "$1 $2" = "volume rm" ]; then
  exit 0
fi

printf 'Unexpected fake docker call: %s\n' "$*" >&2
exit 99
FAKE

  chmod +x "$fake_docker"
  : > "$log_file"
  printf '%s\n' "$fake_docker"
}

run_subject() {
  local scenario=$1
  shift

  local fake_docker
  fake_docker=$(make_fake_docker "$scenario")
  FAKE_DOCKER_SCENARIO=$scenario \
    FAKE_DOCKER_LOG="$TMP_DIR/docker-$scenario.log" \
    DOCKER_BIN="$fake_docker" \
    "$SUBJECT" "$@" >"$LAST_STDOUT" 2>"$LAST_STDERR"
}

assert_exit() {
  local expected=$1
  local actual=$2
  local label=$3

  if [ "$actual" -ne "$expected" ]; then
    printf 'FAIL: %s expected exit %s, got %s\n' "$label" "$expected" "$actual" >&2
    printf 'stdout:\n' >&2
    cat "$LAST_STDOUT" >&2 || true
    printf 'stderr:\n' >&2
    cat "$LAST_STDERR" >&2 || true
    exit 1
  fi
}

assert_contains() {
  local file=$1
  local needle=$2
  local label=$3

  if ! grep -Fq -- "$needle" "$file"; then
    printf 'FAIL: %s missing "%s" in %s\n' "$label" "$needle" "$file" >&2
    cat "$file" >&2 || true
    exit 1
  fi
}

assert_not_contains() {
  local file=$1
  local needle=$2
  local label=$3

  if grep -Fq -- "$needle" "$file"; then
    printf 'FAIL: %s unexpectedly found "%s" in %s\n' "$label" "$needle" "$file" >&2
    cat "$file" >&2 || true
    exit 1
  fi
}

status=0
run_subject none || status=$?
assert_exit 0 "$status" 'no existing dogfood volumes passes'
assert_contains "$LAST_STDOUT" 'No existing dogfood project volumes found.' 'no-volume preflight output'

status=0
run_subject volumes || status=$?
assert_exit 1 "$status" 'existing volumes are detected'
assert_contains "$LAST_STDOUT" 'telegram-bot-dogfood_postgres_data' 'existing volume list'
assert_not_contains "$TMP_DIR/docker-volumes.log" 'volume rm' 'preflight does not remove'

status=0
run_subject volumes --reset || status=$?
assert_exit 2 "$status" 'reset requires confirmation'
assert_not_contains "$TMP_DIR/docker-volumes.log" 'volume rm' 'unconfirmed reset does not remove'

status=0
run_subject ci-volumes --project telegram-bot-dogfood-ci --reset --yes || status=$?
assert_exit 0 "$status" 'confirmed explicit dogfood project reset passes'
assert_contains "$TMP_DIR/docker-ci-volumes.log" 'volume rm telegram-bot-dogfood-ci_postgres_data' 'confirmed reset removes selected volume'

status=0
run_subject none --project telegram-bot --reset --yes || status=$?
assert_exit 2 "$status" 'non-dogfood project refused'
assert_not_contains "$TMP_DIR/docker-none.log" 'volume ls' 'non-dogfood project does not query docker'

printf 'dogfood-volume-preflight tests passed.\n'
