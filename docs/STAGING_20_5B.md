# Stage 20.5B — Hostinger staging deployment (operator checklist)

This is the operational sequence for the first GitHub-native deployment to the
Hostinger staging area. It is the **A1–A6** surface of the remaining roadmap
(`docs/ROADMAP.md`).

Nothing in this file is performed by an AI agent on the server. The developer
(the human) executes the server steps over SSH. Used paths come from
`AGENTS.md` §86–§87; credentials are never stored in this file or in git.

## 0. GitHub is in the loop

- All code changes were committed to `main` and pushed to `origin/main`
  **before** this checklist runs.
- The deployable is the zip attached to a GitHub Release built by the
  `build-deploy.yml` workflow — never a local hand-built package.

## A1 — Create the deployable (developer machine, via the workflow)

```bash
git checkout main
git pull origin main            # make sure origin/main is current
git tag stage20.5b.1            # the deploy decision + versioned record
git push origin stage20.5b.1    # triggers the GitHub Actions release build
```

When the workflow finishes:

- A GitHub Release named `stage20.5b.1` exists.
- It carries one asset: `as-is-commerce-stage20.5b.1.zip`.

Verify the release is there and not marked as a draft:
<https://github.com/majcreatives/as-is-commerce/releases>.

**Never download a zip built anywhere except that Release.** The whole point of
this stage is that the server only ever receives what GitHub built.

## A2 — Extract on staging

```bash
ssh -p 65002 u146516859@89.116.53.20
```

On the staging root (see `AGENTS.md` §86 for the current application path):

```bash
cd /home/u146516859/domains/darksalmon-swan-978886.hostingersite.com/public_html
mkdir -p temp-stage20
cd temp-stage20
# Download the release asset from the GitHub Release (A1), then:
unzip as-is-commerce-stage20.5b.1.zip -d ../as-is-commerce-stage20
```

## A3 — `.env` bootstrap

Copy `.env.example` to `.env` and complete it **on the server, in staging
only**. Never commit it; never paste secrets into a support ticket.

```bash
cd ../as-is-commerce-stage20
cp .env.example .env
# Edit .env now:
#   APP_ENV=staging
#   APP_DEBUG=false
#   APP_URL=https://darksalmon-swan-978886.hostingersite.com/as-is-commerce-stage20
#   DB_DATABASE=u146516859_asiscomm   (staging database, not production)
#   DB_USERNAME=u146516859_asisadmin
#   DB_HOST=127.0.0.1 / DB_PORT=3306
#   Paystack TEST keys (PAYSTACK_SECRET_KEY / PAYSTACK_PUBLIC_KEY) — test mode
#   SESSION_DRIVER, CACHE_STORE, QUEUE_CONNECTION = database
#   BROADCAST_CONNECTION=null
/opt/alt/php84/usr/bin/php artisan key:generate
```

## A4 — Storage

```bash
/opt/alt/php84/usr/bin/php artisan storage:link
```

## A5 — Permissions

Ensure the web user can write:

```bash
chmod -R u+rwX storage bootstrap/cache
chown -R <web-user>:<group> storage bootstrap/cache
```

## A6 — Artisan bootstrap

**Clear then cache config** — this is the fix for the "Paystack is not
configured" symptom that appeared when `.env` changed after an earlier cache
was built. A stale `config.php` makes the app ignore `.env`:

```bash
/opt/alt/php84/usr/bin/php artisan config:clear
/opt/alt/php84/usr/bin/php artisan config:cache
/opt/alt/php84/usr/bin/php artisan route:cache
/opt/alt/php84/usr/bin/php artisan view:cache
```

Run the schema (staging database — not production, never `migrate:fresh`):

```bash
/opt/alt/php84/usr/bin/php artisan migrate --force
```

Seed reference data once (all idempotent):

```bash
/opt/alt/php84/usr/bin/php artisan db:seed --class=RoleSeeder --force
/opt/alt/php84/usr/bin/php artisan db:seed --class=PermissionSeeder --force
/opt/alt/php84/usr/bin/php artisan db:seed --class=SettingsSeeder --force
/opt/alt/php84/usr/bin/php artisan db:seed --class=CreditPackageSeeder --force
```

Create the first administrator (a non-seeded, human-chosen account):

```bash
/opt/alt/php84/usr/bin/php artisan app:create-admin --role=super_admin
```

## Verification (must all pass before Stage 21)

```bash
/opt/alt/php84/usr/bin/php artisan about
/opt/alt/php84/usr/bin/php artisan config:show paystack   # key present; value is not printed
```

Then in the browser:

1. `GET /health` → `{"status":"ok"}`.
2. `GET /up` → framework boot health.
3. One customer page (catalogue, product, login, credit packages).
4. One admin page (login as the created administrator, dashboard).
5. Confirm no `.env` value, secret, or stack trace appears in any rendered
   page, HTML source, or log.

## Nothing else is done in this stage

- No live-money transactions.
- No production migration or production `.env`.
- No scheduler/cron yet — that is Stage 25.
- No Paystack production keys — test mode only through Stage 24.