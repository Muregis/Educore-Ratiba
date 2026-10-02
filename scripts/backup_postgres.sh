#!/usr/bin/env bash
# Formal logical backup for EduCore Ratiba (Postgres / Supabase).
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-./backups}"
RETAIN_DAYS="${RETAIN_DAYS:-14}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$BACKUP_DIR"

if [[ -n "${DATABASE_URL:-}" ]]; then
  DUMP_URL="$DATABASE_URL"
else
  : "${DB_HOST:?set DATABASE_URL or DB_HOST}"
  : "${DB_USER:?set DB_USER}"
  : "${DB_NAME:?set DB_NAME}"
  DB_PORT="${DB_PORT:-5432}"
  DUMP_URL="postgresql://${DB_USER}:${DB_PASS}@${DB_HOST}:${DB_PORT}/${DB_NAME}"
fi

OUT="${BACKUP_DIR}/educore-ratiba-${STAMP}.dump"
LOG="${BACKUP_DIR}/backup.log"

echo "[$(date -u +%FT%TZ)] starting backup → $OUT" | tee -a "$LOG"

if ! command -v pg_dump >/dev/null 2>&1; then
  echo "pg_dump not found. Install postgresql-client." | tee -a "$LOG"
  exit 1
fi

pg_dump "$DUMP_URL" --no-owner --no-acl -Fc -f "$OUT"
SIZE=$(wc -c < "$OUT" | tr -d ' ')
echo "[$(date -u +%FT%TZ)] OK size=${SIZE} bytes" | tee -a "$LOG"

find "$BACKUP_DIR" -name 'educore-ratiba-*.dump' -mtime "+${RETAIN_DAYS}" -delete 2>/dev/null || true

if [[ -n "${BACKUP_NOTIFY_URL:-}" ]]; then
  curl -sS -X POST -H 'Content-Type: application/json' \
    -d "{\"text\":\"EduCore Ratiba backup OK ${STAMP} (${SIZE} bytes)\"}" \
    "$BACKUP_NOTIFY_URL" >/dev/null || true
fi

echo "$OUT"
