#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
RELEASE_SCRIPT="$ROOT_DIR/scripts/deploy-production-release.sh"
PREVIOUS_COMMIT=1111111111111111111111111111111111111111
CANDIDATE_COMMIT=2222222222222222222222222222222222222222
PREVIOUS_IMAGE=ghcr.io/example/telegram-bot@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
LEGACY_PREVIOUS_IMAGE=telegram-bot-prod-app
CANDIDATE_IMAGE=ghcr.io/example/telegram-bot@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb

grep -Fq 'ops:check-failed-jobs --snapshot </dev/null' "$RELEASE_SCRIPT" \
  || { printf 'FAIL: failed-job snapshot can consume the streamed SSH script stdin\n' >&2; exit 1; }
grep -Fq 'php artisan migrate --force </dev/null' "$RELEASE_SCRIPT" \
  || { printf 'FAIL: candidate migration can consume the streamed SSH script stdin\n' >&2; exit 1; }

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

assert_eq() {
  [ "$1" = "$2" ] || fail "expected '$2', got '$1'"
}

run_scenario() {
  local scenario=$1 expected_exit=$2 expected_result=$3 expected_image=$4
  local previous_image=${5:-$PREVIOUS_IMAGE}
  local workspace bin artifacts status
  workspace=$(mktemp -d)
  bin="$workspace/bin"
  artifacts="$workspace/artifacts"
  mkdir -p "$bin" "$artifacts" "$workspace/scripts"
  printf '%s\n' "$PREVIOUS_COMMIT" > "$workspace/head"
  printf '%s\n' "$previous_image" > "$workspace/current-image"
  printf '%s\n' "$scenario" > "$workspace/scenario"
  : > "$workspace/commands.log"
  : > "$workspace/.env"

  cat > "$workspace/scripts/safe-compose-config.sh" <<'SCRIPT'
#!/usr/bin/env bash
exit 0
SCRIPT
  chmod +x "$workspace/scripts/safe-compose-config.sh"
  cp "$ROOT_DIR/scripts/redact-release-diagnostics.sh" "$workspace/scripts/redact-release-diagnostics.sh"
  chmod +x "$workspace/scripts/redact-release-diagnostics.sh"

  cat > "$bin/git" <<'SCRIPT'
#!/usr/bin/env bash
set -euo pipefail
printf 'git %s\n' "$*" >> "$MOCK_WORKSPACE/commands.log"
case "${1:-}" in
  fetch|cat-file) exit 0 ;;
  rev-parse) cat "$MOCK_WORKSPACE/head" ;;
  checkout)
    [ "${2:-}" = "--detach" ] && printf '%s\n' "$3" > "$MOCK_WORKSPACE/head"
    ;;
  reset)
    [ "${2:-}" = "--hard" ] && printf '%s\n' "$3" > "$MOCK_WORKSPACE/head"
    ;;
esac
SCRIPT

  cat > "$bin/docker" <<'SCRIPT'
#!/usr/bin/env bash
set -euo pipefail
printf 'docker %s APP_IMAGE=%s\n' "$*" "${APP_IMAGE:-}" >> "$MOCK_WORKSPACE/commands.log"
scenario=$(cat "$MOCK_WORKSPACE/scenario")
current_image=$(cat "$MOCK_WORKSPACE/current-image")
image_id() {
  case "$1" in
    *aaaaaaaaaaaaaaaa*) printf 'sha256:previous-id\n' ;;
    *bbbbbbbbbbbbbbbb*) printf 'sha256:candidate-id\n' ;;
    *) printf 'sha256:unknown-id\n' ;;
  esac
}

if [ "${1:-}" = "pull" ]; then exit 0; fi
if [ "${1:-}" = "image" ] && [ "${2:-}" = "inspect" ]; then image_id "$3"; exit 0; fi
if [ "${1:-}" = "inspect" ]; then
  if printf '%s' "$*" | grep -q '{{.Config.Image}}'; then cat "$MOCK_WORKSPACE/current-image"; else image_id "$current_image"; fi
  exit 0
fi
if [ "${1:-}" = "ps" ]; then exit 0; fi
if [ "${1:-}" != "compose" ]; then exit 0; fi

shift
while [ "$#" -gt 0 ]; do
  case "$1" in
    -p|--env-file) shift 2 ;;
    *) break ;;
  esac
