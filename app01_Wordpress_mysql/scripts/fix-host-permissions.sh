#!/usr/bin/env bash
# Tightens permissions on the custom code we bind-mount into the container
# (host-owned, hermes:hermes) — PLAN.md Phase 6 "strict file and directory
# permissions". Apache only ever reads these paths (DISALLOW_FILE_EDIT is
# set, and they aren't WordPress.org-updated plugins/themes), so it's safe
# to strip group/other write bits without breaking runtime behavior. Run as
# the host user; no docker/sudo needed.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

TARGETS=(
    "wp-content/mu-plugins"
    "wp-content/plugins/cad-edu-core"
    "wp-content/themes/cad-edu-theme"
)

for target in "${TARGETS[@]}"; do
    if [ ! -d "$target" ]; then
        echo "skip (not found): $target" >&2
        continue
    fi
    find "$target" -type d -exec chmod 755 {} +
    find "$target" -type f -exec chmod 644 {} +
    echo "tightened: $target"
done
