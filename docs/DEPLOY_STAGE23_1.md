# Stage 23.1 — Incremental staging deploy (operator checklist)

Deploys the `stage23.1` GitHub Release to the existing Stage 20.5B staging
install. Nothing here is run by an AI agent on the server; the human operator
executes these steps over SSH (paths from `AGENTS.md` §86; credentials never
stored here or in git).

**What this release carries**

- Header cart / "Return to checkout" navigation (commit `f44e87c`).
- Forfeit/cancel Store Wallet correction (commit `5b8248e`): `ForfeitAuction`
  and `CancelAuction` no longer issue Store Wallet when there is no acquiring
  customer; the rules in `AGENTS.md` §44/§45 are the authoritative behavior.
- Runbook notes (`d4c76d4`).

**No migrations are included.** This is code + tests only. The schema is
unchanged, so `migrate` below is a no-op safeguard, not a requirement.

---

## 1. Confirm the release exists

Verify on GitHub that a non-draft Release named `stage23.1` exists with the
asset `as-is-commerce-stage23.1.zip`:

```text
https://github.com/majcreatives/as-is-commerce/releases
```

Only ever download the zip built by that Release — never a locally built one.

---

## 2. Backup + extract on staging

```bash
ssh -p 65002 u146516859@89.116.53.20
```

```bash
cd /home/u146516859/domains/darksalmon-swan-978886.hostingersite.com/public_html
# Optional but cheap: snapshot the current .env so a misstep is reversible.
cp as-is-commerce-stage20/.env .env.bak-stage23
# Clean extraction area, so no stale files survive a rename.
mv as-is-commerce-stage20 as-is-commerce-stage20.bak-23
mkdir -p as-is-commerce-stage20 temp-stage23
cd temp-stage23
# Download as-is-commerce-stage23.1.zip into this dir from the GitHub Release,
# then:
unzip as-is-commerce-stage23.1.zip -d ../as-is-commerce-stage20
cd ../as-is-commerce-stage20
# Restore the environment only (storage/framework caches rebuild below).
cp ../.env.bak-stage23 .env
```

Stop here and confirm: `ls -la` shows `.env`, `artisan`, `public`, `vendor`.
If anything looks wrong, delete the new dir and `mv as-is-commerce-stage20.bak-23 as-is-commerce-stage20`.

---

## 3. Storage + permissions

```bash
/opt/alt/php84/usr/bin/php artisan storage:link
chmod -R u+rwX storage bootstrap/cache
```

---

## 4. Clear + rebuild caches (critical — the "config ignores .env" fix)

```bash
/opt/alt/php84/usr/bin/php artisan config:clear
/opt/alt/php84/usr/bin/php artisan config:cache
/opt/alt/php84/usr/bin/php artisan route:cache
/opt/alt/php84/usr/bin/php artisan view:cache
```

## 5. Schema + boot

```bash
/opt/alt/php84/usr/bin/php artisan migrate --force
/opt/alt/php84/usr/bin/php artisan about
/opt/alt/php84/usr/bin/php artisan config:show paystack   # key present; value not printed
```

Nothing needs seeding — the staging database already holds the roles,
permissions, settings, credit packages, catalog, and rulesets from Stage 20.5B
and the audit.

## 6. Health + smoke

1. `GET https://darksalmon-swan-978886.hostingersite.com/health`
   → `{"status":"ok"}`.
2. One customer page (catalogue/product/login).
3. One admin page (dashboard).
4. No `.env` value, secret, or stack trace in any rendered HTML or log.

---

## 7. Verify the new behavior (audit continues here)

### 7a. Header cart

1. Log in as a test customer with **no** payable checkout (e.g. user 3): the
   cart icon must **not** appear on `/dashboard`.
2. Start a Buy Now checkout on any product (do not cancel it yet): the cart
   icon must now appear next to the account menu (and "Return to checkout" in
   the mobile drawer), linking to `/checkout/{order_number}`.
3. The icon must vanish again once that checkout is paid or cancelled.

### 7b. Flow 4 — Forfeit + Relist (corrected behavior)

1. Admin creates/starts a live auction; have a customer bid.
2. Let the auction close (winner determined, settlement checkout opened) —
   or use an existing PendingSettlement auction.
3. Let `settlement_due_at` pass, then run:

   ```bash
   /opt/alt/php84/usr/bin/php artisan auctions:tick
   ```

   Expect: auction `Forfeited` (`closure_reason=forfeited`), winner settlement
   order `Cancelled`, inventory reservation released (`stock_reserved`
   back down, release inventory transaction).
4. Expect **no** `auction-loss:{auctionId}:{userId}` Store Wallet rows and no
   Store Wallet balance change for any bidder (the fix). Verify:

   ```bash
   /opt/alt/php84/usr/bin/php artisan tinker --execute="
   dump(App\Models\StoreWalletTransaction::where('idempotency_key','like','auction-loss:{auctionId}:%')->get());
   "
   ```

5. Admin relists the forfeited auction with the ruleset + settlement amount:
   a new Draft auction appears, original marked `relisted` pointing at the
   replacement's id.

### 7c. Flow 4 — Admin cancel (corrected behavior)

1. Admin opens a Draft or Live auction and cancels with a reason
   (`AuctionDetail::cancel(CancelAuction)`).
2. Expect: auction `Cancelled`, any outstanding settlement/checkout closed,
   inventory reservation released.
3. Expect **no** `auction-loss` Store Wallet issuance and no Store Wallet
   balance change for any bidder.

### 7d. Regression spot-check

1. Re-run **Flow 3d** style verification on a new auction close (winner gets
   compensation for losers as before): losers still receive
   `floor(credits × lot.acquisition_amount_minor / lot.original_amount)`
   keyed `auction-loss:{auctionId}:{userId}`, winner excluded.
2. Admin Exception Centre shows no unexplained exceptions.

---

## 8. After verification

- Update the Stage 23 runbook `Results` table with the Forfeit/Cancel
  outcomes and record the exact verifying output.
- Leave `as-is-commerce-stage20.bak-23` and `.env.bak-stage23` in place until
  the next deploy succeeds, then remove them.
- The old tag `stage20.5b.1` stays as the permanent versioned record; nothing
  is deleted.