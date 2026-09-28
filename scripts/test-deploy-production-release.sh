#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
RELEASE_SCRIPT="$ROOT_DIR/scripts/deploy-production-release.sh"
PREVIOUS_COMMIT=1111111111111111111111111111111111111111
CANDIDATE_COMMIT=2222222222222222222222222222222222222222
PREVIOUS_IMAGE=ghcr.io/example/telegram-bot@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
LEGACY_PREVIOUS_IMAGE=telegram-bot-prod-app
LOCAL_PREVIOUS_IMAGE_ID=sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
CANDIDATE_IMAGE=ghcr.io/example/telegram-bot@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb


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
  local operation=deploy
  local recover_failed=false
  local bootstrap_local_rollback=false
  local initialize_fingerprint_key=false
  local use_persisted_fingerprint_key=false
  local recorded_previous_image="$previous_image"
  local workspace bin artifacts status
  [ "$scenario" = "recovery-failure" ] && recover_failed=true
  if [ "$scenario" = "legacy-image-bootstrap" ] || [ "$scenario" = "legacy-bootstrap-health-failure" ]; then
    bootstrap_local_rollback=true
    recorded_previous_image="$LOCAL_PREVIOUS_IMAGE_ID"
  fi
  [ "$scenario" = "initialize-fingerprint-key" ] && initialize_fingerprint_key=true
  [ "$scenario" = "use-persisted-fingerprint-key" ] && use_persisted_fingerprint_key=true
  case "$scenario" in
    contract-success|contract-failure|backup-failure) operation=contract ;;
    reconcile-success|reconcile-failure|reconcile-rollback-succeeded|reconcile-rollback-failed|reconcile-migration-failed|reconcile-backfill-failed) operation=reconcile ;;
  esac
  workspace=$(mktemp -d)
  bin="$workspace/bin"
  artifacts="$workspace/artifacts"
  mkdir -p "$bin" "$artifacts" "$workspace/scripts" "$workspace/src/database/migrations"
  cp "$ROOT_DIR/docker-compose.yml" "$workspace/docker-compose.yml"
  : > "$workspace/src/database/migrations/2026_08_17_000003_create_telegram_outbound_effects_table.php"
  : > "$workspace/src/database/migrations/2026_08_18_000001_contract_telegram_outbound_effects.php"
  if [ "$operation" = "contract" ] || [ "$operation" = "reconcile" ]; then
    if [ "$scenario" = "reconcile-rollback-succeeded" ] || [ "$scenario" = "reconcile-migration-failed" ] || [ "$scenario" = "reconcile-backfill-failed" ]; then
      printf '%s\n' "$PREVIOUS_COMMIT" > "$workspace/head"
      printf '%s\n' "$PREVIOUS_IMAGE" > "$workspace/current-image"
    else
      printf '%s\n' "$CANDIDATE_COMMIT" > "$workspace/head"
      printf '%s\n' "$CANDIDATE_IMAGE" > "$workspace/current-image"
    fi
  else
    printf '%s\n' "$PREVIOUS_COMMIT" > "$workspace/head"
    printf '%s\n' "$previous_image" > "$workspace/current-image"
  fi
  printf '%s\n' "$scenario" > "$workspace/scenario"
  : > "$workspace/commands.log"
  : > "$workspace/.env"
  if [ "$scenario" = "use-persisted-fingerprint-key" ]; then
    printf 'CREDENTIAL_IDENTITY_FINGERPRINT_KEY=%064d\n' 0 > "$workspace/.env"
  fi
  if [ "$operation" = "reconcile" ]; then
    printf '%s\n' "$PREVIOUS_COMMIT" > "$artifacts/previous-commit.txt"
    printf '%s\n' "$PREVIOUS_IMAGE" > "$artifacts/previous-image.txt"
    if [ "$scenario" = "reconcile-rollback-succeeded" ]; then
      printf 'state=rollback-succeeded\n' > "$artifacts/release-state.env"
      printf 'deploy-failed-rollback-succeeded\n' > "$artifacts/release-result.txt"
    elif [ "$scenario" = "reconcile-rollback-failed" ]; then
      printf 'state=rollback-failed\n' > "$artifacts/release-state.env"
      printf 'deploy-failed-rollback-failed\n' > "$artifacts/release-result.txt"
    elif [ "$scenario" = "reconcile-migration-failed" ]; then
      printf 'state=migration-failed-no-cutover\n' > "$artifacts/release-state.env"
      printf 'migration-failed-no-cutover\n' > "$artifacts/release-result.txt"
    elif [ "$scenario" = "reconcile-backfill-failed" ]; then
      printf 'state=credential-identity-backfill-failed-no-cutover\n' > "$artifacts/release-state.env"
      printf 'credential-identity-backfill-failed-no-cutover\n' > "$artifacts/release-result.txt"
    else
      printf 'state=candidate-cutover-started\n' > "$artifacts/release-state.env"
    fi
  fi


  cat > "$workspace/scripts/safe-compose-config.sh" <<'SCRIPT'