done
command=${1:-}
shift || true
case "$command" in
  ps)
    if [ "${1:-}" = "-q" ]; then printf '%s-container\n' "${2:-app}"; fi
    ;;
  run)
    if [ "$scenario" = "migration-failure" ] && printf '%s' "$*" | grep -q 'artisan migrate --force'; then exit 1; fi
    ;;
  up)
    printf '%s\n' "${APP_IMAGE:?}" > "$MOCK_WORKSPACE/current-image"
    ;;
  exec)
    current_image=$(cat "$MOCK_WORKSPACE/current-image")
    if printf '%s' "$*" | grep -q 'ops:health'; then
      if [ "$scenario" = "application-health-failure" ] && printf '%s' "$current_image" | grep -q bbbbbbbb; then exit 1; fi
      if [ "$scenario" = "rollback-failure" ]; then exit 1; fi
    fi
    ;;
  logs) ;;
esac
SCRIPT
  chmod +x "$bin/git" "$bin/docker"

  set +e
  PATH="$bin:$PATH" MOCK_WORKSPACE="$workspace" PRODUCTION_PATH="$workspace" DEPLOY_BRANCH=main \
    DEPLOY_SHA="$CANDIDATE_COMMIT" COMPOSE_PROJECT_NAME=telegram-bot-prod APP_IMAGE="$CANDIDATE_IMAGE" \
    RELEASE_ARTIFACT_DIR="$artifacts" RELEASE_HEALTH_ATTEMPTS=1 RELEASE_HEALTH_INTERVAL=0 \
    GHCR_USERNAME=ci-bot GHCR_TOKEN=ci-token \
    bash "$RELEASE_SCRIPT" > "$workspace/stdout" 2> "$workspace/stderr"
  status=$?
  set -e

  if [ "$scenario" = "external-smoke-rollback" ] && [ "$status" -eq 0 ]; then
    set +e
    PATH="$bin:$PATH" MOCK_WORKSPACE="$workspace" PRODUCTION_PATH="$workspace" \
      COMPOSE_PROJECT_NAME=telegram-bot-prod RELEASE_ARTIFACT_DIR="$artifacts" \
      RELEASE_OPERATION=rollback FAILURE_REASON=external-smoke-failed \
      bash "$RELEASE_SCRIPT" >> "$workspace/stdout" 2>> "$workspace/stderr"
    status=$?
    set -e
  fi

  assert_eq "$status" "$expected_exit"
  assert_eq "$(cat "$artifacts/release-result.txt")" "$expected_result"
  assert_eq "$(cat "$workspace/current-image")" "$expected_image"
  assert_eq "$(cat "$artifacts/previous-commit.txt")" "$PREVIOUS_COMMIT"
  assert_eq "$(cat "$artifacts/previous-image.txt")" "$previous_image"
  [ -x "$artifacts/redact-release-diagnostics.sh" ] || fail "artifact-local redactor is missing or not executable"
  [ -f "$artifacts/compose.log" ] || fail "release diagnostics did not produce a redacted compose log"

  case "$scenario" in
    success)
      grep -q 'compose .* run --rm --no-deps .*artisan migrate --force' "$workspace/commands.log" || fail "success did not run explicit migration"
      ;;
    migration-failure)
      ! grep -q 'compose .* up -d' "$workspace/commands.log" || fail "migration failure cut over application services"
      ;;
    application-health-failure)
      grep -q "APP_IMAGE=$previous_image" "$workspace/commands.log" || fail "health failure did not restore previous image"
      grep -q 'rollback succeeded' "$workspace/stderr" || fail "successful rollback outcome was not explicit"
      ;;
    rollback-failure)
      grep -q 'rollback failed' "$workspace/stderr" || fail "failed rollback outcome was not explicit"
      ;;
    external-smoke-rollback)
      grep -q "APP_IMAGE=$previous_image" "$workspace/commands.log" || fail "external smoke rollback did not restore previous image"
      grep -q 'external-smoke-failed' "$workspace/stderr" || fail "external smoke rollback reason was not explicit"
      ;;
  esac

  rm -rf "$workspace"
  printf 'PASS: %s\n' "$scenario"
}

run_scenario success 0 deploy-succeeded "$CANDIDATE_IMAGE"
run_scenario legacy-image-success 0 deploy-succeeded "$CANDIDATE_IMAGE" "$LEGACY_PREVIOUS_IMAGE"
run_scenario migration-failure 1 migration-failed-no-cutover "$PREVIOUS_IMAGE"
run_scenario application-health-failure 20 deploy-failed-rollback-succeeded "$PREVIOUS_IMAGE"
run_scenario rollback-failure 21 deploy-failed-rollback-failed "$PREVIOUS_IMAGE"
run_scenario external-smoke-rollback 0 external-smoke-failed-rollback-succeeded "$PREVIOUS_IMAGE"
