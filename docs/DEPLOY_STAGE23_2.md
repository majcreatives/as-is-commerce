# Stage 23.2 — Incremental staging hotfix (operator checklist)

Follow-up to `docs/DEPLOY_STAGE23_1.md`. Applies the fix for the admin
dashboard 500 after the Stage 23.1 deploy. Executed by the human operator over
SSH (paths from `AGENTS.md` §86; credentials never stored here or in git).

**Root cause (already fixed in code)**

The admin dashboard (Operations → Scheduler block) 500'd because the four
sweep commands stamped the database cache with **Carbon objects**
(`Cache::put('sweeps:...:last_run', Carbon::now())`). After the Stage 23.1
extraction, the new runtime unserialized those old serialized objects as
`__PHP_Incomplete_Class`, and `SchedulerStatus::all()` died calling
`->toIso8601String()` on one (`SchedulerStatus.php:66`).

Fix commit `8921e1d` makes every sweep store **ISO-8601 strings**
(`now()->toIso8601String()`) and the reader return the raw string. The view
already parses it with `Carbon::parse`, so display is unchanged.

**MUST also clear the cache on the server.** The corrupt serialized entries are
already sitting in the staging database cache. Deploying the code alone is not
enough; `cache:clear` below purges them. Until then the dashboard would still
500. Cache holds only operational stamps/scheduler locks — nothing financial.

---

## What this release carries

- Sweep last-run stamps stored as ISO strings (commit `8921e1d`).
- Stage 23.1 content (already on the server): header cart, forfeit/cancel
  Store Wallet correction, runbook notes.

**No migrations.** Code + tests only — schema unchanged.

---

## 1. Confirm the release exists

A non-draft GitHub Release named `stage23.2` with the asset
`as-is-commerce-stage23.2.zip`:

```text
https://github.com/majcreatives/as-is-commerce/releases
```

Only ever download that zip.

---

## 2. Overlay-extract over the current app (keeps `.env`)

The Stage 23.1 app is already in place and working except for the dashboard.
There is no need to move directories again: `unzip` overwrites files, and the
zip excludes `.env` and runtime storage data.

```bash
cd /home/u146516859/domains/darksalmon-swan-978886.hostingersite.com/public_html
cp as-is-commerce-stage20/.env .env.bak-stage23.2
mkdir -p temp-stage23.2
cd temp-stage23.2
# Download as-is-commerce-stage23.2.zip into this dir from the GitHub Release,
# then:
unzip -o as-is-commerce-stage23.2.zip -d ../as-is-commerce-stage20
```

Confirm the changed file landed:

```bash
grep -n "toIso8601String" ../as-is-commerce-stage20/app/Console/Commands/RunAuctionClock.php
```

Expect `sweeps:auctions_tick:last_run` with `toIso8601String()` on the same line.

---

## 3. Caches

```bash
cd ../as-is-commerce-stage20
/opt/alt/php84/usr/bin/php artisan config:clear
/opt/alt/php84/usr/bin/php artisan config:cache
/opt/alt/php84/usr/bin/php artisan route:cache
/opt/alt/php84/usr/bin/php artisan view:cache
```

**Purge the corrupt stamped objects (the actual fix on the server):**

```bash
/opt/alt/php84/usr/bin/php artisan cache:clear
```

This clears the database cache store, removing every old `sweeps:*:last_run`
incomplete-class value. It touches no financial/auction data (those live in
tables, not the cache) and no sessions (sessions are a separate table).

---

## 4. Verify

1. `php artisan about` — env `staging`, caches rebuilt.
2. Log in as the super_admin, open the **Admin** tab → Operations.
   The Scheduler block will show "Last ran …" only after each sweep next runs;
   until then it shows the empty state, which is correct (the docblock in
   `SchedulerStatus` calls this the honest state).
3. Confirm the former 500 is gone. `tail -n 10 storage/logs/laravel.log`
   should show no new `Incomplete object` errors after the reload.
4. The sweeps re-stamp ISO strings on their next run (`auctions:tick` /
   `orders:expire-checkouts` run per schedule; refunds/referrals are manual or
   scheduled). Once a sweep runs, its block on the dashboard shows a time.

---

## 5. Back to the Stage 23 audit

Continue with `docs/DEPLOY_STAGE23_1.md` §7 (7a cart, 7b forfeit+relist,
7c admin cancel, 7d regression). No other changes are needed from this
hotfix.