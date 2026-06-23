#!/usr/bin/env bash
set -euo pipefail

usage() {
  printf 'Usage: %s [--dry-run] [--yes] BACKUP_FILE\n' "$0"
}

DRY_RUN=false
CONFIRMED=${RESTORE_CONFIRM:-}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --dry-run) DRY_RUN=true; shift ;;
    --yes) CONFIRMED=yes; shift ;;
    -h|--help) usage; exit 0 ;;
    --*) printf 'Unknown option: %s\n' "$1" >&2; usage >&2; exit 2 ;;
    *) BACKUP_FILE=${BACKUP_FILE:-$1}; shift ;;
  esac
done

if [ -z "${BACKUP_FILE:-}" ]; then
  usage >&2
  exit 2
fi

if [ ! -f "$BACKUP_FILE" ]; then
  printf 'Backup file not found: %s\n' "$BACKUP_FILE" >&2
  exit 2
fi

case "$BACKUP_FILE" in
  *.sql|*.sql.gz|*.sql.gz.gpg) ;;
  *) printf 'Unsupported backup format: %s\n' "$BACKUP_FILE" >&2; exit 2 ;;
esac

COMPOSE=${COMPOSE:-docker compose}
DB_SERVICE=${DB_SERVICE:-db}
POSTGRES_DB=${POSTGRES_DB:-telegram_bot}
POSTGRES_USER=${POSTGRES_USER:-telegram_bot}

printf 'Restore target: service=%s database=%s user=%s file=%s\n' "$DB_SERVICE" "$POSTGRES_DB" "$POSTGRES_USER" "$BACKUP_FILE"

if [ "$DRY_RUN" = true ]; then
  printf 'Dry run only; no restore executed.\n'
  exit 0
fi

if [ "$CONFIRMED" != yes ]; then
  printf 'Refusing destructive restore. Set RESTORE_CONFIRM=yes or pass --yes.\n' >&2
  exit 1
fi

case "$BACKUP_FILE" in
  *.sql)
    $COMPOSE exec -T -e PGPASSWORD="${POSTGRES_PASSWORD:-}" "$DB_SERVICE" psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" < "$BACKUP_FILE"
    ;;
  *.sql.gz)
    gzip -dc "$BACKUP_FILE" | $COMPOSE exec -T -e PGPASSWORD="${POSTGRES_PASSWORD:-}" "$DB_SERVICE" psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"
    ;;
  *.sql.gz.gpg)
    if [ -z "${GPG_PASSPHRASE:-}" ]; then
      printf 'GPG_PASSPHRASE is required for encrypted backups.\n' >&2
      exit 1
    fi
    gpg --batch --decrypt --passphrase "$GPG_PASSPHRASE" "$BACKUP_FILE" | gzip -dc | $COMPOSE exec -T -e PGPASSWORD="${POSTGRES_PASSWORD:-}" "$DB_SERVICE" psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"
    ;;
esac

printf 'Restore completed.\n'
