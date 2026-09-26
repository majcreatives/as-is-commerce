# Verify 27 — Production database/domain preparation

| | |
|---|---|
| **Stage** | 27 — Production database/domain preparation (Phase D: production launch) |
| **Gate** | Very High — Real domain + SSL; production `.env` and production database created/confirmed; production migration run; administrative accounts created; Paystack production keys only when explicitly ready; cron set; backups verified; `/health` and `/up` monitored |
| **Status** | **RUNBOOK ONLY — NOT EXECUTED.** Stage 27 touches production (real domain, production database/migration, live Paystack keys). Per AGENTS §90 and ROADMAP §4, production is a separate controlled operation requiring explicit approval and production access. Operator decision recorded: draft runbook now, do **not** touch production until approval + access granted. |
| **Runbook author** | AI agent (OpenCode) |
| **Recorded operator decisions** | Production host = **same Hostinger account** (separate domain + dedicated production database). **No real production domain yet** — a dedicated Hostinger subdomain may stand in until a real domain is decided. Paystack **live keys ready now** (configure during execution, not before). |
| **Stage 26 baseline commit** | `1404797` |
| **Writing date** | 2026-09-15 |
| **AGENTS.md anchors** | §2.5 (GitHub in the loop), §7 (secrets), §86–§89 (Hostinger paths/commands), §90 (production requires explicit approval), §117 (constraints), §118 (migration safety), §124 (diff review), §125 (completion report), §128.30 (do not deploy production without explicit approval) |
| **Policy anchors** | `README.md` — "Production configuration checklist", "HTTPS", "Production smoke tests", "Operations: backup, recovery and rollback"; `docs/DEPLOYMENT.md` — Hostinger deployment runbook; `.github/workflows/build-deploy.yml` — the deployable release workflow |

---

## 1. Purpose

Prepare the **first real production** installation of As-Is-Commerce on the
same Hostinger account that hosts staging, against a **dedicated production
domain and database**, using only the release package produced by GitHub
Actions (a pushed `stage*` tag). No local build, no hand-edited server code,
no `migrate:fresh`, and **no real money moved yet** (that is Stage 28).

This document is the gate's runbook. Until an operator executes it against
production (and records evidence), the verdict stays **PENDING**. Nothing in
this file is a claim that production has been prepared.

---

## 2. The production contract (locked by policy)

These are boundary conditions from `README.md` / `DEPLOYMENT.md`; the runbook
must not relax them.

1. **PHP/PHP extension/bounds:** production targets Hostinger Premium Web
   Hosting (hPanel), PHP 8.4 (`/opt/alt/php84/usr/bin/php`), MariaDB/MySQL,
   database cache/queue/session, cron as the scheduler, no persistent daemons,
   no Redis, no Reverb, `BROADCAST_CONNECTION=null`.
2. **GitHub is in the loop.** The deployable is only the zip attached to a
   GitHub Release built by `build-deploy.yml` from a pushed `stage*` tag
   (e.g. `stage27.1`). `main` is pushed first.
3. **`.env` is never committed.** It is created on the server from
   `.env.example`; secrets never go into git, logs, tickets or docs.
4. **Production migration is `php artisan migrate --force` on a verified
   backup.** Never `migrate:fresh`; never `migrate:rollback` across the
   append-only/irreversible boundaries (README "Migration rollback"). The
   rollback boundary is the backup.
5. **Reference seeders only** (idempotent): `RoleSeeder`, `PermissionSeeder`,
   `SettingsSeeder`, `CreditPackageSeeder`. Admin accounts are created with
   `php artisan app:create-admin --role=super_admin`; passwords are never
   committed or seeded.
6. **Configuration cache:** after every deploy,
   `config:cache && route:cache && view:cache`. A stale config cache is the
   commonest cause of a "working" rollback/live issue.
7. **Production values (README checklist):**
   `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<domain>`,
   `SESSION_SECURE_COOKIE=true`, `LOG_LEVEL=warning` or above,
   `SESSION_DRIVER/CACHE_STORE/QUEUE_CONNECTION=database`,
   `BROADCAST_CONNECTION=null`, `MAIL_MAILER=<real transport>` (not `log`).
8. **Paystack live keys only "when explicitly ready".** Operator confirmed the
   live keys are available now; they are configured only during the execution
   of Flow E below — and only the dashboard/webhook wiring happens in Stage 27,
   no real charge is made.
9. **Session cookie hardening is non-negotiable from day one.** Staging
   initially shipped without `SESSION_SECURE_COOKIE` (Stage 26 finding, fixed
   on staging). Production `.env` **must** include it before first request.

---

## 3. Planned flows (each must produce staging-recorded evidence)

