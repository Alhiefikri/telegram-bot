#!/usr/bin/env bash
set -euo pipefail

: "${PRODUCTION_PATH:?PRODUCTION_PATH is required}"
: "${COMPOSE_PROJECT_NAME:?COMPOSE_PROJECT_NAME is required}"
RELEASE_OPERATION="${RELEASE_OPERATION:-deploy}"
TELEGRAM_OUTBOUND_CONTRACT_MIGRATION="2026_08_18_000001_contract_telegram_outbound_effects.php"
if [ "$RELEASE_OPERATION" = "deploy" ]; then
  : "${DEPLOY_BRANCH:?DEPLOY_BRANCH is required}"
  : "${DEPLOY_SHA:?DEPLOY_SHA is required}"
  : "${APP_IMAGE:?APP_IMAGE is required}"
elif [ "$RELEASE_OPERATION" = "contract" ] || [ "$RELEASE_OPERATION" = "reconcile" ]; then
  : "${DEPLOY_SHA:?DEPLOY_SHA is required for $RELEASE_OPERATION}"
  : "${APP_IMAGE:?APP_IMAGE is required for $RELEASE_OPERATION}"
elif [ "$RELEASE_OPERATION" != "rollback" ]; then
  echo "Unsupported release operation: $RELEASE_OPERATION" >&2
  exit 2
fi

