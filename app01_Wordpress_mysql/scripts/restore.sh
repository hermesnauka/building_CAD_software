#!/usr/bin/env bash
# Restores a backup produced by scripts/backup.sh — PLAN.md Phase 6 /
# USER_STORIES.md US-09. Decrypts, then restores the DB dump into the
# running mysql container and the wp-content trees onto the host bind
# mounts / into the wordpress container's uploads volume.
#
# Usage:
#   scripts/restore.sh <path-to-archive.tar.gz.gpg> [--dry-run]
#
# --dry-run only decrypts and lists the archive contents — nothing is
# written to the database, host filesystem, or containers. Always run with
# --dry-run first against an unfamiliar archive before a real restore.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

if [ $# -lt 1 ]; then
    echo "usage: $0 <path-to-archive.tar.gz.gpg> [--dry-run]" >&2
    exit 1
fi

archive="$1"
dry_run=false
if [ "${2:-}" = "--dry-run" ]; then
    dry_run=true
fi

if [ ! -f "$archive" ]; then
    echo "error: archive not found: $archive" >&2
    exit 1
fi

if [ -f .env ]; then
    set -a
    # shellcheck disable=SC1091
    source .env
    set +a
fi

: "${BACKUP_GPG_PASSPHRASE:?BACKUP_GPG_PASSPHRASE must be set (see .env)}"

workdir="$(mktemp -d)"
trap 'rm -rf "$workdir"' EXIT

echo "[restore] decrypting..."
gpg --batch --yes --decrypt --passphrase-fd 3 3<<<"$BACKUP_GPG_PASSPHRASE" \
    "$archive" > "$workdir/combined.tar.gz"

tar xzf "$workdir/combined.tar.gz" -C "$workdir"

if [ "$dry_run" = true ]; then
    echo "[restore] --dry-run: archive contents:"
    tar tzf "$workdir/wp-content-code.tar.gz" | sed 's/^/  wp-content-code: /'
    tar tzf "$workdir/uploads.tar.gz" | sed 's/^/  uploads: /'
    echo "  db.sql: $(wc -l < "$workdir/db.sql") lines"
    echo "[restore] dry run complete, nothing was written"
    exit 0
fi

: "${MYSQL_DATABASE:?MYSQL_DATABASE must be set (see .env)}"
: "${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD must be set (see .env)}"

echo "[restore] restoring database..."
docker compose exec -T mysql mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" \
    < "$workdir/db.sql"

echo "[restore] restoring wp-content/{themes,plugins,mu-plugins}..."
tar xzf "$workdir/wp-content-code.tar.gz" -C wp-content

echo "[restore] restoring wp-content/uploads..."
docker compose exec -T -u www-data wordpress tar xzf - -C /var/www/html/wp-content \
    < "$workdir/uploads.tar.gz"

echo "[restore] done"
