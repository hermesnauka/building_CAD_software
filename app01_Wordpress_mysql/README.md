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