#!/usr/bin/env bash
if [ "$(cat "$MOCK_WORKSPACE/scenario")" = "compose-validation-failure" ]; then
  exit 1
fi
printf 'Compose configuration is valid; resolved config intentionally not printed.\n'
exit 0
SCRIPT
  chmod +x "$workspace/scripts/safe-compose-config.sh"
  cp "$ROOT_DIR/scripts/redact-release-diagnostics.sh" "$workspace/scripts/redact-release-diagnostics.sh"
  chmod +x "$workspace/scripts/redact-release-diagnostics.sh"
  cat > "$workspace/scripts/backup-db.sh" <<'SCRIPT'
#!/usr/bin/env bash
set -euo pipefail
if [ "$(cat "$MOCK_WORKSPACE/scenario")" = "backup-failure" ]; then
  exit 1
fi
mkdir -p "$BACKUP_DIR"
outfile="$BACKUP_DIR/telegram_bot_test.sql.gz.gpg"
printf 'encrypted backup fixture\n' > "$outfile"
printf 'Backup written to %s\n' "$outfile"
printf 'Backup verification: encrypted-and-readable\n'
SCRIPT
  chmod +x "$workspace/scripts/backup-db.sh"


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
    *aaaaaaaaaaaaaaaa*) printf 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n' ;;
    *bbbbbbbbbbbbbbbb*) printf 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb\n' ;;
    *telegram-bot-prod-app*) printf 'sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc\n' ;;
    *) printf 'sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd\n' ;;
  esac
}

