#!/usr/bin/env bash
# Log-scraping intrusion detection — PLAN.md Phase 6 ("activate monitoring
# and intrusion detection systems") and Phase 7 ("continuously monitor
# server logs"). Tails the wordpress container's logs and appends a
# human-readable alert for two signals:
#
#   1. Account lockouts emitted by wp-content/mu-plugins/auth-audit-log.php
#      — the mu-plugin already stops the attack account-side; this script's
#      job is purely to surface it for a human/on-call process to see, the
#      way a fail2ban log watcher would.
#   2. A spike of repeated PHP Fatal errors within a short window — a
#      common pre-exploitation signal (an attacker probing a plugin
#      vulnerability, or a broken update, throws repeated fatals before
#      anything worse happens) that isn't tied to any specific account, so
#      it can't be caught by the lockout mechanism above.
#
# Run once to process what's happened so far (default), or with --follow to
# tail continuously (suitable for a systemd service, not a timer).
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

if [ -f .env ]; then
    set -a
    # shellcheck disable=SC1091
    source .env
    set +a
fi

LOG_DIR="${LOG_DIR:-./logs}"
mkdir -p "$LOG_DIR"
ALERT_LOG="$LOG_DIR/security-alerts.log"
STATE_FILE="$LOG_DIR/.intrusion-watch-last-run"

PHP_FATAL_SPIKE_THRESHOLD="${PHP_FATAL_SPIKE_THRESHOLD:-3}"
PHP_FATAL_SPIKE_WINDOW_SECONDS="${PHP_FATAL_SPIKE_WINDOW_SECONDS:-300}"
declare -a FATAL_TIMESTAMPS=()
fatal_spike_alerted=false

follow=false
if [ "${1:-}" = "--follow" ]; then
    follow=true
fi

process_line() {
    local line="$1"

    if [[ "$line" == *"CAD_EDU_AUTH: LOCKOUT triggered"* ]]; then
        echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] ALERT: $line" >> "$ALERT_LOG"
        echo "ALERT: $line"
        return
    fi

    if [[ "$line" == *"PHP Fatal error:"* ]]; then
        local now pruned=() ts
        now="$(date +%s)"
        FATAL_TIMESTAMPS+=("$now")

        for ts in "${FATAL_TIMESTAMPS[@]}"; do
            if (( now - ts <= PHP_FATAL_SPIKE_WINDOW_SECONDS )); then
                pruned+=("$ts")
            fi
        done
        FATAL_TIMESTAMPS=("${pruned[@]}")

        if (( ${#FATAL_TIMESTAMPS[@]} >= PHP_FATAL_SPIKE_THRESHOLD )); then
            if [ "$fatal_spike_alerted" = false ]; then
                local alert_line="PHP_FATAL_SPIKE: ${#FATAL_TIMESTAMPS[@]} PHP Fatal errors within ${PHP_FATAL_SPIKE_WINDOW_SECONDS}s — possible active exploitation attempt or broken update. Latest: $line"
                echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] ALERT: $alert_line" >> "$ALERT_LOG"
                echo "ALERT: $alert_line"
                fatal_spike_alerted=true
            fi
        else
            fatal_spike_alerted=false
        fi
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
