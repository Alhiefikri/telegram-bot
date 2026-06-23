#!/usr/bin/env bash
set -euo pipefail

BASE_URL=${BASE_URL:-${APP_URL:-}}

if [ -z "$BASE_URL" ]; then
  printf 'BASE_URL or APP_URL is required.\n' >&2
  exit 2
fi

BASE_URL=${BASE_URL%/}
curl --fail --silent --show-error --max-time "${SMOKE_TIMEOUT:-10}" "$BASE_URL/up" >/dev/null
printf 'Smoke check passed: %s/up\n' "$BASE_URL"

PROJECT_NAME=${SMOKE_COMPOSE_PROJECT:-${COMPOSE_PROJECT_NAME:-}}
if [ -n "$PROJECT_NAME" ]; then
  docker compose -p "$PROJECT_NAME" exec -T app php artisan ops:health --db --queue-schema --supervisor
  printf 'Ops health check passed for compose project: %s\n' "$PROJECT_NAME"
fi
