# Incident Response Runbook

PLAN.md Phase 7 ("plan an Incident Response procedure in case of a
breach"). Covers `app01_Wordpress_mysql` specifically — references the
concrete tooling this project has actually built (Phases 4–7), not
generic advice. Five phases: Detection & Analysis, Containment,
Eradication, Recovery, Post-Incident Review, plus what's already in place
from Preparation.

## Preparation (already done, listed here for reference)

* **Backups**: `scripts/backup.sh` (daily via `deploy/systemd/cad-edu-backup.timer`),
  encrypted, retained `BACKUP_RETENTION_DAYS` days. See README "Backups".
* **Detection**: `scripts/intrusion-watch.sh` (account lockouts + PHP fatal
  spikes) and `scripts/health-check.sh` (container health), both scheduled
  via `deploy/systemd/cad-edu-healthcheck.timer`, alerting to
  `logs/security-alerts.log`.
* **Patch status**: `scripts/check-updates.sh`, scheduled weekly via
  `deploy/systemd/cad-edu-patch-check.timer`, reporting to
  `logs/patch-status.log`.
* **Access control**: MFA enforcement for admin/editor
  (`wp-content/mu-plugins/mfa-enforcement.php`), account lockout on
  repeated failed logins (`wp-content/mu-plugins/auth-audit-log.php`),
  RBAC via the `cad_student` role (`wp-content/plugins/cad-edu-core/cad-edu-core.php`).
* **Payment data**: never stored locally — WooCommerce + PayPal Payments
  handle it off-site (US-08), so a breach of this stack does not expose
  card/bank data by design.

## 1. Detection & Analysis

**Where to look first:**

```bash
tail -100 logs/security-alerts.log          # lockouts, PHP fatal spikes, unhealthy containers
bash scripts/health-check.sh                 # current container health/resource snapshot
docker compose logs --since 1h wordpress     # raw recent activity
docker compose logs --since 1h mysql
```

**Signals that indicate a likely incident** (vs. routine noise):

| Signal | Where | Likely meaning |
|---|---|---|
| Repeated `CAD_EDU_AUTH: LOCKOUT triggered` for accounts you don't recognize, or for `admin` | `security-alerts.log` | Credential-stuffing / brute-force attempt |
| `PHP_FATAL_SPIKE` alert | `security-alerts.log` | A plugin vulnerability being actively probed, or a broken update — check which file/line is fataling |
| Unexpected new `administrator`/`editor` user, or a user gaining the `cad_student`/admin role you didn't grant | DB (`wp_users`, `wp_usermeta` — see queries below) | Account compromise or privilege-escalation |
| Unexpected file under `wp-content/uploads/` with a `.php` extension | `wp-content/uploads/` | Classic web-shell upload via a vulnerable plugin/upload handler |
| Site defaced, unfamiliar admin sessions, ransom note, or scrambled DB content | Site itself / DB | Active ransomware/defacement — treat as a confirmed breach, skip straight to Containment |

**Quick DB checks** (run via `docker compose exec -T mysql mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"`
— table names below assume the default `WORDPRESS_TABLE_PREFIX=wp_` from
`.env`; adjust the prefix if it's been changed):

```sql
-- Recently created or modified user accounts
SELECT ID, user_login, user_email, user_registered FROM wp_users ORDER BY user_registered DESC LIMIT 20;

-- Who has administrator/editor/cad_student capabilities
SELECT user_id, meta_value FROM wp_usermeta WHERE meta_key = 'wp_capabilities';
```

## 2. Containment

Stop the bleeding before investigating further — a live attacker is worse
than a few minutes of downtime.

1. **Take an immediate backup of the current (possibly compromised) state**
   — do this *before* stopping anything, since `scripts/backup.sh` needs
   the containers running (`docker compose exec`), and you want a
   snapshot of exactly what the attacker left behind for later analysis:
   ```bash
   bash scripts/backup.sh
   ```
2. **Take the site offline** (prevents further damage while you work):
   ```bash
   docker compose stop wordpress
   ```
   This keeps the containers/volumes intact for forensics — do **not**
   `docker compose down -v` at this stage, that destroys evidence.
3. **Rotate every credential this stack knows about** — assume anything
   reachable from the app is compromised. MySQL's container only applies
   `MYSQL_PASSWORD`/`MYSQL_ROOT_PASSWORD` from `.env` when a data volume is
   first initialized — on an already-running stack, editing `.env` alone
   does **not** change the live database password, it just goes out of
   sync with what's actually set. Rotate the real password first, then
   update `.env` to match:
   ```bash
   docker compose exec -T mysql mysql -u root -p"$MYSQL_ROOT_PASSWORD" -e "
     ALTER USER 'root'@'%' IDENTIFIED BY '<new-root-password>';
     ALTER USER '${MYSQL_USER}'@'%' IDENTIFIED BY '<new-user-password>';
     FLUSH PRIVILEGES;"
   ```
   Then update `MYSQL_PASSWORD`/`MYSQL_ROOT_PASSWORD` in `.env` to match,
   and `docker compose up -d wordpress` to pick up the new
   `WORDPRESS_DB_PASSWORD`. Also rotate WordPress admin passwords and
   `BACKUP_GPG_PASSPHRASE` if you suspect the host itself was compromised
   (not just the WordPress app layer) — the passphrase change only affects
   *future* backups; old ones stay encrypted under the old passphrase.
4. **Disable/lock any account showing suspicious activity** rather than
   deleting it yet (deleting loses forensic evidence — you can revoke
   access without deleting):
   ```sql
   UPDATE wp_users SET user_pass = MD5(UUID()) WHERE user_login = '<suspicious_account>';
   ```

## 3. Eradication

The commands below need the `wordpress` container running
(`docker compose exec` doesn't work against a stopped one). This stack has
no reverse proxy/firewall in front of it, so restarting it re-exposes the
site on its port immediately — there's no way to inspect it "quietly" with
the tooling this project has. If that matters for your situation, block
the host port at the firewall first; otherwise:

```bash
docker compose start wordpress
```

1. **Identify the entry point.** Check `scripts/check-updates.sh` output —
   was there a known vulnerability in an outdated plugin/theme/core at the
   time of the incident? Check `logs/patch-status.log` history.
2. **Remove any planted files** — web shells are almost always dropped
   under `wp-content/uploads/` (the only world-writable, non-versioned
   directory this stack has). It lives only inside the `wp_data` volume,
   not on the host, so check it via the container, not the host
   filesystem:
   ```bash
   bash scripts/restore.sh <last-known-good-backup> --dry-run          # list what SHOULD be there
   docker compose exec wordpress find /var/www/html/wp-content/uploads -name '*.php'   # anything here is almost certainly malicious — uploads should never contain PHP
   ```
3. **Re-run the permission-fix scripts** in case the attacker altered
   ownership/permissions to persist access:
   ```bash
   bash scripts/fix-host-permissions.sh
   bash scripts/fix-container-permissions.sh
   ```
4. **Patch the vulnerability** that enabled entry — apply the plugin/core
   update `scripts/check-updates.sh` flagged, following the normal
   check-and-report-then-manually-apply process (see README "Patch
   management"), not before confirming the update actually fixes the
   specific vulnerability exploited.

## 4. Recovery

1. **Inspect the backup before restoring** — `--dry-run` only decrypts and
   lists contents, no containers required, safe to run any time:
   ```bash
   bash scripts/restore.sh <pre-incident-backup> --dry-run
   ```
2. **Bring the stack back up** — `scripts/restore.sh`'s real (non-dry-run)
   path needs both containers running (`docker compose exec`), and
   `wordpress` was stopped during Containment:
   ```bash
   docker compose up -d
   ```
3. **Restore from the last backup taken *before* the incident** (not the
   containment-phase backup from step 1 of Containment, which includes
   whatever the attacker left behind):
   ```bash
   bash scripts/restore.sh <pre-incident-backup>
   bash scripts/health-check.sh
   ```
4. **Verify the fix holds** — re-run `scripts/check-updates.sh` (should
   report the vulnerable component patched) and `scripts/intrusion-watch.sh`
   (should show no new alerts from the restored state).
5. **Force-reset all user passwords** (not just the ones you suspected)
   before declaring the site open again.

## 5. Post-Incident Review

Document, even for a course-demo incident:

* **Timeline**: when the compromise likely started (cross-reference
  `logs/security-alerts.log` and `logs/patch-status.log` timestamps
  against the vulnerability's disclosure date), when it was detected, when
  contained/recovered.
* **Root cause**: which component, which vulnerability, why the patch
  hadn't been applied yet (was it flagged by `check-updates.sh` and
  sitting unreviewed, or not yet disclosed/flagged at all?).
* **What detection worked / didn't**: did `intrusion-watch.sh` or
  `health-check.sh` actually surface this, or did you find out some other
  way? If the tooling missed it, that's the concrete next improvement —
  update the relevant script/threshold rather than just noting it.
* **Update this runbook** if any step here didn't match reality during the
  actual incident.
