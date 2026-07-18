# app01_Wordpress_mysql

WordPress + MySQL local development stack for the Construction Tech & CAD
Edu-Commerce Platform. See [`CLAUDE.md`](../CLAUDE.md), [`AGENTS.md`](../AGENTS.md),
[`REQUIREMENTS.md`](../REQUIREMENTS.md), [`PLAN.md`](../PLAN.md), and
[`USER_STORIES.md`](../USER_STORIES.md) at the repo root for the full project
context.

## Prerequisites

* Docker and Docker Compose

## Local setup

```bash
cp .env.example .env
# edit .env and set real credentials
docker compose up -d
```

WordPress will be available at `http://localhost:8080` (or `WORDPRESS_PORT`
from `.env`). Complete the WordPress install wizard on first run.

To also start phpMyAdmin for local DB inspection:

```bash
docker compose --profile dev-tools up -d
```

It will be available at `http://localhost:8081` (or `PHPMYADMIN_PORT`).

## Directory layout

* `wp-content/themes/cad-edu-theme` — custom theme.
* `wp-content/plugins/cad-edu-core` — custom plugin: CAD extension catalog
  post type and premium educational content gating.
* `wp-content/mu-plugins/ssdlc-hardening.php` — always-on hardening
  (disables XML-RPC, hides WP version, per `PLAN.md` Phase 4).

## Security notes

* `.env`, `wp-config.php`, and uploaded media are gitignored — never commit
  real credentials or user-uploaded content.
* No payment card or bank data should ever be stored in MySQL; all payment
  methods (BLIK, PayPal, PayU/Przelewy24) must be integrated via
  tokenized/off-site flows per `REQUIREMENTS.md`.
* This compose file is for local development only. Production deployment
  must follow `PLAN.md` Phases 5–7 (security testing, hardened deployment,
  monitoring).

### File and directory permissions (PLAN.md Phase 6)

Two paths are bind-mounted from the host (owned by whoever checked out the
repo) and two are container-managed (owned by `www-data` inside the
container) — a single chmod pass can't cover both, so there are two
scripts:

| Path | Owner | Mode | Fixed by |
|---|---|---|---|
| `wp-content/mu-plugins/`, `wp-content/plugins/cad-edu-core/`, `wp-content/themes/cad-edu-theme/` | host user (e.g. `hermes`) | `755`/`644` | `scripts/fix-host-permissions.sh` |
| `wp-content/uploads/` | `www-data` (in-container) | `755`/`644` | `scripts/fix-container-permissions.sh` |
| `wp-config.php` | `www-data` (in-container) | `640` | `scripts/fix-container-permissions.sh` |

Run both after first setup and periodically thereafter to correct drift
(e.g. from a prior root-context `docker exec`):

```bash
bash scripts/fix-host-permissions.sh
bash scripts/fix-container-permissions.sh
```

A hardened Compose overlay is also available for exercising a
production-like posture locally — `cap_drop: [ALL]` (with only the specific
capabilities each service's entrypoint needs added back), `read_only` root
filesystems with `tmpfs` for the few paths that must stay writable,
per-service resource limits, and image digests pinned to what was verified
during the Phase 6 security pass:

```bash
docker compose -f docker-compose.yml -f docker-compose.hardened.yml up -d
```

`scripts/fix-container-permissions.sh` only works against the base
`docker-compose.yml` — the hardened overlay's dropped `CAP_CHOWN`/
`CAP_FOWNER` mean even `-u root` can no longer chown/chmod files it doesn't
already own, which is the overlay working as intended, not a bug. Run
permission fixes against the base stack before switching to the hardened
overlay for a demo.

A real production host would set these ownership/mode targets at image
build time (`Dockerfile` `COPY --chown=...` / a non-root `USER`) rather
than via ad hoc `docker exec` after the fact — the scripts above are a
course-demo stand-in for that, since this project has no Dockerfile.

### Backups (PLAN.md Phase 6 / USER_STORIES.md US-09)

```bash
bash scripts/backup.sh
```

Dumps the database (`mysqldump --single-transaction`, no downtime), tars
`wp-content/{themes,plugins,mu-plugins}` and `wp-content/uploads`, combines
them, and encrypts the result with a symmetric GPG passphrase
(`BACKUP_GPG_PASSPHRASE` in `.env` — generate one with
`openssl rand -base64 32`). Archives land in `BACKUP_DIR` (default
`./backups`, gitignored) at `600`, and anything older than
`BACKUP_RETENTION_DAYS` (default 14) is pruned automatically.

To restore:

```bash
bash scripts/restore.sh backups/cad-edu-backup-<timestamp>.tar.gz.gpg --dry-run  # inspect first
bash scripts/restore.sh backups/cad-edu-backup-<timestamp>.tar.gz.gpg            # actually restore
```

This is on-host backup storage only. A real production deployment needs
off-site/object-storage replication (e.g. S3/Backblaze with versioning or
object-lock) so an attacker with host access can't also delete the
backups, and should switch from a symmetric passphrase to
`gpg --encrypt -r <recipient-pubkey>` so a compromised host can produce new
backups without being able to decrypt any of them. Both are out of scope
here — there's no real cloud target for this course demo.

### Monitoring and intrusion detection (PLAN.md Phase 6)

Scoped to what's genuinely testable on a single local host, where every
request comes from the same loopback address (no distinct attacker IPs to
key off of, unlike a real deployment's IP-based fail2ban rules):

* **`wp-content/mu-plugins/auth-audit-log.php`** logs every login
  attempt and locks an *account* (not an IP — the correct control when
  every request is `127.0.0.1`) after `AUTH_LOCKOUT_THRESHOLD` failures
  within `AUTH_LOCKOUT_WINDOW_SECONDS`, for `AUTH_LOCKOUT_DURATION_SECONDS`
  (defaults 5 / 300 / 900 — configurable in `.env`).
* **`scripts/intrusion-watch.sh`** tails `docker compose logs wordpress`
  for lockout events and appends alerts to `logs/security-alerts.log`
  (gitignored). Run it periodically (see the systemd timer below) — it
  tracks what it already scanned so it never re-alerts on the same event.
* **`scripts/health-check.sh`** checks `docker compose ps` +
  `docker stats` and alerts to the same log if a container is unhealthy,
  stopped, or over a CPU/memory threshold.

```bash
bash scripts/intrusion-watch.sh   # one-shot scan since last run
bash scripts/health-check.sh      # one-shot health/resource check
```

**Out of scope / real production only**: a managed WAF/CDN with real
visibility into distinct attacker IPs (IP-based blocking is meaningless
against a single local host); a centralized SIEM aggregating logs across
multiple hosts; real TLS termination (already correctly deferred behind
`FORCE_SSL_ADMIN` and a reverse proxy); off-site/immutable backup storage
(see above); and host-OS-level hardening (unattended-upgrades, CIS
benchmarking) that Docker Compose has no control over. A
Prometheus/cadvisor metrics dashboard was considered and deliberately
left out of this pass — the account-lockout + log-alerting + container
health check above covers what this course demo needs to concretely
test end-to-end.
