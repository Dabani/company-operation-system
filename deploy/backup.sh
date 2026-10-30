#!/usr/bin/env bash
# Nightly backup: database dump + uploaded files, encrypted, 30-day local retention.
# Copy the encrypted archives OFF the server too (second site/cloud bucket) - a backup on the same disk is not a backup.
# Record every restore drill in Compliance > Backups (that feeds the "days since last verified backup" indicator).
set -euo pipefail
API=/var/www/company-ops-api
OUT=/var/backups/ops
KEEP_DAYS=30
STAMP=$(date +%Y%m%d-%H%M%S)
mkdir -p "$OUT"; chmod 700 "$OUT"

# Read DB settings from .env without sourcing it (values may contain special characters)
get() { grep -E "^$1=" "$API/.env" | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e 's/ *#.*$//'; }
DB=$(get DB_DATABASE); USER=$(get DB_USERNAME); PASS=$(get DB_PASSWORD); HOST=$(get DB_HOST)

mysqldump --single-transaction --routines --triggers -h "$HOST" -u "$USER" -p"$PASS" "$DB" | gzip > "$OUT/db-$STAMP.sql.gz"
tar -C "$API/storage/app" -czf "$OUT/files-$STAMP.tar.gz" .

# Encrypt with a passphrase kept OUTSIDE the server (password manager / vault). BACKUP_PASSPHRASE_FILE must be readable by root only.
if [ -n "${BACKUP_PASSPHRASE_FILE:-}" ]; then
  for f in "$OUT/db-$STAMP.sql.gz" "$OUT/files-$STAMP.tar.gz"; do
    gpg --batch --yes --pinentry-mode loopback --passphrase-file "$BACKUP_PASSPHRASE_FILE" -c "$f" && rm "$f"
  done
else
  echo "WARNING: BACKUP_PASSPHRASE_FILE not set - archives are NOT encrypted" >&2
fi

find "$OUT" -type f -mtime +"$KEEP_DAYS" -delete
echo "Backup $STAMP done"