cd "$PRODUCTION_PATH"
RELEASE_ARTIFACT_DIR="${RELEASE_ARTIFACT_DIR:-.release-gate-artifacts/manual}"
case "$RELEASE_ARTIFACT_DIR" in
  /*) ;;
  *) RELEASE_ARTIFACT_DIR="$PRODUCTION_PATH/$RELEASE_ARTIFACT_DIR" ;;
esac
mkdir -p "$RELEASE_ARTIFACT_DIR"
REDACTOR_PATH="$RELEASE_ARTIFACT_DIR/redact-release-diagnostics.sh"
if [ -f ./scripts/redact-release-diagnostics.sh ]; then
  cp ./scripts/redact-release-diagnostics.sh "$REDACTOR_PATH"
else
  # The workflow streams this script over SSH before the production checkout is
  # updated. Older checkouts may not have the redactor file yet, so keep a
  # self-contained fallback to avoid losing rollback diagnostics.
  cat > "$REDACTOR_PATH" <<'RELEASE_DIAGNOSTIC_REDACTOR'
#!/usr/bin/env bash
set -euo pipefail

sed -E \
  -e '/(payload|request[_ -]?body|response[_ -]?body)/Id' \
  -e 's/(Authorization[[:space:]]*:[[:space:]]*)(Bearer[[:space:]]+)?[^[:space:],;]+/\1[REDACTED]/Ig' \
  -e 's/("?(token|secret|password|app[_-]?key|api[_-]?key|cookie|set-cookie)"?[[:space:]]*[=:][[:space:]]*)"[^"]*"/\1"[REDACTED]"/Ig' \
  -e 's/("?(token|secret|password|app[_-]?key|api[_-]?key|cookie|set-cookie)"?[[:space:]]*[=:][[:space:]]*)[^"[:space:],;]+/\1[REDACTED]/Ig' \
  -e 's/(Bearer[[:space:]]+)[[:alnum:]_.~+\/-]+/\1[REDACTED]/Ig' \
  -e 's/bot[0-9]{6,}:[[:alnum:]_-]+/bot[REDACTED]/g' \
  -e 's#([[:alpha:]][[:alnum:]+.-]*://)[^/@[:space:]]*:[^/@[:space:]]+@#\1[REDACTED]@#Ig' \
  -e 's/([?&](token|secret|password|key|api_key)=)[^&#[:space:]]+/\1[REDACTED]/Ig'
RELEASE_DIAGNOSTIC_REDACTOR
fi
chmod 0700 "$REDACTOR_PATH"
SAFE_COMPOSE_CONFIG="$RELEASE_ARTIFACT_DIR/safe-compose-config.sh"
if [ -x ./scripts/safe-compose-config.sh ]; then
  cp ./scripts/safe-compose-config.sh "$SAFE_COMPOSE_CONFIG"
else
  echo "Safe Compose validator is missing from the current release checkout." >&2
  exit 1
fi
chmod 0700 "$SAFE_COMPOSE_CONFIG"

COMPOSE_CONFIG_STATUS=unvalidated
RELEASE_STATE_FILE="$RELEASE_ARTIFACT_DIR/release-state.env"
CREDENTIAL_IDENTITY_BACKFILL_SUMMARY_FILE="$RELEASE_ARTIFACT_DIR/credential-identity-backfill-summary.txt"
CREDENTIAL_IDENTITY_DIAGNOSTIC_FILE="$RELEASE_ARTIFACT_DIR/credential-identity-conflicts.json"

validate_compose_config() {
  [ "$COMPOSE_CONFIG_STATUS" = validated ] && return 0
  if ! "$SAFE_COMPOSE_CONFIG" --project "$COMPOSE_PROJECT_NAME" --env-file .env >&2; then
    COMPOSE_CONFIG_STATUS=invalid
    return 1
  fi
  COMPOSE_CONFIG_STATUS=validated
}


compose() {
  validate_compose_config || return 1
  case "${1:-}" in
    exec|run)
      docker compose -p "$COMPOSE_PROJECT_NAME" --env-file .env "$1" -u laravel "${@:2}"
      ;;
    *)
      docker compose -p "$COMPOSE_PROJECT_NAME" --env-file .env "$@"
      ;;
  esac
}

compose_root() {
  validate_compose_config || return 1
  docker compose -p "$COMPOSE_PROJECT_NAME" --env-file .env "$@"
}

write_release_state() {
  local state="$1"
  local temporary="${RELEASE_STATE_FILE}.tmp"
  {
    printf 'state=%s\n' "$state"
    printf 'deploy_sha=%s\n' "${DEPLOY_SHA:-}"
    printf 'app_image=%s\n' "${APP_IMAGE:-}"
    printf 'previous_commit=%s\n' "${PREVIOUS_COMMIT:-}"
    printf 'previous_image=%s\n' "${PREVIOUS_IMAGE:-}"
    printf 'updated_at=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  } > "$temporary"
  chmod 0600 "$temporary"
  mv -f "$temporary" "$RELEASE_STATE_FILE"
  printf '%s state=%s deploy_sha=%s app_image=%s\n' \
    "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$state" "${DEPLOY_SHA:-}" "${APP_IMAGE:-}" \
    >> "$RELEASE_ARTIFACT_DIR/release-journal.log"
  chmod 0600 "$RELEASE_ARTIFACT_DIR/release-journal.log"
}

release_state_value() {
  local key="$1"
  [ -f "$RELEASE_STATE_FILE" ] || return 0
  sed -n "s/^${key}=//p" "$RELEASE_STATE_FILE" | sed -n '1p'
}

write_release_marker() {
  local release_sha="$1"
  compose exec -T app sh -c 'rm -f /var/www/html/public/release-sha.txt && printf "%s\n" "$1" > /var/www/html/public/release-sha.txt' \
    sh "$release_sha" </dev/null
}

verify_release_marker() {
  local expected_sha="$1"
  local actual_sha
  actual_sha="$(compose exec -T app sh -c 'cat /var/www/html/public/release-sha.txt' </dev/null)"
  [ "$actual_sha" = "$expected_sha" ]
}
verify_app_url() {
  [ -z "${EXPECTED_APP_URL:-}" ] && return 0
  local expected_app_url="${EXPECTED_APP_URL%/}"
  local actual_app_url
  actual_app_url="$(compose exec -T app sh -c 'printf "%s\n" "$APP_URL"' </dev/null)"
  actual_app_url="${actual_app_url%/}"
  [ "$actual_app_url" = "$expected_app_url" ] || {
    echo "Candidate APP_URL $actual_app_url does not match expected smoke URL $expected_app_url." >&2
    return 1
  }
}


is_immutable_image_reference() {
  local reference="$1"
  local digest="${reference##*@sha256:}"
  case "$reference" in
    ghcr.io/*@sha256:*) ;;
    *) return 1 ;;
  esac
  [ "${#digest}" -eq 64 ] && [[ "$digest" =~ ^[0-9a-f]+$ ]]
}

is_content_addressed_local_image() {
  local reference="$1"
  local digest="${reference#sha256:}"
  case "$reference" in
    sha256:*) ;;
    *) return 1 ;;
  esac
  [ "${#digest}" -eq 64 ] && [[ "$digest" =~ ^[0-9a-f]+$ ]]
}

is_rollback_image_reference() {
  is_immutable_image_reference "$1" || is_content_addressed_local_image "$1"
}

initialize_credential_identity_fingerprint_key() {
  local key line
  local replaced=false
  local temporary

  if grep -Eq '^CREDENTIAL_IDENTITY_FINGERPRINT_KEY=[0-9a-f]{64}$' .env; then
    return 0
  fi
  [ "${INITIALIZE_CREDENTIAL_FINGERPRINT_KEY:-false}" = "true" ] || return 0

  key="$(openssl rand -hex 32)"
  [[ "$key" =~ ^[0-9a-f]{64}$ ]] || {
    echo "Failed to generate the production credential identity fingerprint key." >&2
    return 1
  }

  umask 077
  temporary="$(mktemp .env.XXXXXX)"
  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
      CREDENTIAL_IDENTITY_FINGERPRINT_KEY=*)
        if [ "$replaced" = false ]; then
          printf 'CREDENTIAL_IDENTITY_FINGERPRINT_KEY=%s\n' "$key" >> "$temporary"
          replaced=true
        fi
        ;;
      *) printf '%s\n' "$line" >> "$temporary" ;;
    esac
  done < .env
  if [ "$replaced" = false ]; then
    printf 'CREDENTIAL_IDENTITY_FINGERPRINT_KEY=%s\n' "$key" >> "$temporary"
  fi
  mv -f "$temporary" .env
  echo "Initialized CREDENTIAL_IDENTITY_FINGERPRINT_KEY directly on the VPS."
}


run_verified_database_backup() {
  local backup_log="$RELEASE_ARTIFACT_DIR/contract-backup.log"
  local backup_file verification checksum
  local backup_dir="$RELEASE_ARTIFACT_DIR/contract-backup"

  mkdir -p "$backup_dir"
  echo "Creating and verifying encrypted production backup before contract recovery."
  if ! BACKUP_DIR="$backup_dir" \
    COMPOSE="docker compose -p $COMPOSE_PROJECT_NAME --env-file .env" \
    COMPOSE_PROJECT_NAME="$COMPOSE_PROJECT_NAME" \
    COMPOSE_ENV_FILE=.env \
    COMPOSE_CONFIG_SCRIPT="$SAFE_COMPOSE_CONFIG" \
    ./scripts/backup-db.sh > "$backup_log"; then
    echo "Encrypted production backup failed; refusing destructive contract migration." >&2
    return 1
  fi

  backup_file="$(sed -n 's/^Backup written to //p' "$backup_log" | sed -n '1p')"
  verification="$(sed -n 's/^Backup verification: //p' "$backup_log" | sed -n '1p')"
  [ -n "$backup_file" ] && [ -s "$backup_file" ] || {
    echo "Encrypted production backup did not produce a readable file." >&2
    return 1
  }
  [ "$verification" = "encrypted-and-readable" ] || {
    echo "Encrypted production backup did not produce a verification marker." >&2
    return 1
  }

  checksum="$(sha256sum "$backup_file")"
  checksum="${checksum%% *}"
  [[ "$checksum" =~ ^[0-9a-f]{64}$ ]] || {
    echo "Encrypted production backup checksum is invalid." >&2
    return 1
  }

  {
    printf 'backup_file=%s\n' "$backup_file"
    printf 'sha256=%s\n' "$checksum"
    printf 'verification=%s\n' "$verification"
    printf 'verified_at=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  } > "$RELEASE_ARTIFACT_DIR/contract-backup-verified.txt"
  chmod 0600 "$RELEASE_ARTIFACT_DIR/contract-backup-verified.txt"
  echo "Encrypted production backup verified: sha256=$checksum"
}

run_expand_migrations() {
  local migration migration_name
  local -a migration_args=()

  shopt -s nullglob
  for migration in src/database/migrations/*.php; do
    migration_name="${migration##*/}"
    if [ "$migration_name" = "$TELEGRAM_OUTBOUND_CONTRACT_MIGRATION" ]; then
      continue
    fi
    migration_args+=(--path "database/migrations/$migration_name")
  done
  shopt -u nullglob

  if [ "${#migration_args[@]}" -eq 0 ]; then
    echo "No expand migrations found; refusing to run an unscoped migration." >&2
    return 1
  fi

  echo "Running expand migrations; deferred contract migration is excluded: $TELEGRAM_OUTBOUND_CONTRACT_MIGRATION"
  APP_IMAGE="$APP_IMAGE" compose run --rm --no-deps \
    -e APP_AUTO_MIGRATE=false \
    -e APP_OPTIMIZE=false \
    app php artisan migrate --force --isolated "${migration_args[@]}" </dev/null
}

