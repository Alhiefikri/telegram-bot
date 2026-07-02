#!/usr/bin/env bash
set -euo pipefail

DEFAULT_PROJECT_NAME=telegram-bot-dogfood
PROJECT_NAME=$DEFAULT_PROJECT_NAME
DOCKER_BIN=${DOCKER_BIN:-docker}
RESET=false
CONFIRMED=${DOGFOOD_VOLUME_RESET_CONFIRM:-}
ALLOW_EXISTING=false

usage() {
  cat <<'USAGE'
Usage: scripts/dogfood-volume-preflight.sh [options]

Detect and optionally reset Docker Compose volumes for a dogfood project.

Options:
  --project NAME     Dogfood Compose project name. Defaults to telegram-bot-dogfood.
  --reset            Remove the listed dogfood project volumes.
  --yes              Confirm --reset without an interactive prompt.
  --allow-existing   Exit 0 when existing dogfood volumes are detected.
  -h, --help         Show this help.

Safety:
  Destructive reset is allowed only for project names containing "dogfood".
  The script selects volumes by the Compose project label:
  com.docker.compose.project=<project>
USAGE
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --project)
      if [ -z "${2:-}" ]; then
        printf 'Missing value for --project.\n' >&2
        usage >&2
        exit 2
      fi
      PROJECT_NAME=$2
      shift 2
      ;;
    --reset)
      RESET=true
      shift
      ;;
    --yes)
      CONFIRMED=yes
      shift
      ;;
    --allow-existing)
      ALLOW_EXISTING=true
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    --*)
      printf 'Unknown option: %s\n' "$1" >&2
      usage >&2
      exit 2
      ;;
    *)
      printf 'Unexpected argument: %s\n' "$1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

if [ -z "$PROJECT_NAME" ]; then
  printf 'Project name must not be empty.\n' >&2
  exit 2
fi

if [ "$RESET" = true ] && [ "$ALLOW_EXISTING" = true ]; then
  printf 'Choose either --reset or --allow-existing, not both.\n' >&2
  exit 2
fi

case "$PROJECT_NAME" in
  *dogfood*) ;;
  *)
    printf 'Refusing to operate on non-dogfood Compose project: %s\n' "$PROJECT_NAME" >&2
    printf 'Use a project name containing "dogfood", for example: %s\n' "$DEFAULT_PROJECT_NAME" >&2
    exit 2
    ;;
esac

printf 'Dogfood Docker volume preflight\n'
printf 'Project: %s\n' "$PROJECT_NAME"
printf 'Selector: label=com.docker.compose.project=%s\n' "$PROJECT_NAME"

mapfile -t VOLUMES < <("$DOCKER_BIN" volume ls --quiet --filter "label=com.docker.compose.project=$PROJECT_NAME")

if [ "${#VOLUMES[@]}" -eq 0 ]; then
  printf 'No existing dogfood project volumes found.\n'
  exit 0
fi

printf 'Existing dogfood project volumes found:\n'
for volume in "${VOLUMES[@]}"; do
  printf '  - %s\n' "$volume"
done

printf '\nState: warm/reused dogfood Docker volume state.\n'
printf 'Risk: PostgreSQL role, database, and password identity may come from an older run, not the current .env/Compose defaults.\n'
printf 'No volumes will be removed unless --reset is confirmed explicitly.\n'

if [ "$RESET" != true ]; then
  if [ "$ALLOW_EXISTING" = true ]; then
    printf 'Existing dogfood volumes are intentionally allowed for this run (--allow-existing).\n'
    exit 0
  fi

  printf 'No volumes removed. Re-run with --allow-existing to use warm state, or --reset --yes after preserving needed artifacts/logs.\n'
  exit 1
fi

printf '\nReset requested. The following command will remove only the listed volumes:\n'
printf '  %s volume rm' "$DOCKER_BIN"
for volume in "${VOLUMES[@]}"; do
  printf ' %q' "$volume"
done
printf '\n'

if [ "$CONFIRMED" != yes ]; then
  printf 'Refusing destructive reset. Pass --yes or set DOGFOOD_VOLUME_RESET_CONFIRM=yes.\n' >&2
  exit 2
fi

"$DOCKER_BIN" volume rm "${VOLUMES[@]}"
printf 'Removed %s dogfood project volume(s).\n' "${#VOLUMES[@]}"
