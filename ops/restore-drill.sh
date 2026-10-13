#!/usr/bin/env bash
# Manual isolated restore rehearsal. Does not modify the live database.
set -Eeuo pipefail
if [[ $# -ne 1 ]]; then
  echo "Usage: $0 /path/to/iti-erp-YYYYMMDDTHHMMSSZ.sql.gpg" >&2
  exit 2
fi
archive="$1"
test -r "$archive" || { echo "Archive not readable" >&2; exit 2; }
: "${RESTORE_DRILL_ROOT_PASSWORD:?Set a unique nonproduction drill password}"
project="iti-erp-restore-$$"
compose=(docker compose -p "$project" -f deployment/restore.compose.yaml)
cleanup() { "${compose[@]}" down -v --remove-orphans >/dev/null || true; }
trap cleanup EXIT
"${compose[@]}" up -d --wait restore-db
gpg --batch --decrypt -- "$archive" \
  | "${compose[@]}" exec -T restore-db sh -ec \
    'exec mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'
"${compose[@]}" exec -T restore-db sh -ec \
  'mariadb --batch --skip-column-names --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE(); SELECT COUNT(*) FROM institute;"'
echo "Isolated restore finished; review table and institute counts, then run read-only schema preflight on a copy."
