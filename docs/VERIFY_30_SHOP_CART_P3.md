# Verify 30 — Shop cart P3 (multi-item, multi-quantity)

| | |
|---|---|
| **Work item** | **P3 — Shop cart (multi-item, multi-quantity)** (see `docs/CART_SCOPE_AND_IMPACT.md`) |
| **Model** | Full e-commerce store with a gamified credit auction channel; the shop is a real cart → order rail |
| **Risk** | Medium (new tables + one-pending-order guard + multi-line `OrderLifecycle`; no auction/settlement or payment-verification rule changed) |
| **Baseline commit (start)** | `145cc72` (P3 implementation) |
| **Runbook author** | AI agent (OpenCode) |
| **Approved by** | Operator (business owner) — P3 proceeds per the recorded sequencing decision in `docs/CART_SCOPE_AND_IMPACT.md` |
| **AGENTS.md anchors** | §2.5 (GitHub in the loop), §13/§14 (ledgers, integer minor units), §20–§23 (bids, projections, clock), §26–§29 (inventory), §32–§40 (orders/payment), §44–§47 (Store Wallet), §93–§97 (test environment/invariants), §101–§104 (domain actions, locking), §107/§108 (UI preservation, visual verification), §117/§124/§125 (constraints, diff review, completion report) |

---

## 1. Purpose

The shop is a real e-commerce channel: a customer assembles a multi-item,
multi-quantity basket, reviews it, and pays **once** — the whole basket becomes
a single order. The cart is intent only; nothing is held and nothing moves
financially until the one atomic placement, which validates every line against
the inventory ledger, reserves every line's units, prices the order from the
server, applies any Store Wallet portion, and either succeeds wholly or rolls
back to nothing. One awaiting-payment catalogue order per customer keeps an
abandoned basket from holding stock hostage, while a verified payment still
stays Paid and blocked-fulfilment when the unit was legitimately taken first.

## 2. Scope (changed, commit `145cc72`)

| # | File | Change |
|---|---|---|
| 1 | `database/migrations/2026_09_16_110000_create_cart_tables.php` | New — `carts` (one per `user_id`, unique) + `cart_items` (unique cart+product, positive-quantity CHECK, restrict product deletion); MariaDB `DROP CONSTRAINT` |
| 2 | `app/Models/Cart.php`, `app/Models/CartItem.php` | Models + `forUser` scope |
| 3 | `app/Domain/Catalog/Actions/AddToCart.php` | Load line, server cap at available stock, accumulates quantity, no ledger/reservation |
| 4 | `app/Domain/Catalog/Actions/UpdateCartLine.php`, `app/Domain/Catalog/Exceptions/InvalidCart.php` | Grow/shrink/remove; growth re-runs add rules; ownership checked |
| 5 | `app/Domain/Orders/Actions/PlaceCartOrder.php` | Atomic placement: cart lock → empty check → order-level catalogue guard → per-line validation → `CheckoutPricer::forCart` → `createOrder` → `OrderLifecycle::reserveUnit` per line (ascending product id) → Store Wallet commit → cart deleted |
| 6 | `app/Domain/Orders/Services/CheckoutPricer.php` | `forCart()` — per-line Buy Now prices, integer arithmetic, settings delivery/tax, Store Wallet eligibility, payable > 0 |
| 7 | `app/Domain/Orders/Services/OrderLifecycle.php` | Multi-line `itemsFor($order)` reserve/release/sell; `holds_reservation` once per order |
| 8 | `app/Domain/Orders/Actions/StartBuyNowCheckout.php` | Catalogue path delegates to `AddToCart` + `PlaceCartOrder`; auction path unchanged; dead auction-path reservation removed |
| 9 | `app/Livewire/Catalog/CartPage.php` + `resources/views/livewire/catalog/cart-page.blade.php` | Cart page: editable quantities, remove/clear, server-computed preview, atomic place |
| 10 | `app/Livewire/Catalog/ProductDetail.php` + blade | `addToCart` replaces `buyNow`; quantity stepper; live auction routes to the auction |
| 11 | `resources/views/partials/navigation.blade.php`, `routes/web.php` | Cart badge (`cart-count`, summed quantity), Return-to-checkout prompt, `/cart` route |
| 12 | `app/Livewire/Checkout/CheckoutPage.php` + blade | Renders the full multi-line order (`items`) instead of a single line |
| 13 | `database/factories/OrderFactory.php` | `withItems()` multi-line builder, recomputes totals/snapshot |
| 14 | Tests | New `CartCrudTest`, `CartPageTest`, `CartPlacementTest`, `CartCheckoutPageTest`, `CartPlacementConcurrencyTest`; updated `NavigationCartTest`, `MarketplaceSecurityTest`, `MarketplaceConversionTest`, `CatalogUiTest`, `CheckoutParticipationTest`, `StoreWalletConcurrencyTest`, `CheckoutTest`, `PriceSeparationTest` |