Recording rule: a row is **PASS** only when the operator actually observed it
against production. Never claim a step from reasoning.

### Flow A — Domain + SSL (no real domain yet → dedicated subdomain)

> Decision: until a real domain is decided, production may use a dedicated
> Hostinger subdomain (a fresh `*.hostingersite.com` or the plan's equivalent)
> with the free hPanel SSL certificate. When a real domain is added later, it
> becomes a DNS + SSL swap (Stage 27.1+) and must not require a code change
> (the app reads `APP_URL`, which changes only in `.env`).

Evidence to record:
1. Production origin chosen (subdomain or real domain) and its hPanel web root
   path (sibling to staging, never inside `public_html/as-is-commerce-stage20`).
2. hPanel free SSL certificate issued for the origin; `force HTTPS` redirect
   enabled (server-side, so HTTP → HTTPS `301`).
3. `curl -sI https://<origin>/` → 200 over valid TLS; `http://` → 301 to https.
   HTTPS is a Paystack webhook requirement (README "HTTPS").

### Flow B — Production `.env` + dedicated production database

1. Create a **dedicated production database** in hPanel (e.g.
   `u146516859_asisprod` style name — operator picks a name that cannot be
   confused with staging `u146516859_asiscomm`). Create its own user; grant
   only what the app needs. **Never** reuse the staging database.
2. Copy the release zip's **.env.production.example** to `.env` -- NOT
   `.env.example`, which is the developer template and ships `APP_DEBUG=true`,
   `MAIL_MAILER=log` and `SESSION_SECURE_COOKIE=false`. The production template
   already has every value below set correctly, so this is a fill-in-the-blanks job
   rather than a list of corrections to remember. Then confirm:
   - `APP_ENV=production`, `APP_DEBUG=false`, `APP_TIMEZONE=UTC`
   - `APP_URL=https://<origin>`
   - `APP_KEY=` → `php artisan key:generate` (never committed)
   - `DB_*` → the production database/credentials
   - `SESSION_DRIVER/CACHE_STORE/QUEUE_CONNECTION=database`
   - `SESSION_SECURE_COOKIE=true`
   - `LOG_LEVEL=warning`
   - `BROADCAST_CONNECTION=null`
   - `MAIL_MAILER=SMTp|mailgun|...` real transport + credentials — the
     operator must supply the production SMTP credentials; `MAIL_FROM_ADDRESS`
     matches the verified sender
   - Paystack **live** `PAYSTACK_SECRET_KEY`/`PAYSTACK_PUBLIC_KEY` (both from
     the **same** dashboard mode — never mix test and live)
3. **Verify, do not assume.** After the values above are in place:

   ```bash
   /opt/alt/php84/usr/bin/php artisan config:clear
   /opt/alt/php84/usr/bin/php artisan app:check-environment --strict
   ```

   Expect exit 0 and "Nothing blocking". It reads configuration, changes
   nothing, and prints no secret values, so it is safe to paste its output
   into the evidence below. It exists because the conditions it enforces are
   the ones an installation gets wrong by copying the developer template, and
   every one of them fails silently: a stack trace served to a stranger, a
   receipt recorded as sent and never delivered, a checkout that cannot verify
   a payment. A non-zero exit means **stop**, not "log it and continue".
4. **Do not print, commit or paste any secret.** Verifications in this flow
   are flags/booleans only (e.g. `var_export(config('app.debug'), true)`).

### Flow C — Production migration (tag → release zip)

1. On `main`, the runbook/results are committed and pushed (this document).
2. Operator pushes a `stage27.*` tag → `build-deploy.yml` builds and attaches
   the zip to a GitHub Release. Record the release URL and tag.
3. Take the database backup FIRST (Flow G step 1) — the migration runs only
   against a verified backup.
4. Server side (per DEPLOYMENT §2): place app above web root, document root at
   `public/`, `composer install --no-dev --optimize-autoloader` if `vendor/`
   not in zip (it is shipped), create `.env`, storage:link, then
   `php artisan config:cache && route:cache && view:cache`.
5. `php artisan migrate --force` — record the migration count (mirror Stage 21:
   28/28 expected on a blank production schema) and that no append-only
   boundary was crossed.
6. Seed reference data once (idempotent): role/permission/settings/credit
   packages.

### Flow D — Administrative accounts

1. `php artisan app:create-admin --role=super_admin` (first admin) — record
   the created account's identity fields (never the password), and confirm the
   account can sign in to `/admin`.
2. Confirm the Gate's super-admin bypass (`AppServiceProvider` `Gate::before`)
   applies under `APP_ENV=production` (it is not env-scoped).

### Flow E — Paystack live wiring (keys ready now; no charge yet)

1. Confirm live keys resolve to the **live** dashboard (both public+secret from
   the same mode); record that values were never displayed.
2. Paystack dashboard: set webhook URL `https://<origin>/webhooks/paystack`
   (public/unauthn by design, CSRF-exempt, HMAC-protected), and the callback
   URLs `/credits/callback` and `/checkout/callback` (behind HTTPS).
3. **No real-money purchase in this stage** — the live webhook delivery and
   verified callback chain are Stage 28 (controlled smoke).

### Flow F — Production cron

Use the **absolute-path artisan form proven in Stage 25** (hPanel ignores
`cd ... &&`; 255-character command cap):

```
* * * * * /opt/alt/php84/usr/bin/php /home/<user>/domains/<origin>/artisan schedule:run >> /dev/null 2>&1
```

Record `schedule:list` (3 events, no referrals line), then the four
`sweeps:*:last_run` stamps advancing on their own after the first ticks.

### Flow G — Backups verified

1. `mysqldump --single-transaction --routines --triggers <prod-db> > backup.sql`
   (README §2368) **before** any migration; retain ≥ 7 daily + 1 weekly.
2. Verify restoreability: restore the pre-migration dump into a scratch DB and
   run a counts/spot check (schema + a few rows), or at minimum validate the
   dump file (e.g. `mysqldump` exit 0, file non-empty, grep for expected table
   headers). Record exactly what was verified and how.
3. Confirm the release zip's artifact is retrievable (the deploy rollback
   boundary also includes re-downloading the release zip).

