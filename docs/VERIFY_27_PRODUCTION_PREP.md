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

#### Why `--triggers` is not optional here

The documented command already carries `--triggers`, and it is worth recording
why that flag is the whole ballgame rather than a nicety.

**This schema has 28 triggers, and they are the financial integrity layer.** They
are what makes the append-only and freezing rules true at the database rather
than only in application code:

| Category | Examples | Enforces |
|---|---|---|
| Append-only ledgers | `credit_transactions_no_update/_no_delete`, `cash_transactions_*`, `inventory_transactions_*`, `store_wallet_transactions_*` | never UPDATE or DELETE a financial history row (Golden Rules 2–4, §13, §27) |
| Immutable bids | `bids_no_update`, `bids_no_delete` | a bid can never be rewritten or removed (§20) |
| Frozen facts | `auctions_frozen_configuration`, `orders_frozen_after_payment`, `order_payments_frozen_request`, `refunds_frozen_request`, `credit_lots_acquisition_frozen`, `deliveries_frozen_address`, `referrals_frozen_relationship` | configuration and commercial figures stop changing at a known point (§16, §25, §48) |

A backup that restores the tables but not the triggers produces a database that
*looks* healthy and will silently accept `UPDATE credit_transactions SET amount
= ...`. That is worse than having no backup, because it is the state you would
only discover during an incident. So the restore test must assert on trigger
**behaviour**, not on trigger count — a trigger can be present and inert.

#### Roundtrip verified locally (2026-09-28)

Run against a scratch database using the test database as the source, because it
holds real ledger rows rather than the near-empty development database. Nothing
was written to either source database; all tampering was against the restored
copy. The scratch databases and the temporary credentials file were deleted
afterwards.

| Check | Result |
|---|---|
| `mysqldump --single-transaction --routines --triggers` | exit 0, no stderr |
| Dump size / duration | 324.5 KB, 0.82s |
| Restore into empty scratch DB | exit 0, no stderr, 4.4s |
| Tables restored | 52 of 52 |
| Triggers restored | 28 of 28 |
| Engine | all InnoDB, so `--single-transaction` is a real consistent snapshot |
| Collation | `utf8mb4_unicode_ci` on all 52, no drift |
| Row counts | `credit_transactions` 1→1, `store_wallet_transactions` 3→3, `inventory_transactions` 2→2, `users` 1→1 |

Behaviour after restore, which is the check that matters:

| Tamper attempt on the restored DB | Result |
|---|---|
| `UPDATE credit_transactions SET amount=999999` | refused: *Financial history is append-only… Post a compensating entry instead.* |
| `DELETE FROM store_wallet_transactions` | refused: *Store Wallet history is append-only…* |
| `UPDATE inventory_transactions SET quantity_delta=999` | refused: *Inventory history is append-only: post a correcting adjustment instead.* |
| `UPDATE orders SET total_minor=1` on a `paid` order | refused: *A paid order is a historical record…* |
| `UPDATE auctions SET settlement_amount_minor=777` on a non-`draft` auction | refused: *An auction configuration is frozen once the auction leaves draft.* |

All trigger **bodies** survived verbatim, including the null-safe `<=>`
comparisons and the per-column lists, so the conditional paths work and not just
the trigger names.

Two behaviours worth knowing, both of which look like a gap and are not:

- A `draft` auction is exempt from the freezing trigger, and a `pending_payment`
  order is exempt from the order-freezing trigger. Changing them is allowed by
  design.
- The freezing triggers compare `NEW.col <=> OLD.col`, so re-writing a column to
  the value it already holds does not fire. Setting a field to its existing
  value is not tampering.

#### What was NOT verified

Stated plainly, because it matters and the local run cannot settle it:

- **The production dump must be taken with the host's MariaDB `mysqldump`**, not
  the MySQL 8.0 one used here. This dump was produced by MySQL 8.0.46 and shows
  no MySQL-8-only constructs — no `utf8mb4_0900_*` collation, no `/*!80000`
  conditional blocks, highest directive `/*!50503`, and the only `GENERATED
  ALWAYS` columns use syntax MariaDB supports — so it is *indicatively* portable.
  Indicative is not verified. The MariaDB roundtrip has to be re-run on the real
  host as part of this flow.
- **No restore has been performed against MariaDB 11.8.x at all.** The local
  server is MySQL 8.0.46.
- No timing at production data volume, no off-site copy, and no restore under
  real load. 0.82s and 4.4s are small-database numbers and say nothing about a
  database three orders of magnitude larger.

#### Operational notes

- **The dump does not create the target database.** It contains no `CREATE
  DATABASE`, no `USE`, and no `GRANT`, so a restore is
  `CREATE DATABASE` → `mysql <db> < backup.sql`, and users/privileges are not
  part of the backup. That is usually correct, but it means a restore is two
  steps and the second one is easy to forget.
- **Do not pass the password on the command line.** It lands in the process
  argument list. Use `--defaults-extra-file` with a `my.cnf` outside the repo,
  and delete it afterwards. Never commit one.
- `retention: ≥ 7 daily + 1 weekly` is still a policy statement; nothing in the
  application schedules or performs backups, and nothing stores a backup off the
  host. Until that exists, a backup that nobody offloads is one disk failure from
  being worth nothing.

### Flow H — Monitoring surface

1. `GET /up` → 200; `GET /health` → `{"status":"ok","database":"ok"}` on the
   production origin.
2. Record that `/admin/dashboard` loads, scheduler status shows fresh stamps,
   and the exception centre is empty.
3. `storage/logs` writable and free of secrets after the above steps.
4. `php artisan app:check-environment --strict` reports `logging.rotation` as
   `ok`. The production template ships `LOG_STACK=daily` for this reason: the
   `single` driver never trims itself, and a full disk stops Laravel writing
   compiled views and caches, which is an outage rather than a large file.
   Confirm `ls -la storage/logs` shows date-stamped files after a write.

### Flow H2 — Known monitoring gap, accepted

`logging.delivery` reports a **warning** on any install that has no channel in
use that sends the log off the host, and it should stay a warning rather than
being closed before there is money flowing. The reasoning is in the finding
itself: a log file on the host is evidence, not alerting, so a payment path that
breaks at 3am is found by a customer rather than by us.

This is a recorded gap, not an oversight, and closing it means choosing a
hosted error tracker during a stage that has not been approved. Re-check it at
the first stage after launch.

Two things this audit did find, and which are now fixed rather than deferred:

- **The test suite was writing into the production log.** `phpunit.xml` pins
  `CACHE_STORE`, `QUEUE_CONNECTION`, `SESSION_DRIVER` and `MAIL_MAILER` for
  test isolation and had left `LOG_CHANNEL` as the one it missed, so a full run
  appended every financial log line to `storage/logs/laravel.log` — the same
  file a real error lands in. It had reached 284MB, at which point reading the
  tail of it to find a production error took over five minutes. `LOG_CHANNEL`
  is now pinned to a dedicated rotating `testing` channel at
  `storage/logs/testing.log`, kept as a file rather than silenced, because when
  a test fails the log explaining what the domain was doing just beforehand is
  most of the diagnosis.
- **The first version of the `logging.delivery` check was wrong.** It tested
  which channels were *defined* in `config/logging.php`, and Laravel defines a
  `slack` channel out of the box — so it reported error reporting as configured
  for every installation on earth. It now tests which channels are *in use*.
  The same distinction the rest of this project is careful about: a defined
  channel is not a configured one.

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