## 3. Explicitly NOT in scope (verified unchanged)

- Auction state machine, rules snapshots, closure/settlement, `HighestBidResolver`.
- Paystack verification, idempotency, payment-conflict policy, refunds.
- Credit purchase/valuation, credit lots, Store Wallet issuance rules and
  auction-Buy-Now exclusion.
- Any migration to existing tables.

## 4. Verification

### Local (done, 2026-09-16)

1. `vendor/bin/pint` — clean.
2. `vendor/bin/phpstan analyse --memory-limit=512M` — 0 errors.
3. Focused cart + affected suites (207 tests): `CartCrudTest`,
   `CartPageTest`, `CartPlacementTest`, `CartCheckoutPageTest`,
   `CartPlacementConcurrencyTest`, `NavigationCartTest`, `MarketplaceSecurityTest`,
   `MarketplaceConversionTest`, `CatalogUiTest`, `CheckoutTest`,
   `CheckoutParticipationTest`, `StoreWalletConcurrencyTest` — all PASS.
4. Full suite run in chunks (Unit/Credit/Cash/Auth/Settings/Profile/Pages,
   Auction/Realtime/Perf, Orders/Delivery/Schedule/Wallet/StoreWallet,
   Catalog/Marketplace/Concurrency, Admin/Payments/Notifications,
   Refunds/Referrals) — all PASS (~2,000 tests).

### Staging (per AGENTS §108 — visual, not inferred; operator)

> **Deploy target — read before touching the shell (operator).** Serve the
> `stage*` release zip into the application **subdirectory**
> (`as-is-commerce-stage20/public/`) per the §88 rewrite and §87 paths — see
> `docs/DEPLOY_STAGE30_0.md` for the exact steps and zip naming.

1. Deploy `stage30.0` via the GitHub Actions release zip (operator); migration
   creates `carts` + `cart_items`.
2. Product page (signed in): only **Add to cart** + Quantity stepper; a
   live-auction product shows **View the auction** instead.
3. Header cart badge shows the summed basket quantity and links to `/cart`;
   Return-to-checkout appears while a basket order is payable.
4. Cart page: edit, remove, clear; per-line totals and the order preview are
   server-computed.
5. Place order → one multi-line checkout; cart emptied; second placement
   refused while the first is payable; allowed again after payment.
6. Expiry/cancel releases every held unit.
7. Existing Buy Now/credit/auction flows unaffected.

## 5. Results

| # | Check | Result |
|---|---|---|
| 1 | Migration `carts` + `cart_items` (MariaDB-compatible) | PASS (staging 2026-09-16 — `migrate --force` ran `2026_09_16_110000_create_cart_tables` in 859ms; both `Schema::hasTable` checks `true`) |
| 2 | Pint + PHPStan clean | PASS (local, 2026-09-16) |
| 3 | Focused cart + affected suites 207 tests | PASS (local, 2026-09-16) |
| 4 | Full suite chunked | PASS (local, 2026-09-16) |
| 5 | Deploy: zip extracted, `.env` restored, caches rebuilt, health OK | PASS (staging 2026-09-16 — extract per `docs/DEPLOY_STAGE30_0.md`; `/health` `{"status":"ok","database":"ok"}`; homepage/product render the new build, no exception markers; guest product page shows "Sign in to buy", `addToCart` markup in tree) |
| 6 | Staging: product page Add to cart + quantity stepper; auction routes away | PENDING (operator visual, signed in) |
| 7 | Staging: header badge + Return-to-checkout prompt | PENDING (operator visual, signed in) |
| 8 | Staging: cart edit/remove/clear + server preview | PENDING (operator visual, signed in) |
| 9 | Staging: place order → one checkout; cart emptied; second placement refused while owed | PENDING (operator visual, signed in) |
| 10 | Staging: expiry/cancel releases all units | PENDING (operator visual, signed in) |
| 11 | Staging: existing Buy Now/credit/auction flows unchanged | PENDING (operator visual) |
| 12 | Stage 30.1 fix: customer order page (owner, HTTP) no longer 500s | PENDING (staging — engine render check PASS, operator click-through pending) |
| 13 | Stage 30.1 fix: tracking + admin order pages load their relations | PENDING (staging re-verify after `stage30.1` deploy) |
| 14 | Stage 30.1 regression tests (bare-model relation loads + HTTP renders) | PASS (local, 2026-09-17 — RED before fix proved by temporarily removing the loader) |