### Flow H — Monitoring surface

1. `GET /up` → 200; `GET /health` → `{"status":"ok","database":"ok"}` on the
   production origin.
2. Record that `/admin/dashboard` loads, scheduler status shows fresh stamps,
   and the exception centre is empty.
3. `storage/logs` writable and free of secrets after the above steps.

---

## 4. Results

| Flow | Check | Result |
|---|---|---|
| A | Production origin + free SSL + HTTPS enforced (200 / 301 to https) | PENDING |
| B | Dedicated production DB + `.env` (production checklist, live Paystack, SMTP) | PENDING |
| C | `stage27.*` release zip; `migrate --force` clean on blank prod schema; idempotent seeders | PENDING |
| D | `super_admin` account created and sign-in verified; password never recorded | PENDING |
| E | Live Paystack keys same-mode; webhook/callback URLs set; no charge made | PENDING |
| F | Absolute-path cron runs `schedule:run`; 3 events; stamps advancing | PENDING |
| G | Backup taken before migration; restoreability verified; artifact retrievable | PENDING |
| H | `/up` + `/health` ok; admin loads; exception centre empty; no secrets in logs | PENDING |

Gate verdict: **PENDING (runbook only — production approval + access required).**

---

## 5. Blockers and stop conditions

- **No real domain yet.** The gate's "real domain + SSL" is satisfied by a
  dedicated subdomain until the business names a real domain; if the operator
  prefers to wait for a real domain, the runbook stalls at Flow A (recorded,
  not skipped).
- **SMTP transport.** Production `MAIL_MAILER` must be a real transport; SMTP
  credentials must be supplied by the operator. If unavailable, Flow B stalls
  rather than silently shipping `log`.
- **Paystack live webhook delivery is Stage 28** — do not perform a real
  purchase in Stage 27.
- **Never** run `migrate:fresh` or `migrate:rollback` against the production
  schema. Rollback = restore the pre-migration backup.

---

## 6. Gate close

**Verdict: PENDING**

Blocker rule: any **FAIL** blocks the gate. A **FAIL** means: reproduce locally,
fix in source, test, commit, push, re-verify the failing flow in staging, then
re-open the gate block below. This stage also requires the explicit production
approval and production access recorded at the top.

**Gate closed:** `VERIFY_27_PRODUCTION_PREP` — `main` at `<commit>` on `<date>`
— all flows PASS. Next: **Stage 28** per `docs/ROADMAP.md`.

---

## 7. Completion report template

When the stage is executed, the final commit records:

- **Changed:** files/commits/tags touched.
- **Why:** the Stage 27 gate per `docs/ROADMAP.md`.
- **Verification:** which production flows were observed and how (per flow).
- **Database:** migrations run against the dedicated production schema; backup
  verified; no append-only boundary crossed.
- **Deployment:** the `stage27.*` tag → release zip → server install path.
- **Secrets:** none committed/printed; keys used in same dashboard mode.
- **Remaining:** any unverified behavior and any operator-deferred decision
  (real domain vs subdomain; SMTP credentials if not yet supplied).