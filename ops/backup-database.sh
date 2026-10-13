#!/usr/bin/env bash
# Manual encrypted dump, no automatic schedule and no DB mutation.
set -Eeuo pipefail
if [[ $# -ne 2 ]]; then
  echo "Usage: $0 GPG_RECIPIENT OUTPUT_DIRECTORY" >&2
  exit 2
fi
recipient="$1"
output_dir="$2"
mkdir -p -- "$output_dir"
umask 077
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
destination="${output_dir%/}/iti-erp-${stamp}.sql.gpg"
if [[ -e "$destination" ]]; then
  echo "Backup exists: $destination" >&2
  exit 1
fi
temporary="${destination}.partial"
trap 'rm -f -- "$temporary"' EXIT
# Binary-safe Docker stream, GPG encryption without staging plaintext on host.
docker compose -f compose.yaml exec -T database sh -ec \
  'exec mariadb-dump --single-transaction --quick --routines --triggers --events --user="$MARIADB_USER" --password="$MARIADB_PASSWORD" "$MARIADB_DATABASE"' \
  | gpg --batch --yes --encrypt --recipient "$recipient" --output "$temporary"
test -s "$temporary"
mv -- "$temporary" "$destination"
sha256sum "$destination" > "${destination}.sha256"
echo "Encrypted backup: $destination"
echo "Copy archive and checksum to independent off-machine storage; verify with a restore drill."
