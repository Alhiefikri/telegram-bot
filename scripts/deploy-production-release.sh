#!/usr/bin/env bash
set -euo pipefail

: "${PRODUCTION_PATH:?PRODUCTION_PATH is required}"
: "${DEPLOY_BRANCH:?DEPLOY_BRANCH is required}"
: "${DEPLOY_SHA:?DEPLOY_SHA is required}"
: "${COMPOSE_PROJECT_NAME:?COMPOSE_PROJECT_NAME is required}"
: "${APP_IMAGE:?APP_IMAGE is required}"

cd "$PRODUCTION_PATH"
RELEASE_ARTIFACT_DIR="${RELEASE_ARTIFACT_DIR:-.release-gate-artifacts/manual}"
case "$RELEASE_ARTIFACT_DIR" in
  /*) ;;
  *) RELEASE_ARTIFACT_DIR="$PRODUCTION_PATH/$RELEASE_ARTIFACT_DIR" ;;
esac
mkdir -p "$RELEASE_ARTIFACT_DIR"

compose() {
  docker compose -p "$COMPOSE_PROJECT_NAME" --env-file .env "$@"
}

record_result() {
  printf '%s\n' "$1" > "$RELEASE_ARTIFACT_DIR/release-result.txt"
}

collect_release_diagnostics() {
  compose ps --format json > "$RELEASE_ARTIFACT_DIR/compose-ps.json" 2>/dev/null || true
  compose exec -T app php artisan ops:release-health-summary --since="$RELEASE_STARTED_AT" > "$RELEASE_ARTIFACT_DIR/health-summary.json" 2>/dev/null || true
  compose logs --tail=120 app web scheduler 2>&1 \
    | sed -E -e 's/(token|secret|password|app_key)=?[^[:space:]]*/\1=[REDACTED]/Ig' -e 's/bot[0-9]{6,}:[[:alnum:]_-]+/bot[REDACTED]/g' \
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

  compose exec -T app php artisan ops:check-failed-jobs --max=0 --since="$RELEASE_STARTED_AT" </dev/null
  compose exec -T app php artisan ops:check-queue-depth --max=50 --max-oldest-age=120 </dev/null
  compose exec -T app php artisan ops:check-telegram-webhook --max-pending=25 --observation-seconds=30 --sample-interval=5 </dev/null
}

restore_previous_release() {
  echo "Post-cutover verification failed. Restoring previous immutable release $PREVIOUS_IMAGE from commit $PREVIOUS_COMMIT." >&2
  git checkout --detach "$PREVIOUS_COMMIT"
  git reset --hard "$PREVIOUS_COMMIT"
  APP_IMAGE="$PREVIOUS_IMAGE" compose up -d --no-build --remove-orphans

  if verify_service_images "$PREVIOUS_IMAGE" && wait_for_health; then
    record_result "deploy-failed-rollback-succeeded"
    echo "DEPLOY FAILED: rollback succeeded and previous release is healthy." >&2
    return 0
  fi

  record_result "deploy-failed-rollback-failed"
  echo "DEPLOY FAILED: rollback failed or the restored release is unhealthy. Manual recovery is required." >&2
  return 1
}

RELEASE_STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
trap 'status=$?; collect_release_diagnostics; exit "$status"' EXIT
exec 9>"$PRODUCTION_PATH/.production-mutation.lock"
if ! flock -n 9; then
  echo "Another production deployment or recovery is already running; refusing to overlap." >&2
  exit 1
fi

git fetch --prune origin "$DEPLOY_BRANCH"
git cat-file -e "${DEPLOY_SHA}^{commit}"

PREVIOUS_COMMIT="$(git rev-parse HEAD)"
previous_app_container="$(compose ps -q app)"
[ -n "$previous_app_container" ] || { echo "Cannot identify the currently running app container." >&2; exit 1; }
PREVIOUS_IMAGE="$(docker inspect "$previous_app_container" --format '{{.Config.Image}}')"
case "$PREVIOUS_IMAGE" in
  *@sha256:????????????????????????????????????????????????????????????????) ;;
  *) echo "Current app image is not an immutable digest reference: $PREVIOUS_IMAGE" >&2; exit 1 ;;
esac
printf '%s\n' "$PREVIOUS_COMMIT" > "$RELEASE_ARTIFACT_DIR/previous-commit.txt"
printf '%s\n' "$PREVIOUS_IMAGE" > "$RELEASE_ARTIFACT_DIR/previous-image.txt"
echo "Recorded rollback target commit=$PREVIOUS_COMMIT image=$PREVIOUS_IMAGE"

git checkout --detach "$DEPLOY_SHA"
git reset --hard "$DEPLOY_SHA"
./scripts/safe-compose-config.sh --project "$COMPOSE_PROJECT_NAME" --env-file .env

echo "Pulling CI-approved production image: $APP_IMAGE"
docker pull "$APP_IMAGE"

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
