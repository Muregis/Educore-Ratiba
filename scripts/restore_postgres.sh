#!/usr/bin/env bash
set -euo pipefail
DUMP="${1:?usage: restore_postgres.sh <file.dump>}"
: "${DATABASE_URL:?set DATABASE_URL}"

if ! command -v pg_restore >/dev/null 2>&1; then
  echo "pg_restore not found"
  exit 1
fi

echo "Restoring $DUMP into target DB…"
pg_restore --no-owner --no-acl --clean --if-exists -d "$DATABASE_URL" "$DUMP"
echo "Done. Verify with: psql \"\$DATABASE_URL\" -c 'SELECT COUNT(*) FROM schools;'"
