#!/usr/bin/env bash
set -euo pipefail

BACKUP_DIR=${BACKUP_DIR:-backups}
COMPOSE=${COMPOSE:-docker compose}
COMPOSE_PROJECT_NAME=${COMPOSE_PROJECT_NAME:-telegram-bot-prod}
COMPOSE_ENV_FILE=${COMPOSE_ENV_FILE:-.env}
COMPOSE_CONFIG_SCRIPT=${COMPOSE_CONFIG_SCRIPT:-./scripts/safe-compose-config.sh}
DB_SERVICE=${DB_SERVICE:-db}
dotenv_value() {
  local key="$1"
  local line value
  [ -f "$COMPOSE_ENV_FILE" ] || return 0
  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
      "$key="*)
        value="${line#*=}"
        value="${value#\"}"
        value="${value%\"}"
        value="${value#\'}"
        value="${value%\'}"
        printf '%s' "$value"
        return 0
        ;;
    esac
  done < "$COMPOSE_ENV_FILE"
}

for dotenv_key in GPG_RECIPIENT GPG_PASSPHRASE POSTGRES_PASSWORD POSTGRES_DB POSTGRES_USER DB_SERVICE; do
  if [ -z "${!dotenv_key:-}" ]; then
    dotenv_value="$(dotenv_value "$dotenv_key")"
    if [ -n "$dotenv_value" ]; then
      printf -v "$dotenv_key" '%s' "$dotenv_value"
      export "$dotenv_key"
    fi
  fi
done

POSTGRES_DB=${POSTGRES_DB:-telegram_bot}
POSTGRES_USER=${POSTGRES_USER:-telegram_bot}

mkdir -p "$BACKUP_DIR"
timestamp=$(date -u +%Y%m%dT%H%M%SZ)
outfile="$BACKUP_DIR/${POSTGRES_DB}_${timestamp}.sql.gz.gpg"

if [ -z "${GPG_RECIPIENT:-}" ] && [ -z "${GPG_PASSPHRASE:-}" ]; then
  printf 'Backup encryption is required: set GPG_RECIPIENT or GPG_PASSPHRASE.\n' >&2
  exit 2
fi
if [ -n "${GPG_RECIPIENT:-}" ] && [ -n "${GPG_PASSPHRASE:-}" ]; then
  printf 'Set only one backup encryption mode: GPG_RECIPIENT or GPG_PASSPHRASE.\n' >&2
  exit 2
fi

if ! command -v gpg >/dev/null 2>&1; then
  printf 'Backup encryption is required but gpg is not available.\n' >&2
  exit 2
fi
if [ ! -x "$COMPOSE_CONFIG_SCRIPT" ]; then
  printf 'Safe Compose validator is required before taking a production backup.\n' >&2
  exit 2
fi
"$COMPOSE_CONFIG_SCRIPT" --project "$COMPOSE_PROJECT_NAME" --env-file "$COMPOSE_ENV_FILE"

if [ -n "${GPG_RECIPIENT:-}" ]; then
  $COMPOSE exec -T -e PGPASSWORD="${POSTGRES_PASSWORD:-}" "$DB_SERVICE" \
    pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner --no-privileges </dev/null \
    | gzip -c \
    | gpg --batch --yes --encrypt --recipient "$GPG_RECIPIENT" --output "$outfile"
else
  $COMPOSE exec -T -e PGPASSWORD="${POSTGRES_PASSWORD:-}" "$DB_SERVICE" \
    pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner --no-privileges </dev/null \
    | gzip -c \
    | gpg --batch --yes --pinentry-mode loopback --symmetric --cipher-algo AES256 --passphrase "$GPG_PASSPHRASE" --output "$outfile"
fi

chmod 600 "$outfile"
[ -s "$outfile" ] || {
  printf 'Encrypted backup file is empty.\n' >&2
  exit 1
}
gpg --batch --quiet --list-packets "$outfile" >/dev/null 2>&1 || {
  printf 'Encrypted backup packet validation failed.\n' >&2
  exit 1
}
if [ -n "${GPG_PASSPHRASE:-}" ]; then
  gpg --batch --quiet --yes --pinentry-mode loopback --passphrase "$GPG_PASSPHRASE" --decrypt "$outfile" \
    | gzip -t
else
  gpg --batch --quiet --decrypt "$outfile" | gzip -t
fi

printf 'Backup written to %s\n' "$outfile"
printf 'Backup verification: encrypted-and-readable\n'