run_credential_identity_backfill() {
  local raw_output status
  raw_output="$RELEASE_ARTIFACT_DIR/credential-identity-backfill.raw"

  echo "Preparing the credential identity registry for Account Input."
  status=0
  APP_IMAGE="$APP_IMAGE" compose run --rm --no-deps \
    -e APP_AUTO_MIGRATE=false \
    -e APP_OPTIMIZE=false \
    app php artisan credentials:backfill-identity-registry --chunk=100 </dev/null > "$raw_output" 2>&1 || status=$?

  # The backfill command has a safe output contract. Persist only the three
  # machine-readable lines that contain counts and row IDs; never retain raw
  # command stderr in release artifacts.
  sed -n -E \
    -e '/^SUMMARY mode=(dry_run|commit) scanned=[0-9]+ registered=[0-9]+ already_registered=[0-9]+ skipped_unrecognized=[0-9]+ conflicts=[0-9]+ unresolved=[0-9]+ last_id=[0-9]+ ready=(true|false)$/p' \
    -e '/^UNRESOLVED_STOCK_ITEM_IDS (none|[0-9]+(,[0-9]+)*)$/p' \
    -e '/^CONFLICT_STOCK_ITEM_IDS (none|[0-9]+(,[0-9]+)*)$/p' \
    "$raw_output" > "$CREDENTIAL_IDENTITY_BACKFILL_SUMMARY_FILE"
  if [ ! -s "$CREDENTIAL_IDENTITY_BACKFILL_SUMMARY_FILE" ]; then
    printf 'status=unavailable reason=backfill-summary-not-available\n' > "$CREDENTIAL_IDENTITY_BACKFILL_SUMMARY_FILE"
  fi
  chmod 0600 "$CREDENTIAL_IDENTITY_BACKFILL_SUMMARY_FILE"
  cat "$CREDENTIAL_IDENTITY_BACKFILL_SUMMARY_FILE"
  rm -f "$raw_output"

  return "$status"
}

