#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
ROOT_DIR=$(dirname -- "$SCRIPT_DIR")
SUBJECT="$ROOT_DIR/scripts/safe-compose-config.sh"

TMP_DIR=$(mktemp -d "${TMPDIR:-/tmp}/safe-compose-config-test.XXXXXX")
trap 'rm -rf "$TMP_DIR"' EXIT

LAST_STDOUT="$TMP_DIR/stdout"
LAST_STDERR="$TMP_DIR/stderr"
SECRET_SENTINEL='SHOULD_NOT_LEAK_SECRET_VALUE'

make_fake_docker() {
  local scenario=$1
  local log_file="$TMP_DIR/docker-$scenario.log"
  local fake_docker="$TMP_DIR/docker-$scenario"

  cat > "$fake_docker" <<'FAKE'
#!/usr/bin/env bash
set -euo pipefail

printf '%s\n' "$*" >> "$FAKE_DOCKER_LOG"

if [ "$1" = "compose" ]; then
  shift
  saw_config=false
  saw_quiet=false

  for arg in "$@"; do
    if [ "$arg" = "config" ]; then
      saw_config=true
    fi

    if [ "$arg" = "--quiet" ] || [ "$arg" = "-q" ]; then
      saw_quiet=true
    fi
  done

  if [ "$saw_config" = true ] && [ "$saw_quiet" = true ]; then
    exit 0
  fi

  if [ "$saw_config" = true ]; then
    printf 'resolved secret=%s\n' "$SECRET_SENTINEL"
    exit 0
  fi
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
  SECRET_SENTINEL="$SECRET_SENTINEL" \
    FAKE_DOCKER_LOG="$TMP_DIR/docker-$scenario.log" \
    DOCKER_BIN="$fake_docker" \
    "$SUBJECT" "$@" >"$LAST_STDOUT" 2>"$LAST_STDERR"
}

assert_exit() {
  local expected=$1
  local actual=$2
  local message=$3

  if [ "$actual" -ne "$expected" ]; then
    printf 'Assertion failed: %s. Expected exit %s, got %s.\n' "$message" "$expected" "$actual" >&2
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
  local message=$3

  if ! grep -Fq -- "$needle" "$file"; then
    printf 'Assertion failed: %s. Missing: %s\n' "$message" "$needle" >&2
    printf 'File contents:\n' >&2
    cat "$file" >&2 || true
    exit 1
  fi
}

assert_not_contains() {
  local file=$1
  local needle=$2
  local message=$3

  if grep -Fq -- "$needle" "$file"; then
    printf 'Assertion failed: %s. Unexpected: %s\n' "$message" "$needle" >&2
    printf 'File contents:\n' >&2
    cat "$file" >&2 || true
    exit 1
  fi
}

status=0
run_subject quiet --project telegram-bot-prod --env-file .env || status=$?
assert_exit 0 "$status" 'quiet compose config succeeds'
assert_contains "$TMP_DIR/docker-quiet.log" 'compose -p telegram-bot-prod --env-file .env config --quiet' 'docker compose config runs in quiet mode'
assert_contains "$LAST_STDOUT" 'Compose configuration is valid; resolved config intentionally not printed.' 'safe success output'
assert_not_contains "$LAST_STDOUT" "$SECRET_SENTINEL" 'stdout does not leak resolved environment values'
assert_not_contains "$LAST_STDERR" "$SECRET_SENTINEL" 'stderr does not leak resolved environment values'

status=0
COMPOSE_PROJECT_NAME=telegram-bot-dogfood COMPOSE_ENV_FILE=.env.dogfood run_subject quiet || status=$?
assert_exit 0 "$status" 'environment defaults are honored'
assert_contains "$TMP_DIR/docker-quiet.log" 'compose -p telegram-bot-dogfood --env-file .env.dogfood config --quiet' 'env defaults produce quiet config call'

printf 'safe-compose-config tests passed.\n'
