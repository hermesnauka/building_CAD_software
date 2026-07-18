---
name: continue-phase
description: Pick up the next unimplemented item for an app in this monorepo by diffing its PLAN.md/REQUIREMENTS.md/USER_STORIES.md against the actual code and running containers, implementing it, and verifying against the live stack before committing. Use when the user says "continue" (bare or with an app name) about app01_Wordpress_mysql or a future appNN_* directory, or asks what's left / what's next for one of these apps.
---

# Continue Phase

This monorepo holds several training apps (`app01_Wordpress_mysql`, and future
`appNN_*` siblings), each following the same SSDLC doc pattern: root-level
`PLAN.md` (7 phases), `REQUIREMENTS.md`, `USER_STORIES.md`, `AGENTS.md` apply
to all apps; per-app docs (if any) hold only app-specific deltas — never
duplicate the root files into a subdirectory.

A bare "continue" from the user means: figure out what's actually next for
the app they're working on, not "re-read everything and ask what to do."
Follow these steps in order.

## 1. Establish state (read-only, do this every time — don't trust memory alone)

- Read the root `PLAN.md`, `REQUIREMENTS.md`, `USER_STORIES.md` (or the
  relevant sections) to know the target checklist.
- `git log --oneline -20` and `git status` to see what's already committed
  and whether the tree is clean.
- Check what's actually running: `docker ps -a` — note container names/ports
  for the app in question. If nothing is running, that itself may be the
  next task (boot the stack and verify it serves a 200).
- Grep the app's custom code (theme/plugins/mu-plugins, or equivalent for a
  non-WP app) for what's implemented vs. what the docs describe. Don't infer
  completeness from file *names* alone — read the code. A memory file may
  describe a past snapshot; the code and `git log` are ground truth over it.

## 2. Find the gap(s)

Cross-reference the PLAN.md phase list and USER_STORIES.md against the code.
Look specifically for:
- Referenced-but-undefined things (a filter checking a post type, capability,
  or option that nothing ever registers/grants — this is the single most
  common gap pattern in this codebase).
- Phase items with no corresponding code at all.
- User stories with no enforcement point (e.g. a security requirement stated
  in prose but not implemented as a hook/filter/policy).

If there are multiple independent, substantial gaps (e.g. RBAC vs. payment
integration vs. MFA), do not silently pick one. Use `AskUserQuestion` to let
the user choose scope — these are architecturally significant decisions, not
a coin flip you should make for them.

## 3. Implement

- Follow this project's SSDLC posture from the root `CLAUDE.md`: prepared
  statements, output escaping, input sanitization, tokenized payment data
  (never store card/bank data locally), least-privilege capability grants.
  No feature flags or hypothetical extensibility beyond the task.
- Keep changes scoped to the chosen gap. Don't opportunistically refactor
  unrelated code in the same pass.
- Add activation/deactivation (or equivalent setup/teardown) symmetry for
  anything that mutates persistent state (roles, capabilities, options) —
  don't leave one-way grants with no cleanup path.

## 4. Verify against the live stack, not just static review

Static review (read the diff, `php -l` / lint) is necessary but not
sufficient — this codebase has already had a bug (the dead content gate)
that would have passed a syntax check. Prefer:
- `docker exec <container> php -l <file>` (or equivalent) for a syntax pass.
- Hit the actual running endpoint with `curl` to confirm it responds as
  expected (e.g. a newly-registered post type's archive returns 200).
- Query the actual database directly (e.g. `wp_options`/`wp_user_roles` via
  `docker exec <db_container> mysql ...`) to confirm state changes really
  took effect, not just that the code looks right.

If the stack isn't running, start it (`docker compose up -d` from the app
directory) before claiming anything is verified.

## 5. Commit and report

- Stage only the files touched by this change.
- Commit message: what changed and why, referencing the PLAN.md phase and/or
  USER_STORIES.md ID(s) it closes. Do not push unless asked.
- Report back concisely: what's now done, what you verified it against (be
  specific — "confirmed via DB query" beats "should work"), and what
  remains open from the PLAN.md checklist for this app.

## Don'ts

- Don't duplicate root `CLAUDE.md`/`AGENTS.md`/`PLAN.md`/`REQUIREMENTS.md`/
  `USER_STORIES.md` into the app subdirectory — nest only an app-specific
  delta file if one is truly needed.
- Don't guess which of several substantial gaps to close — ask.
- Don't mark something "done" on the strength of a lint pass alone when
  containers are running and a real check is one `curl`/`docker exec` away.