run_credential_identity_conflict_diagnostic() {
  local ids diagnostic_output diagnostic_error status
  diagnostic_output="$CREDENTIAL_IDENTITY_DIAGNOSTIC_FILE.tmp"
  diagnostic_error="$CREDENTIAL_IDENTITY_DIAGNOSTIC_FILE.stderr"
  ids="$({
    sed -n -E 's/^(UNRESOLVED|CONFLICT)_STOCK_ITEM_IDS (.+)$/\2/p' "$CREDENTIAL_IDENTITY_BACKFILL_SUMMARY_FILE" \
      | tr ',' '\n'
  } | awk '/^[1-9][0-9]*$/ { print }' | sort -n -u | paste -sd, -)"

  if [ -z "$ids" ]; then
    printf '{"status":"unavailable","reason":"no-safe-conflict-ids"}\n' > "$CREDENTIAL_IDENTITY_DIAGNOSTIC_FILE"
    chmod 0600 "$CREDENTIAL_IDENTITY_DIAGNOSTIC_FILE"

    return 0
  fi

  echo "Running the read-only credential identity conflict diagnostic for stock item IDs: $ids"
  status=0
  APP_IMAGE="$APP_IMAGE" compose run --rm --no-deps \
    -e APP_AUTO_MIGRATE=false \
    -e APP_OPTIMIZE=false \
    app php artisan credentials:diagnose-identity-conflicts "$ids" --json </dev/null \
    > "$diagnostic_output" 2> "$diagnostic_error" || status=$?

  if [ "$status" -eq 0 ] && grep -q '^{' "$diagnostic_output"; then
    mv -f "$diagnostic_output" "$CREDENTIAL_IDENTITY_DIAGNOSTIC_FILE"
  else
    printf '{"status":"unavailable","reason":"diagnostic-command-failed"}\n' > "$CREDENTIAL_IDENTITY_DIAGNOSTIC_FILE"
    rm -f "$diagnostic_output"
  fi
  rm -f "$diagnostic_error"
  chmod 0600 "$CREDENTIAL_IDENTITY_DIAGNOSTIC_FILE"
  cat "$CREDENTIAL_IDENTITY_DIAGNOSTIC_FILE"

  return 0
}

run_deferred_telegram_contract() {
  local attempt
  local current_commit current_app_container running_image
  local before_report="$RELEASE_ARTIFACT_DIR/telegram-outbound-contract-before.json"
  local after_report="$RELEASE_ARTIFACT_DIR/telegram-outbound-contract-after.json"

  current_commit="$(git rev-parse HEAD)"
  if [ "$current_commit" != "$DEPLOY_SHA" ]; then
    echo "Deferred Telegram outbound contract requires candidate checkout $DEPLOY_SHA; found $current_commit." >&2
    return 1
  fi

  current_app_container="$(compose ps -q app)"
  [ -n "$current_app_container" ] || {
    echo "Deferred Telegram outbound contract cannot identify the running app container." >&2
    return 1
  }
  running_image="$(docker inspect "$current_app_container" --format '{{.Config.Image}}')"
  if [ "$running_image" != "$APP_IMAGE" ]; then
    echo "Deferred Telegram outbound contract found app image $running_image instead of $APP_IMAGE." >&2
    return 1
  fi
  verify_app_url || return 1
  write_release_state "backup-started"

  run_verified_database_backup || return 1
  write_release_state "backup-verified"


  echo "Recording Telegram outbound contract preflight before recovery."
  if ! compose exec -T app php artisan telegram:outbound-contract --json </dev/null > "$before_report"; then
    echo "Telegram outbound contract preflight failed." >&2
    return 1
  fi

  echo "Recovering eligible Telegram outbound effects before contract migration."
  if ! compose exec -T app php artisan telegram:recover-outbound --chunk=100 --queue=telegram-outbound </dev/null; then
    echo "Telegram outbound effect recovery failed." >&2
    return 1
  fi

  for attempt in $(seq 1 "${TELEGRAM_OUTBOUND_CONTRACT_ATTEMPTS:-36}"); do
    if compose exec -T app php artisan telegram:outbound-contract --json --strict </dev/null > "$after_report"; then
      break
    fi
    if [ "$attempt" -eq "${TELEGRAM_OUTBOUND_CONTRACT_ATTEMPTS:-36}" ]; then
      echo "Telegram outbound contract did not become migration-safe after recovery." >&2
      return 1
    fi
    sleep "${TELEGRAM_OUTBOUND_CONTRACT_INTERVAL:-5}"
  done

  write_release_state "contract-migration-started"
  echo "Applying deferred Telegram outbound contract migration."
  if ! APP_IMAGE="$APP_IMAGE" compose run --rm --no-deps \
    -e APP_AUTO_MIGRATE=false \
    -e APP_OPTIMIZE=false \
    app php artisan migrate --force --isolated </dev/null; then
    echo "Deferred Telegram outbound contract migration failed." >&2
    return 1
  fi
  write_release_state "contract-migration-succeeded"

  if ! compose exec -T app php artisan queue:restart </dev/null; then
    echo "Queue worker restart failed after the deferred Telegram outbound contract." >&2
    return 1
  fi
  if ! wait_for_health; then
    echo "Health verification failed after the deferred Telegram outbound contract." >&2
    return 1
  fi
  if ! compose exec -T app php artisan telegram:outbound-contract --strict </dev/null; then
    echo "Strict Telegram outbound contract verification failed after migration." >&2
    return 1
  fi
}