if [ "${1:-}" = "pull" ]; then exit 0; fi
if [ "${1:-}" = "image" ] && [ "${2:-}" = "inspect" ]; then image_id "$3"; exit 0; fi
if [ "${1:-}" = "inspect" ]; then
  if printf '%s' "$*" | grep -q '{{range .Config.Env}}'; then
    printf 'QUEUE_CONNECTION=database\n'
  elif printf '%s' "$*" | grep -q '{{.Config.Image}}'; then
    cat "$MOCK_WORKSPACE/current-image"
  else
    image_id "$current_image"
  fi
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
    if [ "$scenario" = "redis-backlog-rollback" ] && printf '%s' "$*" | grep -q 'ops:check-queue-depth.*include-reserved-and-delayed'; then exit 1; fi
    if [ "$scenario" = "migration-failure" ] && printf '%s' "$*" | grep -q 'artisan migrate --force'; then exit 1; fi
    if [ "$scenario" = "identity-backfill-key-failure" ] && printf '%s' "$*" | grep -q 'credentials:backfill-identity-registry'; then
      printf 'Credential identity backfill failed: fingerprint key could not be prepared.\n'
      exit 1
    fi
    if [ "$scenario" = "identity-backfill-failure" ] && printf '%s' "$*" | grep -q 'credentials:backfill-identity-registry'; then
      printf 'SUMMARY mode=commit scanned=4 registered=1 already_registered=0 skipped_unrecognized=1 conflicts=1 unresolved=1 last_id=4 ready=false\n'
      printf 'UNRESOLVED_STOCK_ITEM_IDS 2\n'
      printf 'CONFLICT_STOCK_ITEM_IDS 1,2\n'
      exit 1
    fi
    if [ "$scenario" = "identity-backfill-failure" ] && printf '%s' "$*" | grep -q 'credentials:diagnose-identity-conflicts'; then
      printf '{"requested_stock_item_ids":[1,2],"summary":{"conflict_identity_count":2},"items":[]}\n'
    fi
    if [ "$scenario" = "contract-failure" ] && printf '%s' "$*" | grep -q 'artisan migrate --force'; then exit 1; fi
    ;;
  stop) ;;
  up)
    printf '%s\n' "${APP_IMAGE:?}" > "$MOCK_WORKSPACE/current-image"
    ;;
  exec)
    current_image=$(cat "$MOCK_WORKSPACE/current-image")
    if printf '%s' "$*" | grep -q 'release-sha.txt'; then
      printf '%s\n' "$(cat "$MOCK_WORKSPACE/head")" > "$MOCK_WORKSPACE/release-marker"
      if printf '%s' "$*" | grep -q 'cat /var/www/html/public/release-sha.txt'; then
        cat "$MOCK_WORKSPACE/release-marker"
      fi
    fi
    if [ "$scenario" = "queue-failure" ] && printf '%s' "$*" | grep -q 'ops:check-queue-depth'; then
      exit 1
    fi
    if [ "$scenario" = "recovery-failure" ] && printf '%s' "$*" | grep -q 'recover-failed-updates'; then
      exit 1
    fi
    if printf '%s' "$*" | grep -q 'ops:health'; then
      if { [ "$scenario" = "application-health-failure" ] || [ "$scenario" = "legacy-bootstrap-health-failure" ] || [ "$scenario" = "reconcile-failure" ] || [ "$scenario" = "redis-backlog-rollback" ]; } && printf '%s' "$current_image" | grep -q bbbbbbbb; then exit 1; fi
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
    RELEASE_OPERATION="$operation" RECOVER_FAILED_TELEGRAM_UPDATES="$recover_failed" BOOTSTRAP_LOCAL_ROLLBACK_IMAGE="$bootstrap_local_rollback" INITIALIZE_CREDENTIAL_FINGERPRINT_KEY="$initialize_fingerprint_key" USE_PERSISTED_CREDENTIAL_FINGERPRINT_KEY="$use_persisted_fingerprint_key" \
    RELEASE_ARTIFACT_DIR="$artifacts" RELEASE_HEALTH_ATTEMPTS=1 RELEASE_HEALTH_INTERVAL=0 \
    GPG_RECIPIENT=backup@example.test \
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

  if [ "$scenario" = "legacy-image-rejected" ]; then
    [ "$status" -ne 0 ] || fail "legacy rollback image was accepted"
    grep -q 'immutable GHCR digest reference' "$workspace/stderr" || fail "legacy rollback rejection was not explicit"
    rm -rf "$workspace"
    printf 'PASS: %s\n' "$scenario"
    return
  fi

  if [ "$scenario" = "compose-validation-failure" ]; then
    [ "$status" -ne 0 ] || fail "invalid Compose configuration was accepted"
    ! grep -q '^docker ' "$workspace/commands.log" || fail "Compose action ran before safe validation"
    grep -q 'compose-validation-failed' "$artifacts/compose-ps.json" || fail "diagnostics attempted Compose after validation failure"
    rm -rf "$workspace"
    printf 'PASS: %s\n' "$scenario"
    return
  fi

  assert_eq "$status" "$expected_exit"
  assert_eq "$(cat "$artifacts/release-result.txt")" "$expected_result"
  assert_eq "$(cat "$workspace/current-image")" "$expected_image"
  if [ "$operation" = "deploy" ] || [ "$operation" = "reconcile" ]; then
    assert_eq "$(cat "$artifacts/previous-commit.txt")" "$PREVIOUS_COMMIT"
    assert_eq "$(cat "$artifacts/previous-image.txt")" "$recorded_previous_image"
  fi
  [ -x "$artifacts/redact-release-diagnostics.sh" ] || fail "artifact-local redactor is missing or not executable"
  [ -f "$artifacts/compose.log" ] || fail "release diagnostics did not produce a redacted compose log"

  case "$scenario" in
    success)
      grep -Eq 'ops:check-telegram-webhook --max-pending=25 --observation-seconds=30 --sample-interval=5 --tolerate-errors-since=[0-9]+' "$workspace/commands.log" || fail "success did not pass the candidate cutover timestamp to the webhook gate non-interactively"
      grep -q 'compose .* run .*--rm --no-deps --entrypoint php .*artisan migrate --force' "$workspace/commands.log" || fail "success did not run explicit migration without the application entrypoint"
      grep -q 'credentials:backfill-identity-registry --chunk=100' "$workspace/commands.log" || fail "success did not prepare the credential identity registry"
      grep -q 'database/migrations/2026_08_17_000003_create_telegram_outbound_effects_table.php' "$workspace/commands.log" || fail "success did not run the additive migration"
      ! grep -q 'database/migrations/2026_08_18_000001_contract_telegram_outbound_effects.php' "$workspace/commands.log" || fail "success ran the deferred contract migration before cutover"
      ;;
    legacy-image-bootstrap)
      grep -q 'content-addressed local rollback image' "$workspace/stdout" || fail "legacy rollback bootstrap was not explicit"
      ;;
    initialize-fingerprint-key)
      grep -Eq '^CREDENTIAL_IDENTITY_FINGERPRINT_KEY=[0-9a-f]{64}$' "$workspace/.env" || fail "fingerprint key was not initialized safely"
      grep -q 'Initialized CREDENTIAL_IDENTITY_FINGERPRINT_KEY directly on the VPS' "$workspace/stdout" || fail "fingerprint initialization was not explicit"
      ;;
    use-persisted-fingerprint-key)
      ! grep -q '^CREDENTIAL_IDENTITY_FINGERPRINT_KEY=' "$workspace/.env" || fail "fingerprint override was not removed"
      grep -q 'Removed CREDENTIAL_IDENTITY_FINGERPRINT_KEY override; using the persisted production key.' "$workspace/stdout" || fail "persisted fingerprint selection was not explicit"
      ;;
    identity-backfill-key-failure)
      grep -q '^status=unavailable reason=fingerprint-key-preparation-failed$' "$artifacts/credential-identity-backfill-summary.txt" || fail "backfill startup failure was not classified without raw output"
      ! grep -q 'compose .* up -d' "$workspace/commands.log" || fail "backfill startup failure cut over application services"
      ;;
    identity-backfill-failure)
      grep -q '^SUMMARY mode=commit scanned=4 registered=1 already_registered=0 skipped_unrecognized=1 conflicts=1 unresolved=1 last_id=4 ready=false$' "$artifacts/credential-identity-backfill-summary.txt" || fail "backfill summary artifact is not sanitized"
      grep -q '^CONFLICT_STOCK_ITEM_IDS 1,2$' "$artifacts/credential-identity-backfill-summary.txt" || fail "backfill conflict IDs were not retained safely"
      grep -q 'credentials:diagnose-identity-conflicts 1,2 --json' "$workspace/commands.log" || fail "read-only conflict diagnostic was not invoked"
      grep -q 'requested_stock_item_ids' "$artifacts/credential-identity-conflicts.json" || fail "sanitized conflict diagnostic artifact is missing"
      ! grep -q 'compose .* up -d' "$workspace/commands.log" || fail "identity backfill failure cut over application services"
      ;;
    application-health-failure|legacy-bootstrap-health-failure)
      grep -q "APP_IMAGE=$recorded_previous_image" "$workspace/commands.log" || fail "health failure did not restore previous image"
      grep -q 'rollback succeeded' "$workspace/stderr" || fail "successful rollback outcome was not explicit"
      ;;
    rollback-failure)
      grep -q 'rollback failed' "$workspace/stderr" || fail "failed rollback outcome was not explicit"
      ;;
    external-smoke-rollback)
      grep -q "APP_IMAGE=$previous_image" "$workspace/commands.log" || fail "external smoke rollback did not restore previous image"
      grep -q 'external-smoke-failed' "$workspace/stderr" || fail "external smoke rollback reason was not explicit"
      ;;
    queue-failure)
      grep -q "APP_IMAGE=$previous_image" "$workspace/commands.log" || fail "queue gate failure did not restore previous image"
      grep -q 'rollback succeeded' "$workspace/stderr" || fail "queue gate failure did not report rollback"
      ;;
    redis-backlog-rollback)
      grep -q 'Rollback blocked: Redis queue is not empty' "$workspace/stderr" || fail "rollback did not preserve a non-empty Redis queue"
      ! grep -q "APP_IMAGE=$previous_image compose up" "$workspace/commands.log" || fail "rollback switched to database after a Redis queue check failed"
      ;;
    recovery-failure)
      grep -q "APP_IMAGE=$previous_image" "$workspace/commands.log" || fail "recovery gate failure did not restore previous image"
      ;;
    reconcile-success)
      grep -q 'Remote release reconciliation proved the candidate is healthy' "$workspace/stdout" || fail "remote reconciliation did not prove the candidate"
      ;;
    reconcile-rollback-succeeded)
      grep -q 'Remote release reconciliation confirmed a healthy rollback' "$workspace/stdout" || fail "remote reconciliation did not recognize the completed rollback"
      grep -qx 'RELEASE_RECONCILIATION_STATE=rollback-succeeded' <(grep '^RELEASE_RECONCILIATION_STATE=' "$workspace/stdout") || fail "remote reconciliation did not expose the rollback terminal state"
      grep -qx 'state=rollback-succeeded' "$artifacts/release-state.env" || fail "remote reconciliation changed the completed rollback state"
      ;;
    reconcile-rollback-failed)
      grep -q 'Remote release journal records a failed rollback' "$workspace/stderr" || fail "remote reconciliation did not report the failed rollback state"
      ! grep -q 'unknown state' "$workspace/stderr" || fail "remote reconciliation treated rollback-failed as an unknown state"
      grep -qx 'state=rollback-failed' "$artifacts/release-state.env" || fail "remote reconciliation changed the failed rollback state"
      ;;
    reconcile-migration-failed|reconcile-backfill-failed)
      grep -q 'Remote release journal records a terminal failure before cutover' "$workspace/stderr" || fail "remote reconciliation did not recognize the pre-cutover terminal failure"
      ! grep -q 'unknown state' "$workspace/stderr" || fail "remote reconciliation treated a pre-cutover terminal state as unknown"
      grep -q '^state=.*failed-no-cutover$' "$artifacts/release-state.env" || fail "remote reconciliation changed the pre-cutover terminal state"
      ;;
    reconcile-failure)
      grep -q "APP_IMAGE=$previous_image" "$workspace/commands.log" || fail "remote reconciliation failure did not restore previous image"
      grep -q 'remote-state-reconcile' "$workspace/stderr" || fail "remote reconciliation rollback reason was not explicit"
      ;;
    contract-success)
      [ -s "$artifacts/contract-backup-verified.txt" ] || fail "contract success did not record verified backup evidence"
      grep -q 'contract-migration-started' "$artifacts/release-journal.log" || fail "contract journal did not record migration state"
      ;;
    contract-failure|backup-failure)
      [ "$(cat "$artifacts/release-result.txt")" = "contract-failed-candidate-remains" ] || fail "contract failure result was not durable"
      ;;
  esac

  rm -rf "$workspace"
  printf 'PASS: %s\n' "$scenario"
}
run_scenario compose-validation-failure 1 unused "$PREVIOUS_IMAGE"

