#!/usr/bin/env bash
# Patch management routine — PLAN.md Phase 7 ("establish a patch management
# routine for WordPress core, plugins, and themes"). Reports available
# updates; does NOT apply them.
#
# WordPress core already auto-applies minor/security releases
# (WP_AUTO_UPDATE_CORE=minor in docker-compose.yml, set in Phase 4) — this
# script surfaces the rest: major core upgrades, and ALL plugin updates.
# Plugins are check-and-report only, not auto-applied, because an untested
# plugin update on a live commerce site can silently break a checkout,
# RBAC, or MFA hook this project depends on (see cad-edu-core.php,
# payments-hardening.php, mfa-enforcement.php) — SSDLC change control means
# a human reviews and stages the update before it goes live, the same
# posture PLAN.md Phase 5 (security testing) already assumes for any code
# change.
#
# Only checks plugins/themes hosted on wordpress.org (akismet, two-factor,
# woocommerce, woocommerce-paypal-payments; twentytwenty{three,four,five})
# — cad-edu-core and cad-edu-theme are this project's own custom code and
# have no upstream to check against.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

LOG_DIR="${LOG_DIR:-./logs}"
mkdir -p "$LOG_DIR"
STATUS_LOG="$LOG_DIR/patch-status.log"

WP_ORG_PLUGIN_SLUGS=(akismet two-factor woocommerce woocommerce-paypal-payments)
WP_ORG_THEME_SLUGS=(twentytwentythree twentytwentyfour twentytwentyfive)

timestamp="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
updates_available=0

echo "[$timestamp] patch check starting" | tee -a "$STATUS_LOG"

echo "--- WordPress core ---"
installed_core="$(docker compose exec -T wordpress php -r 'require "/var/www/html/wp-includes/version.php"; echo $wp_version;')"
latest_core="$(curl -s "https://api.wordpress.org/core/version-check/1.7/" | python3 -c '
import json, sys
d = json.load(sys.stdin)
print(d["offers"][0]["version"] if d.get("offers") else "unknown")
')"

if [ "$installed_core" = "$latest_core" ]; then
    line="core: up to date (${installed_core})"
else
    line="core: UPDATE AVAILABLE ${installed_core} -> ${latest_core}"
    updates_available=$((updates_available + 1))
fi
echo "$line"
echo "[$timestamp] $line" >> "$STATUS_LOG"

echo "--- Plugins (wordpress.org-hosted only) ---"
for slug in "${WP_ORG_PLUGIN_SLUGS[@]}"; do
    plugin_file="wp-content/plugins/${slug}/${slug}.php"
    if [ ! -f "$plugin_file" ]; then
        # akismet's main file isn't named after its slug.
        plugin_file=$(find "wp-content/plugins/${slug}" -maxdepth 1 -name '*.php' \
            -exec grep -l 'Plugin Name' {} \; 2>/dev/null | head -1)
    fi

    if [ -z "$plugin_file" ] || [ ! -f "$plugin_file" ]; then
        echo "$slug: SKIPPED (plugin file not found)"
        continue
    fi

    installed_version="$(grep -m1 -i 'Version:' "$plugin_file" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '\r')"
    latest_version="$(curl -s "https://api.wordpress.org/plugins/info/1.0/${slug}.json" | python3 -c '
import json, sys
d = json.load(sys.stdin)
print(d.get("version", "unknown"))
')"

    if [ "$installed_version" = "$latest_version" ]; then
        line="${slug}: up to date (${installed_version})"
    else
        line="${slug}: UPDATE AVAILABLE ${installed_version} -> ${latest_version}"
        updates_available=$((updates_available + 1))
    fi
    echo "$line"
    echo "[$timestamp] $line" >> "$STATUS_LOG"
done

echo "--- Themes (wordpress.org-hosted only) ---"
for slug in "${WP_ORG_THEME_SLUGS[@]}"; do
    style_file="wp-content/themes/${slug}/style.css"
    if [ ! -f "$style_file" ]; then
        echo "$slug: SKIPPED (not installed)"
        continue
    fi

    installed_version="$(grep -m1 -i 'Version:' "$style_file" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '\r')"
    latest_version="$(curl -sG "https://api.wordpress.org/themes/info/1.2/" \
        --data-urlencode "action=theme_information" \
        --data-urlencode "request[slug]=${slug}" \
        | python3 -c '
import json, sys
d = json.load(sys.stdin)
print(d.get("version", "unknown"))
')"

    if [ "$installed_version" = "$latest_version" ]; then
        line="${slug}: up to date (${installed_version})"
    else
        line="${slug}: UPDATE AVAILABLE ${installed_version} -> ${latest_version}"
        updates_available=$((updates_available + 1))
    fi
    echo "$line"
    echo "[$timestamp] $line" >> "$STATUS_LOG"
done

echo "[$timestamp] patch check complete: ${updates_available} update(s) available" | tee -a "$STATUS_LOG"

if [ "$updates_available" -gt 0 ]; then
    exit 2
fi