record_result() {
  printf '%s\n' "$1" > "$RELEASE_ARTIFACT_DIR/release-result.txt"
}

collect_release_diagnostics() {
  local health_summary_temp="$RELEASE_ARTIFACT_DIR/health-summary.json.tmp"

  if [ "$COMPOSE_CONFIG_STATUS" != validated ]; then
    printf '{"status":"unavailable","reason":"compose-validation-failed"}\n' > "$RELEASE_ARTIFACT_DIR/compose-ps.json"
    printf '{"status":"unavailable","reason":"compose-validation-failed"}\n' > "$RELEASE_ARTIFACT_DIR/health-summary.json"
    printf 'Compose diagnostics unavailable because safe Compose validation did not pass.\n' > "$RELEASE_ARTIFACT_DIR/compose.log"
    return 0
  fi

  compose ps --format json > "$RELEASE_ARTIFACT_DIR/compose-ps.json" 2>/dev/null || true
  if [ -s "$FAILED_JOBS_BASELINE_FILE" ]; then
    if compose exec -T app php artisan ops:release-health-summary --baseline-stdin < "$FAILED_JOBS_BASELINE_FILE" > "$health_summary_temp" 2>/dev/null; then
      mv "$health_summary_temp" "$RELEASE_ARTIFACT_DIR/health-summary.json"
    fi
  else
    if compose exec -T app php artisan ops:release-health-summary </dev/null > "$health_summary_temp" 2>/dev/null; then
      mv "$health_summary_temp" "$RELEASE_ARTIFACT_DIR/health-summary.json"
    fi
  fi
  rm -f "$health_summary_temp"
  compose logs --tail=120 app web scheduler 2>&1 \
    | "$REDACTOR_PATH" \
    > "$RELEASE_ARTIFACT_DIR/compose.log" || true
}

verify_service_images() {
  local expected_image_id container_id running_image service
  expected_image_id="$(docker image inspect "$1" --format '{{.Id}}')"
  for service in app scheduler; do
    container_id="$(compose ps -q "$service")"
    [ -n "$container_id" ] || return 1
    running_image="$(docker inspect "$container_id" --format '{{.Image}}')"
    [ "$running_image" = "$expected_image_id" ] || {
      echo "$service is running $running_image instead of expected image $expected_image_id." >&2
      return 1
    }
  done

}

wait_for_health() {
  local attempt
  for attempt in $(seq 1 "${RELEASE_HEALTH_ATTEMPTS:-36}"); do
    if compose_root exec -T app php artisan ops:health --db --queue-schema --queue-depth --supervisor </dev/null; then
      break
    fi
    [ "$attempt" -lt "${RELEASE_HEALTH_ATTEMPTS:-36}" ] || return 1
    sleep "${RELEASE_HEALTH_INTERVAL:-5}"
  done

  # BSC readiness is a database-backed activation gate. Keep this command
  # capability-detected so an immutable rollback to the immediately previous
  # release (before this command existed) remains safe.
  if compose exec -T app php artisan list --raw </dev/null 2>/dev/null | grep -q '^bsc:check-readiness[[:space:]]'; then
    if ! compose exec -T app php artisan bsc:check-readiness --json </dev/null; then
      echo "Release gate failed: enabled BSC is not ready." >&2
      return 1
    fi
  fi

  for attempt in $(seq 1 "${RELEASE_HEALTH_ATTEMPTS:-36}"); do
    if compose_root exec -T scheduler php artisan ops:health --scheduler-heartbeat=180 </dev/null; then
      return 0
    fi
    [ "$attempt" -lt "${RELEASE_HEALTH_ATTEMPTS:-36}" ] || return 1
    sleep "${RELEASE_HEALTH_INTERVAL:-5}"
  done
}

