#!/usr/bin/env bash
# Automated, encrypted DB + file backup — PLAN.md Phase 6 / USER_STORIES.md
# US-09 ("automated backups of the MySQL database and WP files to occur
# daily, so we can recover quickly in the event of a ransomware attack or
# failure"). Per US-08, WooCommerce order/customer rows in this dump never
# include payment card/bank data (that's tokenized off-site by PayPal), so
# this backup gets standard DB-backup treatment, not PCI-scoped handling.
#
# Backs up:
#   1. A consistent mysqldump of the WordPress/WooCommerce database.
#   2. The custom + vendored wp-content/{themes,plugins,mu-plugins} trees
#      (vendored plugins like woocommerce/two-factor/akismet are gitignored
#      — this is their only backup, git won't recover them).
#   3. wp-content/uploads (lives only in the wp_data named volume).
#
# Encrypts the combined archive with a symmetric GPG passphrase. A
# passphrase is used here (rather than public-key encryption) because it
# needs zero extra key-management infrastructure for a local course demo.
# A real production deployment should switch to
# `gpg --encrypt -r <recipient-pubkey>` instead, so a compromised app host
# can still *produce* new encrypted backups without being able to *decrypt*
# any backup (including old ones) — the correct posture against the
# ransomware scenario US-09 names, since a symmetric passphrase stored
# anywhere reachable by that host can decrypt everything it ever produced.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

if [ -f .env ]; then
    set -a
    # shellcheck disable=SC1091
    source .env
    set +a
fi

: "${MYSQL_DATABASE:?MYSQL_DATABASE must be set (see .env)}"
: "${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD must be set (see .env)}"
: "${BACKUP_GPG_PASSPHRASE:?BACKUP_GPG_PASSPHRASE must be set (see .env)}"
BACKUP_DIR="${BACKUP_DIR:-./backups}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"

mkdir -p "$BACKUP_DIR"

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
workdir="$(mktemp -d)"
trap 'rm -rf "$workdir"' EXIT

echo "[backup] dumping database..."
docker compose exec -T mysql mysqldump \
    --single-transaction --quick --routines --triggers \
    -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" \
    > "$workdir/db.sql"

echo "[backup] archiving wp-content/{themes,plugins,mu-plugins}..."
tar czf "$workdir/wp-content-code.tar.gz" \
    -C wp-content themes plugins mu-plugins

echo "[backup] archiving wp-content/uploads..."
docker compose exec -T wordpress tar czf - -C /var/www/html/wp-content uploads \
    > "$workdir/uploads.tar.gz"

echo "[backup] combining and encrypting..."
archive_name="cad-edu-backup-${timestamp}.tar.gz.gpg"
tar czf - -C "$workdir" db.sql wp-content-code.tar.gz uploads.tar.gz \
    | gpg --batch --yes --symmetric --cipher-algo AES256 \
          --passphrase-fd 3 3<<<"$BACKUP_GPG_PASSPHRASE" \
          --output "$BACKUP_DIR/$archive_name"

chmod 600 "$BACKUP_DIR/$archive_name"
echo "[backup] wrote $BACKUP_DIR/$archive_name"

echo "[backup] pruning archives older than ${BACKUP_RETENTION_DAYS} days..."
find "$BACKUP_DIR" -maxdepth 1 -name 'cad-edu-backup-*.tar.gz.gpg' \
    -mtime "+${BACKUP_RETENTION_DAYS}" -print -delete

echo "[backup] done"
