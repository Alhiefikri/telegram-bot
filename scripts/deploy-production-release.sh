#!/usr/bin/env bash
set -euo pipefail

: "${PRODUCTION_PATH:?PRODUCTION_PATH is required}"
: "${COMPOSE_PROJECT_NAME:?COMPOSE_PROJECT_NAME is required}"
RELEASE_OPERATION="${RELEASE_OPERATION:-deploy}"
if [ "$RELEASE_OPERATION" = "deploy" ]; then
  : "${DEPLOY_BRANCH:?DEPLOY_BRANCH is required}"
  : "${DEPLOY_SHA:?DEPLOY_SHA is required}"
  : "${APP_IMAGE:?APP_IMAGE is required}"
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

compose() {
  docker compose -p "$COMPOSE_PROJECT_NAME" --env-file .env "$@"
}

record_result() {
  printf '%s\n' "$1" > "$RELEASE_ARTIFACT_DIR/release-result.txt"
}

collect_release_diagnostics() {
  local health_summary_temp="$RELEASE_ARTIFACT_DIR/health-summary.json.tmp"
  compose ps --format json > "$RELEASE_ARTIFACT_DIR/compose-ps.json" 2>/dev/null || true
  if [ -s "$FAILED_JOBS_BASELINE_FILE" ]; then
    if compose exec -T app php artisan ops:release-health-summary --baseline-stdin < "$FAILED_JOBS_BASELINE_FILE" > "$health_summary_temp" 2>/dev/null; then
      mv "$health_summary_temp" "$RELEASE_ARTIFACT_DIR/health-summary.json"
    fi
  else
    if compose exec -T app php artisan ops:release-health-summary > "$health_summary_temp" 2>/dev/null; then
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
    if compose exec -T app php artisan ops:health --db --queue-schema --supervisor </dev/null; then
      break
    fi
    [ "$attempt" -lt "${RELEASE_HEALTH_ATTEMPTS:-36}" ] || return 1
    sleep "${RELEASE_HEALTH_INTERVAL:-5}"
  done

  for attempt in $(seq 1 "${RELEASE_HEALTH_ATTEMPTS:-36}"); do
    if compose exec -T scheduler php artisan ops:health --scheduler-heartbeat=180 </dev/null; then
      return 0
    fi
    [ "$attempt" -lt "${RELEASE_HEALTH_ATTEMPTS:-36}" ] || return 1
    sleep "${RELEASE_HEALTH_INTERVAL:-5}"
  done
}

verify_release_gates() {
  wait_for_health || return 1

  if [ "${RECOVER_FAILED_TELEGRAM_UPDATES:-false}" = "true" ]; then
    compose exec -T app php artisan telegram:recover-failed-updates --prune-unrecoverable
  fi

  compose exec -T app php artisan ops:check-failed-jobs --max=0 --baseline-stdin < "$FAILED_JOBS_BASELINE_FILE"
  compose exec -T app php artisan ops:check-queue-depth --max=50 --max-oldest-age=120 </dev/null
  compose exec -T app php artisan ops:check-telegram-webhook --max-pending=25 --observation-seconds=30 --sample-interval=5 </dev/null
}

restore_previous_release() {
  local failure_reason="${FAILURE_REASON:-deploy-failed}"
  echo "Release verification failed ($failure_reason). Restoring previous release $PREVIOUS_IMAGE from commit $PREVIOUS_COMMIT." >&2
  git checkout --detach "$PREVIOUS_COMMIT"
  git reset --hard "$PREVIOUS_COMMIT"
  APP_IMAGE="$PREVIOUS_IMAGE" compose up -d --no-build --remove-orphans

  if verify_service_images "$PREVIOUS_IMAGE" && wait_for_health; then
    record_result "${failure_reason}-rollback-succeeded"
    echo "DEPLOY FAILED: rollback succeeded and previous release is healthy." >&2
    return 0
  fi

  record_result "${failure_reason}-rollback-failed"
  echo "DEPLOY FAILED: rollback failed or the restored release is unhealthy. Manual recovery is required." >&2
  return 1
}

FAILED_JOBS_BASELINE_FILE="$RELEASE_ARTIFACT_DIR/failed-jobs-baseline.json"
trap 'status=$?; collect_release_diagnostics; exit "$status"' EXIT
exec 9>"$PRODUCTION_PATH/.production-mutation.lock"
if ! flock -n 9; then
  echo "Another production deployment or recovery is already running; refusing to overlap." >&2
  exit 1