verify_release_gates() {
  wait_for_health || return 1

  echo "Clearing stale Laravel scheduler mutexes after candidate cutover."
  if ! compose exec -T app php artisan schedule:clear-cache </dev/null; then
    echo "Release gate failed: stale scheduler mutex cleanup failed." >&2
    return 1
  fi

  if [ "${RECOVER_FAILED_TELEGRAM_UPDATES:-false}" = "true" ]; then
    if ! compose exec -T app php artisan telegram:recover-failed-updates --prune-unrecoverable </dev/null; then
      echo "Release gate failed: Telegram update recovery failed." >&2
      return 1
    fi
  fi

  if ! compose exec -T app php artisan ops:check-failed-jobs --max=0 --baseline-stdin < "$FAILED_JOBS_BASELINE_FILE"; then
    echo "Release gate failed: candidate failed jobs exceeded the pre-release baseline." >&2
    return 1
  fi

  if ! compose exec -T app php artisan ops:check-queue-depth \
    --max=50 \
    --max-oldest-age=120 \
    --queue=payments \
    --queue=default \
    --queue=telegram-outbound \
    --queue=notifications \
    --queue=telegram-broadcast \
    --queue=telegram-stock \
    --queue=binance-verification </dev/null; then
    echo "Release gate failed: an operational queue exceeded its threshold." >&2
    return 1
  fi

  if ! compose exec -T app php artisan ops:check-telegram-webhook --max-pending=25 --observation-seconds=30 --sample-interval=5 </dev/null; then
    echo "Release gate failed: Telegram webhook observation failed." >&2
    return 1
  fi
}

load_previous_release() {
  PREVIOUS_COMMIT="$(cat "$RELEASE_ARTIFACT_DIR/previous-commit.txt")"
  PREVIOUS_IMAGE="$(cat "$RELEASE_ARTIFACT_DIR/previous-image.txt")"
  [[ "$PREVIOUS_COMMIT" =~ ^[0-9a-f]{40}$ ]] || {
    echo "Recorded rollback commit is invalid." >&2
    return 1
  }
  is_rollback_image_reference "$PREVIOUS_IMAGE" || {
    echo "Recorded rollback image is not content-addressed." >&2
    return 1
  }
}

restore_previous_release() {
  local failure_reason="${FAILURE_REASON:-deploy-failed}"
  echo "Release verification failed ($failure_reason). Restoring previous release $PREVIOUS_IMAGE from commit $PREVIOUS_COMMIT." >&2
  write_release_state "rollback-started"
  git checkout --detach "$PREVIOUS_COMMIT"
  git reset --hard "$PREVIOUS_COMMIT"
  COMPOSE_CONFIG_STATUS=unvalidated
  export RELEASE_SHA="$PREVIOUS_COMMIT"

  if is_immutable_image_reference "$PREVIOUS_IMAGE"; then
    if ! docker pull "$PREVIOUS_IMAGE"; then
      record_result "${failure_reason}-rollback-failed"
      write_release_state "rollback-failed"
      echo "DEPLOY FAILED: previous immutable image could not be pulled." >&2
      return 1
    fi
  elif ! docker image inspect "$PREVIOUS_IMAGE" >/dev/null; then
    record_result "${failure_reason}-rollback-failed"
    write_release_state "rollback-failed"
    echo "DEPLOY FAILED: previous content-addressed local image is unavailable." >&2
    return 1
  fi
  if ! APP_IMAGE="$PREVIOUS_IMAGE" compose up -d --no-build --remove-orphans; then
    record_result "${failure_reason}-rollback-failed"
    write_release_state "rollback-failed"
    echo "DEPLOY FAILED: previous release could not be started." >&2
    return 1
  fi

  if verify_service_images "$PREVIOUS_IMAGE" \
    && wait_for_health \
    && verify_app_url \
    && write_release_marker "$PREVIOUS_COMMIT" \
    && verify_release_marker "$PREVIOUS_COMMIT"; then
    APP_IMAGE="$PREVIOUS_IMAGE"
    record_result "${failure_reason}-rollback-succeeded"
    write_release_state "rollback-succeeded"
    echo "DEPLOY FAILED: rollback succeeded and previous release is healthy." >&2
    return 0
  fi

  record_result "${failure_reason}-rollback-failed"
  write_release_state "rollback-failed"
  echo "DEPLOY FAILED: rollback failed or the restored release is unhealthy. Manual recovery is required." >&2
  return 1
}

