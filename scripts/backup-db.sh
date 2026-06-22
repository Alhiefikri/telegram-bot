#!/usr/bin/env bash
set -euo pipefail

BACKUP_DIR=${BACKUP_DIR:-backups}
COMPOSE=${COMPOSE:-docker compose}
DB_SERVICE=${DB_SERVICE:-db}
POSTGRES_DB=${POSTGRES_DB:-telegram_bot}
POSTGRES_USER=${POSTGRES_USER:-telegram_bot}

mkdir -p "$BACKUP_DIR"
timestamp=$(date -u +%Y%m%dT%H%M%SZ)
outfile="$BACKUP_DIR/${POSTGRES_DB}_${timestamp}.sql.gz"

if [ -n "${GPG_RECIPIENT:-}" ]; then
  outfile="$outfile.gpg"
  $COMPOSE exec -T -e PGPASSWORD="${POSTGRES_PASSWORD:-}" "$DB_SERVICE" \
    pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner --no-privileges \
    | gzip -c \
    | gpg --batch --yes --encrypt --recipient "$GPG_RECIPIENT" --output "$outfile"
elif [ -n "${GPG_PASSPHRASE:-}" ]; then
  outfile="$outfile.gpg"
  $COMPOSE exec -T -e PGPASSWORD="${POSTGRES_PASSWORD:-}" "$DB_SERVICE" \
    pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner --no-privileges \
    | gzip -c \
    | gpg --batch --yes --symmetric --cipher-algo AES256 --passphrase "$GPG_PASSPHRASE" --output "$outfile"
else
  $COMPOSE exec -T -e PGPASSWORD="${POSTGRES_PASSWORD:-}" "$DB_SERVICE" \
    pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner --no-privileges \
    | gzip -c > "$outfile"
fi

chmod 600 "$outfile"
printf 'Backup written to %s\n' "$outfile"
