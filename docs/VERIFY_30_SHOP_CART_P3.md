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

1. Deploy `stage30.2` via the GitHub Actions release zip (operator); migration created `carts` + `cart_items` on the earlier `stage30.0` release.
2. Product page (signed in): only **Add to cart** + Quantity stepper; a
   live-auction product shows **View the auction** instead.
3. Header cart badge sums the basket quantity **plus any payable Shop
   checkout**, links to `/cart`, and there is **no separate Return-to-checkout
   prompt** — one affordance for everything still owed.
4. Cart page: edit, remove, clear; per-line totals and the order preview are
   server-computed; an owed checkout renders as a read-only **Awaiting
   payment** card above the basket with a **Continue to payment** button, and
   the page reads "Your cart is empty" only when there is neither.
5. Place order → one multi-line checkout; the items stay visible as
   **Awaiting payment** so nothing seems to vanish; a second placement refused
   while the first is payable; allowed again after payment.
6. Expiry/cancel releases every held unit and clears the awaiting-payment card.
7. Existing Buy Now/credit/auction flows unrelated to the Shop cart unchanged.

## 5. Results

| # | Check | Result |
|---|---|---|
| 1 | Migration `carts` + `cart_items` (MariaDB-compatible) | PASS (staging 2026-09-16 — `migrate --force` ran `2026_09_16_110000_create_cart_tables` in 859ms; both `Schema::hasTable` checks `true`) |
| 2 | Pint + PHPStan clean | PASS (local, 2026-09-16) |
| 3 | Focused cart + affected suites 207 tests | PASS (local, 2026-09-16) |
| 4 | Full suite chunked | PASS (local, 2026-09-16) |
| 5 | Deploy: zip extracted, `.env` restored, caches rebuilt, health OK | PASS (staging 2026-09-16 — extract per `docs/DEPLOY_STAGE30_0.md`; `/health` `{"status":"ok","database":"ok"}`; homepage/product render the new build, no exception markers; guest product page shows "Sign in to buy", `addToCart` markup in tree) |
| 6 | Staging: product page Add to cart + quantity stepper; auction routes away | PENDING (operator visual, signed in) |
| 7 | Staging: header badge combines basket + owed checkout; no separate Return-to-checkout prompt | PENDING (operator visual, signed in — re-verify after `stage30.2`) |
| 8 | Staging: cart edit/remove/clear + server preview + **Awaiting payment** card | PENDING (operator visual, signed in — re-verify after `stage30.2`) |
| 9 | Staging: place order → one checkout; items stay visible as Awaiting payment; second placement refused while owed | PENDING (operator visual, signed in — re-verify after `stage30.2`) |
| 10 | Staging: expiry/cancel releases all units and clears the awaiting-payment card | PENDING (operator visual, signed in — re-verify after `stage30.2`) |
| 11 | Staging: existing Buy Now/credit/auction flows unchanged | PENDING (operator visual) |
| 12 | Stage 30.1 fix: customer order page (owner, HTTP) no longer 500s | PASS (staging 2026-09-17 — read-only engine render of order `AIC-O-20260917-GG24KCP1KV` under strict + bare route-binding model; renders 8,054 bytes incl. the **View product** link; no exception) |
| 13 | Stage 30.1 fix: tracking + admin order pages load their relations | Tracking PASS (staging engine render, no exception); admin screen PASS by code equivalence + local HTTP tests — staging operator click-through pending |
| 14 | Stage 30.1 regression tests (bare-model relation loads + HTTP renders) | PASS (local, 2026-09-17 — RED before fix proved by temporarily removing the loader) |
| 15 | Stage 30.1 deploy: zip extracted, `.env` restored, caches rebuilt, health OK, no fresh errors | PASS (staging 2026-09-17 — committed files byte-identical to tag `stage30.1` (SHA-256 match), `migrate --force` nothing to run, `/health` `{"status":"ok",...}`, homepage/products 200, fresh `laravel.log` has no app-facing errors) |

## 6. Gate close

**Verdict: PENDING** — deployment to staging is complete (`stage30.0`, 2026-09-16;
`stage30.1` hotfix, 2026-09-17; `stage30.2` unified-cart iteration, awaiting
deploy); the gate awaits the operator's signed-in visual checks (Results rows
#6–#11), re-verified against the `stage30.2` unified-cart behavior (rows
#7–#10), and the `stage30.1` hotfix rows (#12–#13).
Blocker rule: any FAIL blocks the gate (fix in source, retest, redeploy,
re-verify).

## 7. Completion report

- **Changed:** the files in §2, plus the §8 hotfix for the `stage30.1` 500,
  plus the §9 unified-cart iteration for `stage30.2`.
- **Why:** the shop is a real cart → order channel: intent is never held as
  stock; the whole basket is placed atomically as one order; one open
  catalogue checkout per customer (`docs/CART_SCOPE_AND_IMPACT.md`), and the
  customer-facing cart is the single view of everything still owed.
- **Tests:** exact suites in §4.1 (208 cart + affected tests, concurrency,
  plus the full suite rerun in chunks — ~1,800 tests across every directory).
- **Verification:** local PASS; staging deployed and smoke-checked for
  `stage30.0`/`stage30.1` (migration, tables, health, product/home markup,
  order-page fix); signed-in visual checks pending operator, re-verified
  against the `stage30.2` unified cart.