verify_candidate_release() {
  local current_commit current_app_container running_image
  current_commit="$(git rev-parse HEAD)"
  [ "$current_commit" = "$DEPLOY_SHA" ] || return 1
  current_app_container="$(compose ps -q app)"
  [ -n "$current_app_container" ] || return 1
  running_image="$(docker inspect "$current_app_container" --format '{{.Config.Image}}')"
  verify_service_images "$APP_IMAGE" && wait_for_health && verify_app_url
}

reconcile_remote_release() {
  local state="${1:-}"
  if [ -z "$state" ]; then
    echo "Remote release journal is missing; refusing to infer mutation outcome." >&2
    if load_previous_release; then
      FAILURE_REASON=remote-state-reconcile
      restore_previous_release || true
    fi
    return 1
  fi

  case "$state" in
    candidate-cutover-started|candidate-healthy|compatible-candidate-running)
      COMPOSE_CONFIG_STATUS=unvalidated
      export RELEASE_SHA="$DEPLOY_SHA"
      if verify_candidate_release; then
        if ! verify_release_marker "$DEPLOY_SHA"; then
          write_release_marker "$DEPLOY_SHA" && verify_release_marker "$DEPLOY_SHA" || {
            echo "Candidate is healthy but its public release marker could not be repaired." >&2
            load_previous_release && FAILURE_REASON=remote-state-reconcile && restore_previous_release || true
            return 1
          }
        fi
        [ -s "$RELEASE_ARTIFACT_DIR/release-result.txt" ] \
          || record_result "deploy-succeeded-compatible-contract-pending"
        write_release_state "compatible-candidate-running"
        echo "Remote release reconciliation proved the candidate is healthy."
        return 0
      fi
      echo "Remote release journal says candidate mutation started, but candidate health is not proven." >&2
      if load_previous_release; then
        FAILURE_REASON=remote-state-reconcile
        restore_previous_release || true
      fi
      return 1
      ;;
    deploy-started|previous-release-recorded|candidate-checkout|image-pulled|candidate-migrations-started|migration-succeeded|backup-started|backup-verified)
      echo "Remote release journal does not prove a healthy cutover: state=$state." >&2
      if load_previous_release; then
        FAILURE_REASON=remote-state-reconcile
        restore_previous_release || true
      fi
      return 1
      ;;
    *)
      echo "Remote release journal has an unknown state: $state." >&2
      return 1
      ;;
  esac
}


FAILED_JOBS_BASELINE_FILE="$RELEASE_ARTIFACT_DIR/failed-jobs-baseline.json"
trap 'status=$?; collect_release_diagnostics; exit "$status"' EXIT
exec 9>"$PRODUCTION_PATH/.production-mutation.lock"
if [ "$RELEASE_OPERATION" = "reconcile" ]; then
  flock -w "${RELEASE_RECONCILE_LOCK_TIMEOUT:-120}" 9 \
    || { echo "Timed out waiting for the active release to finish before reconciliation." >&2; exit 1; }
elif ! flock -n 9; then
  echo "Another production deployment or recovery is already running; refusing to overlap." >&2
  exit 1
fi

if [ "$RELEASE_OPERATION" = "contract" ]; then
  write_release_state "contract-started"
  if run_deferred_telegram_contract; then
    record_result "deploy-succeeded"
    write_release_state "contract-succeeded"
    echo "Deferred Telegram outbound contract succeeded: commit=$DEPLOY_SHA image=$APP_IMAGE"
    exit 0
  fi

  record_result "contract-failed-candidate-remains"
  write_release_state "contract-failed-candidate-remains"
  echo "DEPLOY FAILED: deferred Telegram outbound contract did not complete; candidate remains running for investigation." >&2
  exit 1
fi

if [ "$RELEASE_OPERATION" = "rollback" ]; then
  load_previous_release || exit 1
  FAILURE_REASON="${FAILURE_REASON:-external-smoke-failed}"
  if restore_previous_release; then
    exit 0
  fi
  exit 1
fi

if [ "$RELEASE_OPERATION" = "reconcile" ]; then
  state="$(release_state_value state)"
  if reconcile_remote_release "$state"; then
    exit 0
  fi
  exit 1
fi

write_release_state "deploy-started"
git fetch --prune origin "$DEPLOY_BRANCH"
git cat-file -e "${DEPLOY_SHA}^{commit}"
initialize_credential_identity_fingerprint_key

PREVIOUS_COMMIT="$(git rev-parse HEAD)"
previous_app_container="$(compose ps -q app)"
[ -n "$previous_app_container" ] || { echo "Cannot identify the currently running app container." >&2; exit 1; }
configured_previous_image="$(docker inspect "$previous_app_container" --format '{{.Config.Image}}')"
if is_immutable_image_reference "$configured_previous_image"; then
  PREVIOUS_IMAGE="$configured_previous_image"
