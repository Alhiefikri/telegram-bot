#!/usr/bin/env bash
set -euo pipefail

fail() {
  printf 'Smoke check failed: %s\n' "$*" >&2
  exit 1
}

BASE_URL=${BASE_URL:-${APP_URL:-}}
EXPECTED_APP_URL=${EXPECTED_APP_URL:-${APP_URL:-}}
EXPECTED_RELEASE_SHA=${EXPECTED_RELEASE_SHA:-}
SMOKE_TIMEOUT=${SMOKE_TIMEOUT:-10}

[ -n "$BASE_URL" ] || {
  printf 'BASE_URL or APP_URL is required.\n' >&2
  exit 2
}
[ -n "$EXPECTED_APP_URL" ] || {
  printf 'EXPECTED_APP_URL or APP_URL is required.\n' >&2
  exit 2
}

BASE_URL=${BASE_URL%/}
EXPECTED_APP_URL=${EXPECTED_APP_URL%/}
case "$BASE_URL" in
  https://*) ;;
  *) fail "BASE_URL must use HTTPS: $BASE_URL" ;;
esac
case "$EXPECTED_APP_URL" in
  https://*) ;;
  *) fail "EXPECTED_APP_URL must use HTTPS: $EXPECTED_APP_URL" ;;
esac
[ "$BASE_URL" = "$EXPECTED_APP_URL" ] || fail "smoke URL $BASE_URL does not match canonical APP_URL $EXPECTED_APP_URL"

[[ "$EXPECTED_RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]] || fail "EXPECTED_RELEASE_SHA must be a full lowercase commit SHA"

base_authority="${BASE_URL#https://}"
base_authority="${base_authority%%/*}"
SMOKE_HEALTH_MARKER=${SMOKE_HEALTH_MARKER:-Application up}
SMOKE_ADMIN_MARKER=${SMOKE_ADMIN_MARKER:-LHIE STORE}
SMOKE_RELEASE_PATH=${SMOKE_RELEASE_PATH:-/release-sha.txt}

temporary_dir="$(mktemp -d)"
trap 'rm -rf "$temporary_dir"' EXIT

health_body="$temporary_dir/health.body"
health_status="$(curl --silent --show-error --max-time "$SMOKE_TIMEOUT" \
  --header 'Accept: text/html' \
  --output "$health_body" --write-out '%{http_code}' "$BASE_URL/up")"
[ "$health_status" = 200 ] || fail "/up returned HTTP $health_status"
case "$(cat "$health_body")" in
  *"$SMOKE_HEALTH_MARKER"*) ;;
  *) fail "/up response did not contain the expected application marker" ;;
esac
printf 'Smoke health check passed: %s/up\n' "$BASE_URL"

release_body="$temporary_dir/release.body"
release_status="$(curl --silent --show-error --max-time "$SMOKE_TIMEOUT" \
  --header 'Cache-Control: no-cache' \
  --output "$release_body" --write-out '%{http_code}' "$BASE_URL$SMOKE_RELEASE_PATH")"
[ "$release_status" = 200 ] || fail "$SMOKE_RELEASE_PATH returned HTTP $release_status"
release_sha="$(tr -d '\r\n' < "$release_body")"
[ "$release_sha" = "$EXPECTED_RELEASE_SHA" ] \
  || fail "$SMOKE_RELEASE_PATH returned an unexpected release identity"
printf 'Release identity check passed: %s%s\n' "$BASE_URL" "$SMOKE_RELEASE_PATH"

MINI_APP_DASHBOARD_URL=${TELEGRAM_MINI_APP_URL:-}
if [ -z "$MINI_APP_DASHBOARD_URL" ]; then
  MINI_APP_DASHBOARD_URL="$BASE_URL/admin"
fi

admin_body="$temporary_dir/admin.body"
admin_meta="$(curl --silent --show-error --location --max-redirs 5 --max-time "$SMOKE_TIMEOUT" \
  --output "$admin_body" --write-out '%{http_code}|%{url_effective}' "$MINI_APP_DASHBOARD_URL")"
admin_status="${admin_meta%%|*}"
admin_effective_url="${admin_meta#*|}"
case "$admin_status" in
  2[0-9][0-9]) ;;
  *) fail "admin smoke returned HTTP $admin_status" ;;
esac
case "$admin_effective_url" in
  https://*) ;;
  *) fail "admin smoke ended on a non-HTTPS URL" ;;
esac
admin_authority="${admin_effective_url#https://}"
admin_authority="${admin_authority%%/*}"
[ "$admin_authority" = "$base_authority" ] \
  || fail "admin smoke ended on unexpected hostname $admin_authority"
case "$(cat "$admin_body")" in
  *"$SMOKE_ADMIN_MARKER"*) ;;
  *) fail "admin response did not contain the expected application marker" ;;
esac
printf 'Admin smoke check passed: %s\n' "$admin_effective_url"

PROJECT_NAME=${SMOKE_COMPOSE_PROJECT:-${COMPOSE_PROJECT_NAME:-}}
if [ "${SMOKE_PUBLIC_ONLY:-false}" != "true" ] && [ -n "$PROJECT_NAME" ]; then
  COMPOSE_ENV_FILE=${COMPOSE_ENV_FILE:-.env}
  COMPOSE_CONFIG_SCRIPT=${COMPOSE_CONFIG_SCRIPT:-./scripts/safe-compose-config.sh}
  [ -x "$COMPOSE_CONFIG_SCRIPT" ] || fail "safe Compose validator is required for local smoke checks"
  "$COMPOSE_CONFIG_SCRIPT" --project "$PROJECT_NAME" --env-file "$COMPOSE_ENV_FILE"
  docker compose -p "$PROJECT_NAME" --env-file "$COMPOSE_ENV_FILE" exec -T app php artisan ops:health --db --queue-schema --queue-depth --supervisor </dev/null
  SCHEDULER_DEADLINE=$((SECONDS + ${SCHEDULER_HEALTH_TIMEOUT:-120}))
  until docker compose -p "$PROJECT_NAME" --env-file "$COMPOSE_ENV_FILE" exec -T scheduler php artisan ops:health --scheduler-heartbeat="${SCHEDULER_HEARTBEAT_MAX_AGE:-180}" </dev/null; do
    if [ "$SECONDS" -ge "$SCHEDULER_DEADLINE" ]; then
      printf 'Scheduler health check failed for compose project: %s\n' "$PROJECT_NAME" >&2
      exit 1
    fi

    sleep 5
  done
  printf 'Ops and scheduler health checks passed for compose project: %s\n' "$PROJECT_NAME"
fi