- **Database:** new additive migration (two new tables); no applied migration
  edited. Applied on staging (`create_cart_tables`, 859ms).
- **Deployment:** `stage30.0`/`stage30.1` tags → GitHub Actions release zip
  (SHA-256/zip from the Release, tree verified after extract) → extracted into
  the served app subdir; `.env` restored; caches rebuilt; smoke checks green.
  `stage30.2` deploy pending after operator review of this iteration.
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

### Staging verification (2026-09-17)

- Deployed committed files byte-identical to local HEAD (SHA-256 over the four
  edited components); caches rebuilt; `migrate --force` → nothing to run;
  `storage:link` OK; `/health` `{"status":"ok","database":"ok"}`; `/` and
  `/products` 200 (the "error"/"500" strings flagged by a naive scan are the
  Livewire snapshot's empty `"errors":[]` and numeric JSON — benign).
- **Customer order page** (the reported 500): read-only engine render of order
  `AIC-O-20260917-GG24KCP1KV` under the exact staging conditions (strict
  lazy-loading ON, `APP_DEBUG=false`, route-binding-style bare model) renders
  8,054 bytes including the **View product** link — no
  `LazyLoadingViolationException`. Tracking page renders (9,582 bytes).
- **Auction room:** verified by local suites + the `mount` load; no staging
  render possible today because every non-draft auction is `unsold`/`cancelled`
  (all fetch a 404 room). Re-check when a live/scheduled auction exists.
- The two `staging.ERROR` lines in the post-deploy log are the throwaway
  `stage301_auctions.php` probe's own SQL/enum errors (now deleted) — not
  application failures. Operator click-through of the order/tracking screens
  remains the final human confirmation.

---

## 9. Iteration — `stage30.2` unified Shop cart (UX/navigation only)

### Why

After `stage30.0` the header showed **two** affordances for one situation: a
cart icon (basket count) and a separate **Return to checkout** prompt whenever
a checkout was still owed. Because placement deletes the cart, a customer who
had just placed an order saw the badge fall to zero while they still owed — as
if their purchase had vanished. This iteration makes the cart the **one**
customer-facing view of an unfinished Shop purchase.

### Operator decision (recorded)

- The cart icon has one predictable destination: `/cart`.
- Cart = editable intent; awaiting-payment order = frozen, read-only, reserved
  obligation. Both render on `/cart`, clearly separated.
- The cart reads "empty" only when there is **neither** a basket **nor** an
  owed Shop order.
- Badge = total item quantity across the basket **plus** the payable Shop
  checkout.
- Auction settlement and auction Buy-Now stay on their own rails — never in
  the Shop cart.

### Changed (commit(s) after `stage30.1`)

| # | File | Change |
|---|---|---|
| 1 | `resources/views/partials/navigation.blade.php` | Header/mobile cart is the single affordance; badge = basket + payable **catalogue** order (`BuyNow`, `auction_id IS NULL`, awaiting payment, in-window); separate Return-to-checkout button/link removed |
| 2 | `app/Livewire/Catalog/CartPage.php` | `payableOrder()` — newest in-window catalogue checkout, items eager-loaded **with `order`** (the strict lazy-loading rule from §8; `OrderItem::unitPrice()/lineTotal()` read `order->currency`) |
| 3 | `resources/views/livewire/catalog/cart-page.blade.php` | **Awaiting payment** card (read-only snapshot lines, totals from frozen order, Continue to payment + Keep shopping); empty state only when neither basket nor owed order exists |
| 4 | `tests/Feature/Marketplace/NavigationCartTest.php` | Badge folds owed checkout (empty basket → order qty; basket + order summed); no separate prompt ever; expired checkout not folded |
| 5 | `tests/Feature/Catalog/CartPageTest.php` | Awaiting card with empty basket; owed order + editable basket coexist; auction-linked Buy-Now excluded; order survives basket clear; expiry clears the card; order lines read-only (no qty input) |

### Business invariants (unchanged, verified)

Cart is still intent only (nothing reserved by editing/adding); placement is
still the single atomic reserve; one open catalogue checkout per customer is
preserved and the awaiting card explains why a fresh placement is refused; the
badge is presentational — server-authoritative order/inventory/financial state
is untouched; money stays integer minor units via the `Money` component.

### Local verification (2026-09-17)

1. Focused `NavigationCartTest` (7) + `CartPageTest` (15+new) — PASS (24 tests, 59
   assertions).
2. P3 affected suites (208 tests) — PASS.
3. Store Wallet feature suite (47) — PASS.
4. Concurrency (`StoreWallet`, `CartPlacement`) — PASS (5).
5. Full suite rerun in chunks — PASS (~1,800 tests across every directory).
6. `./vendor/bin/pint` — clean. `./vendor/bin/phpstan analyse --memory-limit=512M` — 0 errors.

### Deploy & staging

`stage30.2` tag → GitHub Actions release zip → staged extraction into the app
subdirectory per `docs/DEPLOY_STAGE30_0.md` (backup the `stage30.1` tree +
`.env` first). No migration in this release. Operator re-runs the signed-in
visual checks (Results rows #7–#10) under the new behavior before the gate
closes.