run_scenario success 0 deploy-succeeded-compatible-contract-pending "$CANDIDATE_IMAGE"
run_scenario reconcile-success 0 deploy-succeeded-compatible-contract-pending "$CANDIDATE_IMAGE"
run_scenario reconcile-failure 1 remote-state-reconcile-rollback-succeeded "$PREVIOUS_IMAGE"
run_scenario reconcile-rollback-succeeded 0 deploy-failed-rollback-succeeded "$PREVIOUS_IMAGE"
run_scenario reconcile-rollback-failed 1 deploy-failed-rollback-failed "$CANDIDATE_IMAGE"
run_scenario reconcile-migration-failed 1 migration-failed-no-cutover "$PREVIOUS_IMAGE"
run_scenario reconcile-backfill-failed 1 credential-identity-backfill-failed-no-cutover "$PREVIOUS_IMAGE"
run_scenario legacy-image-rejected 1 unused "$CANDIDATE_IMAGE" "$LEGACY_PREVIOUS_IMAGE"
run_scenario legacy-image-bootstrap 0 deploy-succeeded-compatible-contract-pending "$CANDIDATE_IMAGE" "$LEGACY_PREVIOUS_IMAGE"
run_scenario legacy-bootstrap-health-failure 20 deploy-failed-rollback-succeeded "$LOCAL_PREVIOUS_IMAGE_ID" "$LEGACY_PREVIOUS_IMAGE"
run_scenario initialize-fingerprint-key 0 deploy-succeeded-compatible-contract-pending "$CANDIDATE_IMAGE"
run_scenario migration-failure 1 migration-failed-no-cutover "$PREVIOUS_IMAGE"
run_scenario identity-backfill-failure 1 credential-identity-backfill-failed-no-cutover "$PREVIOUS_IMAGE"
run_scenario identity-backfill-key-failure 1 credential-identity-backfill-failed-no-cutover "$PREVIOUS_IMAGE"
run_scenario application-health-failure 20 deploy-failed-rollback-succeeded "$PREVIOUS_IMAGE"
run_scenario use-persisted-fingerprint-key 0 deploy-succeeded-compatible-contract-pending "$CANDIDATE_IMAGE"
run_scenario rollback-failure 21 deploy-failed-rollback-failed "$PREVIOUS_IMAGE"
run_scenario external-smoke-rollback 0 external-smoke-failed-rollback-succeeded "$PREVIOUS_IMAGE"
run_scenario queue-failure 20 deploy-failed-rollback-succeeded "$PREVIOUS_IMAGE"
run_scenario recovery-failure 20 deploy-failed-rollback-succeeded "$PREVIOUS_IMAGE"
run_scenario redis-backlog-rollback 21 deploy-failed-rollback-failed "$CANDIDATE_IMAGE"
run_scenario contract-success 0 deploy-succeeded "$CANDIDATE_IMAGE"
run_scenario contract-failure 1 contract-failed-candidate-remains "$CANDIDATE_IMAGE"
run_scenario backup-failure 1 contract-failed-candidate-remains "$CANDIDATE_IMAGE"
