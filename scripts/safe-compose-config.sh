#!/usr/bin/env bash
set -euo pipefail

DOCKER_BIN=${DOCKER_BIN:-docker}
PROJECT_NAME=${COMPOSE_PROJECT_NAME:-telegram-bot-prod}
ENV_FILE=${COMPOSE_ENV_FILE:-.env}
COMPOSE_FILES=()

usage() {
  cat <<'USAGE'
Usage: safe-compose-config.sh [--project NAME] [--env-file PATH] [--compose-file PATH]

Validates the Docker Compose configuration without printing the resolved config.
Use this instead of `docker compose config` in production-like environments because
resolved Compose output includes environment values such as APP_KEY, Telegram
secrets, Pakasir API keys, and database passwords.
USAGE
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --project|-p)
      if [ "$#" -lt 2 ]; then
        printf 'Missing value for %s.\n' "$1" >&2
        exit 2
      fi
      PROJECT_NAME=$2
      shift 2
      ;;
    --env-file)
      if [ "$#" -lt 2 ]; then
        printf 'Missing value for %s.\n' "$1" >&2
        exit 2
      fi
      ENV_FILE=$2
      shift 2
      ;;
    --compose-file|-f)
      if [ "$#" -lt 2 ]; then
        printf 'Missing value for %s.\n' "$1" >&2
        exit 2
      fi
      COMPOSE_FILES+=(--file "$2")
      shift 2
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    *)
      printf 'Unknown option: %s\n' "$1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

"$DOCKER_BIN" compose -p "$PROJECT_NAME" --env-file "$ENV_FILE" "${COMPOSE_FILES[@]}" config --quiet
printf 'Compose configuration is valid; resolved config intentionally not printed.\n'
