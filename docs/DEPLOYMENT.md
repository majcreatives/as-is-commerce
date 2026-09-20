# Hostinger deployment runbook

Stage 19 companion to the canonical [Deployment](../README.md#deployment) and
[Operations: backup, recovery and rollback](../README.md#operations-backup-recovery-and-rollback)
sections. This file is the step-by-step operational sequence; the README owns the
policy. Where they differ, the README wins.

Target: **Hostinger Premium Web Hosting / hPanel**. The application is designed
for shared hosting — PHP, MySQL and cron, with no persistent daemons, no Redis
and no Reverb. Nothing in this runbook assumes a VPS.

## 1. Requirements (verified against the code)

| Need | Evidence | Hostinger |
| --- | --- | --- |
| PHP 8.3+ | `composer.json` declares `^8.3` | hPanel PHP selector (8.2–8.5; use 8.3) |
| MySQL 8 | `SELECT ... FOR UPDATE`, CHECKs and append-only triggers | included |
| Composer | dependency install | pre-installed on Premium |
| Cron | auction clock, checkouts, refund reconciliation | hPanel scheduled tasks, UTC |
| HTTPS | webhooks, secure cookies, generated URLs | free hPanel SSL |

Node.js is **build-time only**: the deployable is produced by the GitHub Actions
release workflow, which runs `npm run build` on a clean runner. `public/build/`
ships inside the release zip. The server never runs Node.

## 2. Sequence

**GitHub is in the loop.** The deployable is never built ad hoc on a developer
machine and never edited on the server. It is produced by the
`build-deploy.yml` workflow from a pushed `stage*` tag and attached to the
corresponding GitHub Release. The developer machine and the server both consume
that artifact.

Produced by GitHub Actions, before anything is uploaded:

1. `composer install --no-dev --optimize-autoloader` (deterministic: lockfile).
2. `npm ci && npm run build` — produces `public/build/`.
3. Optional quality gates can be added to the workflow (Pint, PHPStan, tests)
   without changing this sequence.

Completed on the server (SSH):

1. Place the application above the web root; only `public/` is webserved.
   If the plan forces `public_html`, put the application in a sibling directory
   and let `public_html` hold the contents of `public/`, with `index.php`
   requiring the bootstrap one level up. **Never** put `.env` in the web root.
2. Point the document root at `public/`.
3. `composer install --no-dev --optimize-autoloader --no-interaction` — only
   needed if `vendor/` is not already present; the release zip from the
   workflow ships it.
4. Create `.env` from `.env.example`; generate `APP_KEY` with
   `php artisan key:generate`. Never commit `.env`; never paste secrets into a
   support ticket.
5. `php artisan migrate --force`. **Never** `migrate:fresh` against production.
6. Seed reference data once (all idempotent): `RoleSeeder`,
   `PermissionSeeder`, `SettingsSeeder`, `CreditPackageSeeder`.
7. `php artisan app:create-admin --role=super_admin` — first administrator.
   Accounts are never seeded and no password is committed.
8. `php artisan storage:link` (writable public storage).
9. `php artisan config:cache && php artisan route:cache && php artisan view:cache`.
   Re-run all three after every deployment that touches config, routes or views
   — a stale config cache is the commonest cause of a live app "ignoring" `.env`.
10. Set the cron entry (below).

### 2a. Upgrading an existing install (directory swap)

The staging runbooks (`DEPLOY_STAGE*.md`) replace the application by renaming
the live directory to a `.bak-*` name and extracting the release zip into a
fresh one. The zip contains **no user data**, so the swap orphans anything the
application wrote at runtime. Before the rename, carry over:

- **`.env`** — copy it into the new directory (the runbooks do this).
- **`storage/app/public`** — uploaded product images (Stage 30.4 galleries).
- **`storage/app/private`** — private uploads.

```bash
cp -a old/storage/app/public/.  new/storage/app/public/
cp -a old/storage/app/private/. new/storage/app/private/
find new/storage/app -type f | wc -l   # must equal the same count in old/
```

After the swap, recreate the public link, because `public/storage` is a
symlink whose target is an **absolute path into the old directory name**:

```bash
rm -f public/storage && php artisan storage:link
```

Then `chmod -R u+rwX storage bootstrap/cache`, re-run the three caches, and
`migrate --force`. Skipping the copy does not fail loudly — the app boots, but
product images 404 until the files are restored from the backup directory.

## 3. Cron

One line drives every schedule — auctions opening/closing, settlement
checkouts, checkout expiry, refund reconciliation:

```
* * * * * cd /home/<user>/domains/<domain>/ && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

- hPanel cron runs on **UTC**, matching `APP_TIMEZONE=UTC`. No offset anywhere.
- Confirm the PHP binary path in hPanel; it is usually version-specific.
- Confirm the plan's real minimum interval. If it cannot run every minute,
  auctions still close **correctly** — just **late**, by up to the cron
  interval — because winners are resolved from the bid records when the sweep
  finally runs and those records do not change while it is late.
- Overlap is bounded, never trusted: each sweep runs with
  `withoutOverlapping()` plus an explicit expiry (`App\Support\ScheduleLocks`),
  so a killed run blocks the next for minutes, not a day.

Verify: `php artisan schedule:list`; then watch the scheduler status card / the
`sweeps:*:last_run` cache stamps after the first tick.

## 4. Queue

- Driver is `database`; **nothing is queued by default**. All financial and
  inventory operations are synchronous and transactional — the application is
  fully correct with **no worker running at all**.
- The single optional queued job is notification email, behind
  `NOTIFICATIONS_QUEUE_MAIL`. **Off by default on purpose**: queued mail needs a
  worker, and shared hosting runs cron rather than daemons, so enabling it
  without a worker would stop email silently.
- To enable it, add a second, finite worker cron (drains then exits within the
  minute; Supervisor is not available on hPanel and is not required):

```
* * * * * cd /home/<user>/domains/<domain>/ && /usr/bin/php artisan queue:work --queue=notifications --stop-when-empty --max-time=55 >> /dev/null 2>&1
```

## 5. Environment

Source of truth: `.env.example` (committed) and `.env` (never committed).
The production table is in the README's
[production configuration checklist](../README.md#production-configuration-checklist).

Non-negotiable in production:

- `APP_ENV=production`, `APP_DEBUG=false` (a stack trace is a disclosure).
- `APP_URL=https://<domain>`, `SESSION_SECURE_COOKIE=true`, HTTPS forced.
- `LOG_LEVEL=warning` or above — `debug` fills a shared-hosting disk.
- `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` = `database`. No Redis.
- `BROADCAST_CONNECTION=null` — no Reverb server exists to broadcast to; the
  auction room polls.
- `MAIL_MAILER` = a real transport. The `log` mailer sends nothing; configure
  SMTP. `MAIL_FROM_ADDRESS` must match the sender.

Paystack (see the README's [Paystack](../README.md#paystack) section):

- `PAYSTACK_SECRET_KEY` / `PAYSTACK_PUBLIC_KEY` from the **same** dashboard
  mode. Never mix test and live. The secret key is server-only.
- Webhook URL configured in the Paystack dashboard:
  `https://<domain>/webhooks/paystack`. Public + unauthn by design, CSRF-exempt
  in `bootstrap/app.php`, protected by an HMAC SHA512 signature verified before
  anything is stored or acted on.
- Callbacks: `/credits/callback` and `/checkout/callback`, behind HTTPS,
  authenticated, reference-scoped to the signed-in user's own payment.

## 6. Writable directories (server)

Web user needs write access to:

- `storage/` — including `storage/framework/{cache,sessions,views}`,
  `storage/logs`, `storage/app/public`
- `bootstrap/cache/`

Nothing else needs write access.

## 7. Verify a deployment

1. `GET /health` → `{"status":"ok"}` (DB reachability).
2. `GET /up` → framework boot health.
3. Customer: catalogue, product page, credit packages page, auction room
   (polling), login.
4. Admin: dashboard, exception centre, scheduler status (recent stamps).
5. Send a real notification and confirm the email transport delivers.
6. Confirm `storage/logs/laravel.log` is writable and reveals no secrets.

Then run the [production smoke tests](../README.md#production-smoke-tests) —
credit purchase, webhook delivery, duplicate replay, Buy Now, auction
settlement, callback isolation, exception queue. Steps 1–3 move real money.

## 8. Rollback

- **Code**: revert the checkout and repeat §2 server steps 3, 9. A stale
  config/route/view cache is the commonest reason a rollback appears not to
  have worked — re-cache.
- **Database**: do not "un-migrate". Restore the pre-deployment backup, then
  re-apply reference seeders if the backup predates them. Migrations here are
  append-only where money flows and are intentionally irreversible; the
  rollback boundary is the backup, never schema surgery.
- Backup, recovery and rollback policy: README
  [Operations: backup, recovery and rollback](../README.md#operations-backup-recovery-and-rollback).

## 9. What this runbook does not cover

Stage 20 (live operations) responsibility:

- Live domain/DNS and enforced HTTPS.
- Live Paystack keys and dashboard webhook configuration, verified with a real
  minimum-amount purchase.
- Live SMTP relay credentials.
- The exact hPanel cron command with the real account path.
- Confirming the plan's true minimum cron interval.

Nothing here claims any of those has been performed against a live host.