## 6. Gate close

**Verdict: PENDING** — deployment to staging is complete (`stage30.0`, 2026-09-16);
gate awaits the operator's signed-in visual checks (Results rows #6–#11), and
now the `stage30.1` hotfix re-verify (rows #12–#13).
Blocker rule: any FAIL blocks the gate (fix in source, retest, redeploy,
re-verify).

## 7. Completion report

- **Changed:** the files in §2, plus the §8 hotfix for the `stage30.1` 500.
- **Why:** the shop is a real cart → order channel: intent is never held as
  stock; the whole basket is placed atomically as one order; one open
  catalogue checkout per customer (`docs/CART_SCOPE_AND_IMPACT.md`).
- **Tests:** exact suites in §4.1.
- **Verification:** local PASS; staging deployed and smoke-checked (migration,
  tables, health, product/home markup); signed-in visual checks pending
  operator.
- **Database:** new additive migration (two new tables); no applied migration
  edited. Applied on staging (`create_cart_tables`, 859ms).
- **Deployment:** `stage30.0` tag → GitHub Actions release zip (SHA-256/zip
  from the Release, tree verified after extract) → extracted into the served
  app subdir; `.env` restored; caches rebuilt; smoke checks green.
- **Remaining:** operator signed-in visual verification (§4.2 items 2–6 /
  Results rows #6–#11), plus sign-off on the `stage30.1` hotfix rows (#12–#13),
  then close the gate in this doc's Results table.

---

## 8. Incident & hotfix — `stage30.1` (2026-09-17)

### Symptom

After the `stage30.0` deploy, some pages served **`500 | server error`**. The
staging log (`storage/logs/laravel.log`) held two entries (2026-09-17 00:57 and
00:58, `userId 2`):

```text
staging.ERROR: Attempted to lazy load [product] on model [App\Models\OrderItem]
but lazy loading is disabled.
(View: .../resources/views/livewire/orders/order-detail.blade.php)
```

### Root cause

`AppServiceProvider` (`boot()`, line 111) runs `Model::shouldBeStrict(! $this->app->isProduction())`.
Staging runs `APP_ENV=staging`, so strict lazy-loading is **on**, and any blade
reading an unloaded relation throws `LazyLoadingViolationException`. The order
detail screen's **View product** link reads `$item->product`
(`order-detail.blade.php:238`) while the component never loads it
(`OrderDetail::render()` only `refresh()`s the route-bound order). Identical
hazard found in two more order screens (tracking reads `delivery`; admin order
detail reads `delivery`, `items`, `auction.product`) and in the auction room
(title/blade read `$auction->product`). Latent since earlier stages — surfaced
on stage30 because a reachable order flow finally visited the page.

### Fix (commit `1b72467`, tag `stage30.1`)

Load the relations each component's blade reads, at `mount` (covers action
methods) and after `refresh()` in `render`:

| Component | Loads |
|---|---|
| `app/Livewire/Orders/OrderDetail.php` | `items.product` |
| `app/Livewire/Delivery/OrderTracking.php` | `delivery`, `items` |
| `app/Livewire/Admin/Orders/OrderDetail.php` | `items`, `delivery`, `auction.product` |
| `app/Livewire/Auctions/AuctionRoom.php` | `product` |

No business rule, schema, or route change. Strict lazy-loading stays on (its
purpose is to catch exactly this at development time).

### Tests

- New HTTP-path renders in `OrderUiTest` (customer order, admin auction
  buy-out, tracking) — assert `200` + the product link.
- New bare-model relation tests in `OrderUiTest` — render/mount the component
  with a route-binding-style model and assert every relation its blade reads is
  loaded. **RED proved pre-fix** (loader temporarily removed → failing
  assertion), GREEN post-fix.
- Suites run local: `OrderUiTest` 31, `Delivery` 87, `Marketplace`+`Refunds`
  164, auction room UI/perf 63 — all PASS; Pint clean; PHPStan 0 errors.

### Note on local vs staging

With strict on, the local request path hydrates these relations before the
blade runs, so the HTTP tests pass even without the fix there; staging is where
route binding left the relations unloaded. The bare-model tests encode the
guarantee deterministically. Docs are written from measured behavior, not from
assumption.

### Deploy

`stage30.1` tag → GitHub Actions release zip → staged extraction into the app
subdirectory per `docs/DEPLOY_STAGE30_0.md` (backup the `stage30.0` tree +
`.env` first). No migration in this release.