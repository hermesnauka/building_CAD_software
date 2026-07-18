#!/usr/bin/env bash
# Tightens permissions on container-managed WordPress content — PLAN.md
# Phase 6 "strict file and directory permissions". Fixes two concrete
# findings from the Phase 6 security pass:
#   - wp-content/uploads was root:root (drift from an earlier root-context
#     `docker exec`), so the www-data worker process couldn't actually write
#     into it as configured.
#   - wp-config.php (DB credentials) was world-readable at 644.
# Idempotent — safe to run repeatedly (e.g. on the same schedule as backups)
# to correct drift.
#
# Only works against the base docker-compose.yml. Under
# docker-compose.hardened.yml, cap_drop: [ALL] deliberately removes
# CAP_CHOWN/CAP_FOWNER from the wordpress container, so even -u root can no
# longer chown/chmod files it doesn't already own by uid (verified: this
# script fails with "Operation not permitted" on every path when the
# hardened overlay is active). That's the intended effect of dropping those
# caps, not a bug to work around — run this script (and any other
# permission-drift fix) against the base compose file before switching to
# the hardened overlay for a demo/deployment.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

docker compose exec -T -u root wordpress bash -c '
    set -euo pipefail
    chown -R www-data:www-data /var/www/html/wp-content/uploads
    find /var/www/html/wp-content/uploads -type d -exec chmod 755 {} +
    find /var/www/html/wp-content/uploads -type f -exec chmod 644 {} +
    chown www-data:www-data /var/www/html/wp-config.php
    chmod 640 /var/www/html/wp-config.php
'

echo "container permissions tightened"
