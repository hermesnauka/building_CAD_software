#!/usr/bin/env bash
# Basic container health/resource monitoring — PLAN.md Phase 6 ("activate
# monitoring and intrusion detection systems"). Deliberately simple: wraps
# `docker compose ps` + `docker stats` rather than standing up a full
# Prometheus/cadvisor stack, which is more than a course demo needs. Meant
# to run on a schedule (see deploy/systemd/cad-edu-healthcheck.timer).
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

LOG_DIR="${LOG_DIR:-./logs}"
mkdir -p "$LOG_DIR"
ALERT_LOG="$LOG_DIR/security-alerts.log"

MEM_WARN_PERCENT="${HEALTH_MEM_WARN_PERCENT:-90}"
CPU_WARN_PERCENT="${HEALTH_CPU_WARN_PERCENT:-90}"

timestamp="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
problems=0

while IFS=$'\t' read -r name service state health; do
    [ -z "$name" ] && continue
    if [ "$state" != "running" ]; then
        echo "[$timestamp] ALERT: container $name (service $service) is not running (state=$state)" | tee -a "$ALERT_LOG"
        problems=$((problems + 1))
        continue
    fi
    if [ -n "$health" ] && [ "$health" != "healthy" ] && [ "$health" != "" ]; then
        echo "[$timestamp] ALERT: container $name (service $service) is unhealthy (health=$health)" | tee -a "$ALERT_LOG"
        problems=$((problems + 1))
    fi
done < <(docker compose ps --format json | python3 -c '
import json, sys
for line in sys.stdin:
    d = json.loads(line)
    name = d.get("Name", "")
    service = d.get("Service", "")
    state = d.get("State", "")
    health = d.get("Health", "")
    print(f"{name}\t{service}\t{state}\t{health}")
')

while IFS=$'\t' read -r name cpu mem; do
    [ -z "$name" ] && continue
    cpu_int="${cpu%.*}"
    mem_int="${mem%.*}"
    if [ -n "$cpu_int" ] && [ "$cpu_int" -ge "$CPU_WARN_PERCENT" ] 2>/dev/null; then
        echo "[$timestamp] ALERT: container $name CPU usage ${cpu}% >= ${CPU_WARN_PERCENT}% threshold" | tee -a "$ALERT_LOG"
        problems=$((problems + 1))
    fi
    if [ -n "$mem_int" ] && [ "$mem_int" -ge "$MEM_WARN_PERCENT" ] 2>/dev/null; then
        echo "[$timestamp] ALERT: container $name memory usage ${mem}% >= ${MEM_WARN_PERCENT}% threshold" | tee -a "$ALERT_LOG"
        problems=$((problems + 1))
    fi
done < <(docker stats --no-stream --format '{{.Name}}\t{{.CPUPerc}}\t{{.MemPerc}}' | tr -d '%' | grep -E '^cad_edu_')

if [ "$problems" -eq 0 ]; then
    echo "[$timestamp] health-check: all containers healthy, resource usage within thresholds"
else
    echo "[$timestamp] health-check: $problems problem(s) found, see $ALERT_LOG"
fi