elif [ "${BOOTSTRAP_LOCAL_ROLLBACK_IMAGE:-false}" = "true" ]; then
  PREVIOUS_IMAGE="$(docker inspect "$previous_app_container" --format '{{.Image}}')"
  is_content_addressed_local_image "$PREVIOUS_IMAGE" || {
    echo "Current app container does not expose a valid content-addressed local image ID." >&2
    exit 1
  }
  echo "Bootstrapping one release with content-addressed local rollback image: $PREVIOUS_IMAGE"
else
  echo "Current app image is not an immutable GHCR digest reference and cannot be used for rollback: $configured_previous_image" >&2
  exit 1
fi
printf '%s\n' "$PREVIOUS_COMMIT" > "$RELEASE_ARTIFACT_DIR/previous-commit.txt"
printf '%s\n' "$PREVIOUS_IMAGE" > "$RELEASE_ARTIFACT_DIR/previous-image.txt"
write_release_state "previous-release-recorded"
echo "Recorded rollback target commit=$PREVIOUS_COMMIT image=$PREVIOUS_IMAGE"

git checkout --detach "$DEPLOY_SHA"
git reset --hard "$DEPLOY_SHA"
COMPOSE_CONFIG_STATUS=unvalidated
export RELEASE_SHA="$DEPLOY_SHA"
write_release_state "candidate-checkout"
validate_compose_config

if ! is_immutable_image_reference "$APP_IMAGE"; then
  echo "Candidate image is not an immutable GHCR digest reference: $APP_IMAGE" >&2
  exit 1
fi
echo "Pulling CI-approved production image from the VPS Docker credential store: $APP_IMAGE"
docker pull "$APP_IMAGE"
write_release_state "image-pulled"

echo "Recording the pre-release failed-job ID watermark with the candidate image."
APP_IMAGE="$APP_IMAGE" compose run --rm --no-deps -e APP_AUTO_MIGRATE=false -e APP_OPTIMIZE=false app php artisan ops:check-failed-jobs --snapshot </dev/null > "$FAILED_JOBS_BASELINE_FILE"

write_release_state "candidate-migrations-started"
echo "Running candidate expand migrations before application cutover."
if ! run_expand_migrations; then
  git checkout --detach "$PREVIOUS_COMMIT"
  git reset --hard "$PREVIOUS_COMMIT"
  COMPOSE_CONFIG_STATUS=unvalidated
  export RELEASE_SHA="$PREVIOUS_COMMIT"
  write_release_state "migration-failed-no-cutover"
  record_result "migration-failed-no-cutover"
  echo "DEPLOY FAILED: migration failed before cutover. The previous release remains running." >&2
  exit 1
fi
if ! run_credential_identity_backfill; then
  run_credential_identity_conflict_diagnostic || true
  git checkout --detach "$PREVIOUS_COMMIT"
  git reset --hard "$PREVIOUS_COMMIT"
  COMPOSE_CONFIG_STATUS=unvalidated
  export RELEASE_SHA="$PREVIOUS_COMMIT"
  write_release_state "credential-identity-backfill-failed-no-cutover"
  record_result "credential-identity-backfill-failed-no-cutover"
  echo "DEPLOY FAILED: credential identity backfill failed before application cutover. The previous release remains running." >&2
  exit 1
fi
write_release_state "migration-succeeded"

echo "Migration succeeded. Cutting over application services."
write_release_state "candidate-cutover-started"
if ! APP_IMAGE="$APP_IMAGE" compose up -d --no-build --remove-orphans \
  || ! verify_service_images "$APP_IMAGE" \
  || ! verify_release_gates \
  || ! verify_app_url \
  || ! write_release_marker "$DEPLOY_SHA" \
  || ! verify_release_marker "$DEPLOY_SHA"; then
  if restore_previous_release; then
    exit 20
  fi
  exit 21
fi
write_release_state "candidate-healthy"

compose ps

duplicate_projects="$(
  docker ps --filter label=com.docker.compose.project --format '{{.Label "com.docker.compose.project"}}' \
    | sort -u \
    | awk -v expected="$COMPOSE_PROJECT_NAME" '$0 ~ /^telegram-bot/ && $0 != expected'
)"
if [ -n "$duplicate_projects" ]; then
  printf 'Unexpected Telegram Bot Compose project(s) running:\n%s\n' "$duplicate_projects" >&2
  if restore_previous_release; then
    exit 20
  fi
  exit 21
fi

record_result "deploy-succeeded-compatible-contract-pending"
write_release_state "compatible-candidate-running"
echo "Production compatible release succeeded; deferred Telegram outbound contract remains pending: commit=$DEPLOY_SHA image=$APP_IMAGE"