fi

if [ "$RELEASE_OPERATION" = "rollback" ]; then
  PREVIOUS_COMMIT="$(cat "$RELEASE_ARTIFACT_DIR/previous-commit.txt")"
  PREVIOUS_IMAGE="$(cat "$RELEASE_ARTIFACT_DIR/previous-image.txt")"
  case "$PREVIOUS_COMMIT" in
    [0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f]) ;;
    *) echo "Recorded rollback commit is invalid." >&2; exit 1 ;;
  esac
  case "$PREVIOUS_IMAGE" in
    ""|*[[:space:]]*) echo "Recorded rollback image is invalid." >&2; exit 1 ;;
    *) ;;
  esac
  FAILURE_REASON="${FAILURE_REASON:-external-smoke-failed}"
  if restore_previous_release; then
    exit 0
  fi
  exit 1
fi

git fetch --prune origin "$DEPLOY_BRANCH"
git cat-file -e "${DEPLOY_SHA}^{commit}"

PREVIOUS_COMMIT="$(git rev-parse HEAD)"
previous_app_container="$(compose ps -q app)"
[ -n "$previous_app_container" ] || { echo "Cannot identify the currently running app container." >&2; exit 1; }
PREVIOUS_IMAGE="$(docker inspect "$previous_app_container" --format '{{.Config.Image}}')"
case "$PREVIOUS_IMAGE" in
  *@sha256:????????????????????????????????????????????????????????????????)
    echo "Current app image is an immutable digest reference: $PREVIOUS_IMAGE"
    ;;
  ""|*[[:space:]]*)
    echo "Current app image reference is invalid and cannot be used for rollback: $PREVIOUS_IMAGE" >&2
    exit 1
    ;;
  *)
    echo "Current app image is a legacy mutable/local reference: $PREVIOUS_IMAGE" >&2
    echo "Proceeding with first immutable-image cutover; rollback will reuse this existing local image with --no-build." >&2
    ;;
esac
printf '%s\n' "$PREVIOUS_COMMIT" > "$RELEASE_ARTIFACT_DIR/previous-commit.txt"
printf '%s\n' "$PREVIOUS_IMAGE" > "$RELEASE_ARTIFACT_DIR/previous-image.txt"
echo "Recorded rollback target commit=$PREVIOUS_COMMIT image=$PREVIOUS_IMAGE"

git checkout --detach "$DEPLOY_SHA"
git reset --hard "$DEPLOY_SHA"
./scripts/safe-compose-config.sh --project "$COMPOSE_PROJECT_NAME" --env-file .env

echo "Pulling CI-approved production image: $APP_IMAGE"
case "$APP_IMAGE" in
  ghcr.io/*)
    : "${GHCR_USERNAME:?GHCR_USERNAME is required to pull GHCR production images}"
    : "${GHCR_TOKEN:?GHCR_TOKEN is required to pull GHCR production images}"
    printf '%s' "$GHCR_TOKEN" | docker login ghcr.io --username "$GHCR_USERNAME" --password-stdin >/dev/null
    ;;
esac
docker pull "$APP_IMAGE"

echo "Recording the pre-release failed-job ID watermark with the candidate image."
APP_IMAGE="$APP_IMAGE" compose run --rm --no-deps -e APP_AUTO_MIGRATE=false -e APP_OPTIMIZE=false app php artisan ops:check-failed-jobs --snapshot > "$FAILED_JOBS_BASELINE_FILE"

echo "Running candidate database migration before application cutover."
if ! APP_IMAGE="$APP_IMAGE" compose run --rm --no-deps -e APP_AUTO_MIGRATE=false -e APP_OPTIMIZE=false app php artisan migrate --force; then
  git checkout --detach "$PREVIOUS_COMMIT"
  git reset --hard "$PREVIOUS_COMMIT"
  record_result "migration-failed-no-cutover"
  echo "DEPLOY FAILED: migration failed before cutover. The previous release remains running." >&2
  exit 1
fi

echo "Migration succeeded. Cutting over application services."
if ! APP_IMAGE="$APP_IMAGE" compose up -d --no-build --remove-orphans \
  || ! verify_service_images "$APP_IMAGE" \
  || ! verify_release_gates; then
  if restore_previous_release; then
    exit 20
  fi
  exit 21
fi

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

record_result "deploy-succeeded"
echo "Production release succeeded: commit=$DEPLOY_SHA image=$APP_IMAGE"
