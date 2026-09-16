# Stage 30.0 — P3 shop cart deployment checklist (operator)

Deploys the `stage30.0` GitHub Release (P3, `docs/VERIFY_30_SHOP_CART_P3.md`)
to the existing staging install. Nothing here is run by an AI agent on the
server; the human operator executes these steps over SSH (paths from
`AGENTS.md` §86; credentials never stored here or in git).

**What this release carries**

- P3 — shop cart, multi-item and multi-quantity (implementation commit
  `145cc72`):
  - `carts` + `cart_items` tables (one cart per customer, lines unique per
    product, positive-quantity CHECK — MariaDB `DROP CONSTRAINT` on the way
    out, `AGENTS.md §92`). The cart is intent only: no financial record, no
    ledger, no reservation.
  - `AddToCart` / `UpdateCartLine` / `PlaceCartOrder` / `InvalidCart`;
    `CheckoutPricer::forCart`; multi-line `OrderLifecycle`
    reserve/release/sell; `StartBuyNowCheckout` catalogue delegation through
    the cart.
  - One awaiting-payment catalogue order per customer; `ProductDetail`'s buy
    control is now "Add to cart"; header cart-count badge + Return-to-checkout
    prompt; OrderFactory `withItems()`.
- P3 scope + impact doc (commit `3479d15`).

**A migration IS included** — the new `carts` and `cart_items` tables. The
Migration is additive and MariaDB-compatible; nothing applied is rewritten.

---

## 1. Create the release (local/GitHub, operator)

From a clean checkout at `origin/main` (expect `145cc72`; check `git status`
first, keep `.gitignore`'s local `.opencode` line out of any commit):

```bash
git tag stage30.0 -m "Stage 30.0 — P3 shop cart"
git push origin stage30.0
```

> Already done on 2026-09-16: `145cc72` is on `origin/main`, tag `stage30.0`
> was pushed, and the GitHub Actions `build-deploy.yml` workflow produced
> `as-is-commerce-stage30.0.zip` on the non-draft GitHub Release `stage30.0`.
> Confirm it exists before continuing:
> https://github.com/majcreatives/as-is-commerce/releases

Only ever download that zip — never a locally built one.

---

## 2. Confirm the release exists

Verify on GitHub that a non-draft Release named `stage30.0` exists with the
asset `as-is-commerce-stage30.0.zip`. (Verified 2026-09-16.)

---

## 3. Backup + extract on staging

```bash
ssh -p 65002 u146516859@89.116.53.20
```

```bash
cd /home/u146516859/domains/darksalmon-swan-978886.hostingersite.com/public_html
cp as-is-commerce-stage20/.env .env.bak-stage30.0
mv as-is-commerce-stage20 as-is-commerce-stage20.bak-30.0
mkdir -p as-is-commerce-stage20 temp-stage30.0
cd temp-stage30.0
# Download as-is-commerce-stage30.0.zip into this dir from the GitHub Release,
# then:
unzip as-is-commerce-stage30.0.zip -d ../as-is-commerce-stage20
cd ../as-is-commerce-stage20
cp ../.env.bak-stage30.0 .env
```

Stop and confirm: `ls -la` shows `.env`, `artisan`, `public`, `vendor`. If
anything looks wrong, delete the new dir and
`mv as-is-commerce-stage20.bak-30.0 as-is-commerce-stage20`.

---

## 4. Storage + permissions

```bash
/opt/alt/php84/usr/bin/php artisan storage:link
chmod -R u+rwX storage bootstrap/cache
```

---

## 5. Clear + rebuild caches

```bash
/opt/alt/php84/usr/bin/php artisan config:clear
/opt/alt/php84/usr/bin/php artisan config:cache
/opt/alt/php84/usr/bin/php artisan route:cache
/opt/alt/php84/usr/bin/php artisan view:cache
```

---

## 6. Schema + boot

```bash
/opt/alt/php84/usr/bin/php artisan migrate --force
/opt/alt/php84/usr/bin/php artisan about
/opt/alt/php84/usr/bin/php artisan tinker --execute="
dump(Schema::hasTable('carts') && Schema::hasTable('cart_items'));
"
```

Expect `true` (both tables exist). Nothing needs seeding — the staging
database is untouched otherwise.

---

## 7. Health + smoke

1. `GET https://darksalmon-swan-978886.hostingersite.com/health`
   → `{"status":"ok"}`.
2. One customer page (catalogue/product/login).
3. One admin page (dashboard).
4. No `.env` value, secret, or stack trace in any rendered HTML or log.

---

## 8. Verify P3 on staging (per `docs/VERIFY_30_SHOP_CART_P3.md` §4.2)

1. **Product page (signed-in customer):** the only buy control is **Add to
   cart** with a Quantity stepper (no Buy Now button); an item with a live
   auction instead shows **View the auction** and routes away from the cart.
2. **Header:** a cart badge shows the summed quantity across the basket and
   links to `/cart`; with a still-payable order the header also shows
   **Return to checkout** pointing at that order.
3. **Cart page:** quantities edit and lines remove/re-add server-side; per-line
   totals and the order preview (subtotal, delivery, tax, Store Wallet portion,
   payable) come from `CheckoutPricer`; nothing on the page edits money.
4. **Place order:** the whole basket becomes **one** order (checkout page shows
   every line and the frozen per-line totals); the cart is emptied; a second
   cart placement for the same customer is refused while the first is payable;
   after payment the new basket is allowed again.
5. **Expiry/cancel:** an expired or cancelled basket order releases every held
   unit and the lines become available again.
6. Existing Buy Now/credit/auction flows still behave as before (spot-check:
   one product checkout with a verified payment; one auction-led Buy Now; one
   store-wallet-assisted cart).

---

## 9. After verification

- Update `docs/VERIFY_30_SHOP_CART_P3.md` `Results` table with the outcomes
  and the gate-close verdict, and commit/push.
- Leave `as-is-commerce-stage20.bak-30.0` and `.env.bak-stage30.0` in place
  until the next deploy succeeds, then remove them.
- The tag `stage30.0` stays as the permanent versioned record; nothing is
  deleted.