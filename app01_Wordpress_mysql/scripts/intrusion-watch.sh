#!/usr/bin/env bash
# Log-scraping intrusion detection — PLAN.md Phase 6 ("activate monitoring
# and intrusion detection systems"). Tails the wordpress container's logs
# (which include the structured lines emitted by
# wp-content/mu-plugins/auth-audit-log.php), and appends a human-readable
# alert whenever an account lockout is triggered.
#
# This is the detection/alerting companion to auth-audit-log.php's actual
# blocking — the mu-plugin already stops the attack account-side; this
# script's job is purely to surface it for a human/on-call process to see,
# the way a fail2ban log watcher would.
#
# Run once to process what's happened so far (default), or with --follow to
# tail continuously (suitable for a systemd service, not a timer).
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

LOG_DIR="${LOG_DIR:-./logs}"
mkdir -p "$LOG_DIR"
ALERT_LOG="$LOG_DIR/security-alerts.log"
STATE_FILE="$LOG_DIR/.intrusion-watch-last-run"

follow=false
if [ "${1:-}" = "--follow" ]; then
    follow=true
fi

process_line() {
    local line="$1"
    if [[ "$line" == *"CAD_EDU_AUTH: LOCKOUT triggered"* ]]; then
        echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] ALERT: $line" >> "$ALERT_LOG"
        echo "ALERT: $line"
    fi
}

if [ "$follow" = true ]; then
    docker compose logs -f --no-log-prefix wordpress | while IFS= read -r line; do
        process_line "$line"
    done
else
    # One-shot mode is meant to run on a schedule (cron/systemd timer), so it
    # only scans logs emitted since the last run — `docker compose logs`
    # replays the whole container log every call, and without this it would
    # re-append the same alert on every subsequent run.
    since_arg=()
    if [ -f "$STATE_FILE" ]; then
        since_arg=(--since "$(cat "$STATE_FILE")")
    fi

    run_started_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    alert_count_before=$([ -f "$ALERT_LOG" ] && wc -l < "$ALERT_LOG" || echo 0)

    while IFS= read -r line; do
        process_line "$line"
    done < <(docker compose logs --no-log-prefix "${since_arg[@]}" wordpress)

    echo "$run_started_at" > "$STATE_FILE"
    alert_count_after=$([ -f "$ALERT_LOG" ] && wc -l < "$ALERT_LOG" || echo 0)
    echo "[intrusion-watch] scanned logs since ${since_arg[1]:-container start}; $((alert_count_after - alert_count_before)) new alert(s), $alert_count_after total in $ALERT_LOG"
fi
