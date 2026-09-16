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
| 1 | Migration `carts` + `cart_items` (MariaDB-compatible) | PENDING (staging) — tables exist locally; migration ran green in tests |
| 2 | Pint + PHPStan clean | PASS (local, 2026-09-16) |
| 3 | Focused cart + affected suites 207 tests | PASS (local, 2026-09-16) |
| 4 | Full suite chunked | PASS (local, 2026-09-16) |
| 5 | Staging: product page Add to cart + quantity stepper; auction routes away | PENDING (operator visual) |
| 6 | Staging: header badge + Return-to-checkout prompt | PENDING (operator visual) |
| 7 | Staging: cart edit/remove/clear + server preview | PENDING (operator visual) |
| 8 | Staging: place order → one checkout; cart emptied; second placement refused while owed | PENDING (operator visual) |
| 9 | Staging: expiry/cancel releases all units | PENDING (operator visual) |
| 10 | Staging: existing Buy Now/credit/auction flows unchanged | PENDING (operator visual) |

## 6. Gate close

**Verdict: PENDING** — awaiting staging deploy + operator verification.
Blocker rule: any FAIL blocks the gate (fix in source, retest, redeploy,
re-verify).

## 7. Completion report

- **Changed:** the files in §2.
- **Why:** the shop is a real cart → order channel: intent is never held as
  stock; the whole basket is placed atomically as one order; one open
  catalogue checkout per customer (`docs/CART_SCOPE_AND_IMPACT.md`).
- **Tests:** exact suites in §4.1.
- **Verification:** local PASS; staging pending operator.
- **Database:** new additive migration (two new tables); no applied migration
  edited.
- **Deployment:** `stage30.0` tag pushed; GitHub Actions release zip built;
  staging deploy pending operator (`docs/DEPLOY_STAGE30_0.md`).
- **Remaining:** staging deploy + visual verification below; then close the
  gate in this doc's Results table.