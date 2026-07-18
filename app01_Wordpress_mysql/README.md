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
