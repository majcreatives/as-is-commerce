# Stage 23.2 — Fresh first deploy of staging (operator checklist)

Deploys the `stage23.2` GitHub Release straight onto the existing Stage 20.5B
staging install, for when **stage23.1 has NOT been deployed**. stage23.2 is
cumulative: it contains every stage23.1 code change, plus the Stage 23.2
hotfix, so deploying 23.2 alone is enough.

If stage23.1 is already on the server, use `docs/DEPLOY_STAGE23_2.md`
(overlay) instead of this guide.

**What this release carries**

- Header cart / "Return to checkout" navigation (commit `f44e87c`).
- Forfeit/cancel Store Wallet correction (commit `5b8248e`): `ForfeitAuction`
  and `CancelAuction` no longer issue Store Wallet when there is no acquiring
  customer; the rules in `AGENTS.md` §44/§45 are the authoritative behavior.
- Sweep-stamp hotfix (commit `8921e1d`): sweep commands now store ISO-8601
  strings instead of Carbon objects, fixing the admin dashboard 500.
- Runbook notes (`d4c76d4`, `f76a070`).

**No migrations are included.** This is code + tests only. The schema is
unchanged, so `migrate` in §8 is a no-op safeguard, not a requirement.

---

## 1. Confirm the release exists

On GitHub, verify a non-draft Release named `stage23.2` exists with the asset:

```text
as-is-commerce-stage23.2.zip
```

```text
https://github.com/majcreatives/as-is-commerce/releases
```

Only ever use that zip. Download it to your local machine (you can upload it
with an SFTP tool, or copy its direct download URL for the `wget` branch in
§3).

---

## 2. Log in to the server

```bash
ssh -p 65002 u146516859@89.116.53.20
```

You will be asked for your SSH password. Never store it in git or in chat.

---

## 3. Back up + create a fresh app folder

Run these one at a time:

```bash
cd /home/u146516859/domains/darksalmon-swan-978886.hostingersite.com/public_html
```

```bash
cp as-is-commerce-stage20/.env .env.bak-stage23
```

```bash
mv as-is-commerce-stage20 as-is-commerce-stage20.bak-23
```

```bash
mkdir -p as-is-commerce-stage20 temp-stage23
```

Get the zip into `temp-stage23`. Either upload the file you downloaded, or run
`wget` with the direct download URL:

```bash
cd temp-stage23
wget "PASTE_THE_DIRECT_ZIP_URL_HERE"
```

Then unzip it into the fresh folder:

```bash
unzip as-is-commerce-stage23.2.zip -d ../as-is-commerce-stage20
```

---

## 4. Restore your environment file

```bash
cd ../as-is-commerce-stage20
```

```bash
cp ../.env.bak-stage23 .env
```

**Stop and sanity-check**: run `ls -la` — you must see `.env`, `artisan`,
`public`, `vendor`. If anything is missing, stop and restore from the backup
before continuing:

```bash
cd ..
rm -rf as-is-commerce-stage20
mv as-is-commerce-stage20.bak-23 as-is-commerce-stage20
```

---

## 5. Storage + permissions

```bash
/opt/alt/php84/usr/bin/php artisan storage:link
```

```bash
chmod -R u+rwX storage bootstrap/cache
```

---

## 6. Clear + rebuild caches

```bash
/opt/alt/php84/usr/bin/php artisan config:clear
/opt/alt/php84/usr/bin/php artisan config:cache
/opt/alt/php84/usr/bin/php artisan route:cache
/opt/alt/php84/usr/bin/php artisan view:cache
```

---

## 7. Clear the database cache (the actual dashboard fix)

```bash
/opt/alt/php84/usr/bin/php artisan cache:clear
```

This purges old `sweeps:*:last_run` values serialized as Carbon objects, which
the new runtime would otherwise misread and crash the admin dashboard's
Scheduler block. **Skipping this step leaves the dashboard 500ing.** It touches
no financial/auction data (those live in tables, not the cache) and no sessions
(sessions are a separate table).

---

## 8. Schema + boot

```bash
/opt/alt/php84/usr/bin/php artisan migrate --force
```

```bash
/opt/alt/php84/usr/bin/php artisan about
```

`about` should show environment `staging` and healthy values. If it throws,
record the exact error before continuing.

As an extra confirmation the hotfix landed:

```bash
grep -n "toIso8601String" app/Console/Commands/RunAuctionClock.php
```

Expect `sweeps:auctions_tick:last_run` with `toIso8601String()` on the same
line.

---

## 9. Health + smoke

1. `GET https://darksalmon-swan-978886.hostingersite.com/health`
   → `{"status":"ok"}`.
2. Sign in as the **super_admin** and open Admin → Operations. The page must
   load (no 500). The Scheduler block may show an empty state until each sweep
   next runs — that is the honest state, not an error.
3. No `.env` value, secret, or stack trace should appear in any rendered HTML
   or log.

---

## 10. Clean up only after verification

Do **not** delete the backups until everything is verified:

```text
as-is-commerce-stage20.bak-23
.env.bak-stage23
```

Once verified, remove them and return to the Stage 23 audit.

---

## 11. Back to the Stage 23 audit

Continue with `docs/DEPLOY_STAGE23_1.md` §7:

- **7a** — Header cart: icon appears only when a payable checkout exists and
  links to `/checkout/{order_number}`.
- **7b** — Flow 4 Forfeit + Relist: auction Forfeited, settlement order
  Cancelled, reservation released, and **no** `auction-loss:{auctionId}:{userId}`
  Store Wallet rows (the fix).
- **7c** — Flow 4 Admin cancel: auction Cancelled, checkout closed, reservation
  released, **no** Store Wallet issuance (the fix).
- **7d** — Regression spot-check: a normal auction close still issues loser
  compensation `floor(credits × lot.acquisition_amount_minor / lot.original_amount)`,
  winner excluded; Exception Centre shows no unexplained exceptions.

Update the Stage 23 runbook `Results` table with the outcomes and record the
exact verifying output.