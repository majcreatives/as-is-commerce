# P3 — Shop Cart: Scope and Impact

**Status:** PLANNED — scope agreed with the operator; implementation begins on
operator approval, following the normal commit → tests → `stage*` tag → release
zip → staging → runbook gate sequence.

**Baseline:** `main` `691da47` (P2 gate closed). This document is the planning
artifact named by `docs/REFERENCE_MODEL.md` §6 for P3. Nothing here relaxes a
rule in `AGENTS.md` or in the roadmap.

---

## 1. Why

The storefront today sells one product, one unit, per Buy Now checkout. That is
an auction-shaped rail being used for the shop. The shop must behave like a
genuine e-commerce channel: a customer selects several products and several
quantities, reviews the whole basket, and pays once.

## 2. Operator decisions (locked)

1. **The cart is not the order.** Adding, editing, removing or emptying a cart
   creates **no** financial record and **no** inventory reservation.
2. **No reservation until placement.** Stock is reserved only when the customer
   places the cart order. The place step is **atomic and all-or-nothing**: it
   validates every line, and if any line cannot satisfy its requested quantity,
   the entire placement fails with no partial reservation and no partial order.
3. **Arbitrary quantities.** A cart line may hold any quantity up to the
   authoritative available Shop inventory (`on_hand - reserved`). Products are
   ordinary multi-unit e-commerce items; **no auction-style one-unit or
   scarcity restriction** is imposed on Shop products.
4. **One pending order per customer.** "One open checkout" means **one
   awaiting-payment order** (which may contain many lines and quantities), not
   one product or one unit. A customer cannot place a new cart order while an
   earlier one is awaiting payment.
5. **Shop vs Auction allocation stay separate.** `auction_eligible` only means
   *admin may move the product into the auction channel*. It does not mean the
   product is single-unit, is currently in an auction, or has auction-only
   inventory. A product that is **currently in an active auction** remains
   auction-first (bid / auction Buy Now) and is not addable to the multi-item
   cart until that auction resolves; the cart serves products not held by an
   active auction. (Recommended default — reversible at review.)
6. **Cart persists server-side per user**, additive `carts` / `cart_items`
   tables, one active cart per customer. (Recommended default — reversible at
   review.)
7. **The customer sees the entire basket before committing to payment** — the
   checkout page renders every line, the order totals, the Store Wallet portion
   and the payable, all server-computed.

## 3. Current behaviour (verified against source)

- Each Buy Now checkout writes **one** `Order` with **one** `OrderItem`
  (`quantity = 1`): `StartBuyNowCheckout::createOrder()`,
  `app/Domain/Orders/Actions/StartBuyNowCheckout.php:208`.
- Opening a Buy Now checkout reserves one unit (`OrderLifecycle::reserveUnit`),
  sets `payment_due_at = now + hold`, applies Store Wallet, all inside one
  transaction.
- `assertNoOpenCheckout()` (`StartBuyNowCheckout.php:170`) blocks a second open
  `BuyNow` checkout on the **same product** currently.
- `OrderLifecycle::reserveUnit / releaseReservation / sellUnit` all operate on
  the **single** `Order::item()` helper (`app/Models/Order.php:393`) — this is
  the core code assumption P3 generalises.
- The schema is already ready for it:
  - `order_items` carries `quantity`, `unit_price_minor`, `discount_minor`,
    `line_total_minor`, `metadata`, with
    `chk_order_items_line_total: line_total = unit_price × quantity - discount`.
  - `orders` carries order-level `subtotal/discount/delivery/tax/total +
    store_wallet_applied + payable` and keeps `chk_orders_total_consistent`,
    `chk_orders_payable_positive` (payable > 0 — an order is never fully
    covered by Store Wallet), `chk_orders_store_wallet_buy_now_only`.
  - `order_items` is append-only (trigger); order money is frozen once paid.
- `InventoryService` is already quantity-parameterised
  (`reserve/release/recordSale(Product, int $quantity)`) and locks the product
  row `FOR UPDATE` before reading stock (`InventoryService.php:222`).
- Downstream is order-level and cart-agnostic: payment initialization and
  verification charge `order.payable_minor`; `orders:expire-checkouts` works by
  order; fulfilment and delivery are one per order and carry items; Store
  Wallet applies/releases per order.
- The header badge (`resources/views/partials/navigation.blade.php:13-22,
  118-133`) currently sums items on the *latest awaiting-payment order* — under
  the new model it should show the *cart* count.

## 4. Target flow

```text
browse (product page: quantity + "Add to cart")
   → server-side cart (+1 line, quantity, no reserve)
   → cart page: review, change quantities, remove lines, see the total
   → "Place order":
        transaction {
            assert no other awaiting-payment catalogue order for this user
            load lines with products, deterministic product-id order
            for each line: lock its product row and reserve quantity      (all-or-nothing: any failure rolls back every reservation)
            compute order totals server-side (sum of quantity × price, + delivery + tax − Store Wallet, payable > 0)
            create the multi-line order + item snapshots
            commit Store Wallet portion
            hold reservation flag, write transition
            delete the cart (it has become the order)
        }
   → checkout page shows the full basket and the payable
   → Paystack initialized for order.payable_minor (unchanged)
   → verified payment → Paid → fulfilment handoff (unchanged, order-level)
```

## 5. Schema changes — one additive migration

New tables (no change to any existing table):

- **`carts`** — `id`, `user_id` FK → users (restrict), `created_at`,
  `updated_at`; `UNIQUE(user_id)` ⇒ one active cart per customer.
- **`cart_items`** — `id`, `cart_id` FK → carts (cascade), `product_id` FK →
  products (restrict), `quantity` unsigned int, `created_at`, `updated_at`;
  `UNIQUE(cart_id, product_id)`, `CHECK (quantity > 0)` (`chk_cart_items_quantity_positive`).

MariaDB-friendly (named CHECKs, `DROP CONSTRAINT` in `down()`, per Stage 20/29
discipline). Cart rows are mutable by design — the cart is *not* financial
history and is covered by no append-only trigger.

## 6. Code changes

### New files

| File | Purpose |
|---|---|
| `app/Models/Cart.php` | One cart per user; `items()` has-many. |
| `app/Models/CartItem.php` | A line: product, quantity; `product()` belongs-to. |
| `app/Domain/Catalog/Actions/AddToCart.php` | Upsert a line; server-validated qty cap at available stock. No financial/inventory effect. |
| `app/Domain/Catalog/Actions/UpdateCartLine.php` | Change quantity / remove line; same cap. |
| `app/Domain/Orders/Actions/PlaceCartOrder.php` | The atomic placement in §4. Writes the order + lines + reservation + Store Wallet in one transaction. |
| `app/Livewire/Catalog/CartPage.php` + blade | Review/edit basket. |
| `app/Domain/Orders/ValueObjects/CheckoutPricing` street: add multi-line pricing path | see below. |

### Modified files

| File | Change |
|---|---|
| `app/Domain/Orders/Services/CheckoutPricer.php` | Add `forCart()`: per-line `quantity × buyNowPrice` subtotals summed, discount = 0 (pure catalogue), delivery/tax from settings, Store Wallet applied once against total, `payable > 0`. `forBuyNow()` (auction) unchanged. |
| `app/Domain/Orders/Services/OrderLifecycle.php` | Generalise `reserveUnit`, `releaseReservation`, `sellUnit` from `item()` to iterate `items()`; reserve/release/sell each product by its item quantity, `holds_reservation` unchanged. |
| `app/Domain/Orders/Actions/StartBuyNowCheckout.php` | Keep the **auction-linked** path exactly as is. Catalogue path delegates to cart placement (a single-line, quantity-one cart order) so there is one checker-out. `assertNoOpenCheckout` becomes order-level for catalogue orders. |
| `app/Livewire/Catalog/ProductDetail.php` + blade | Quantity picker + "Add to cart" for products **not** in an active auction; the existing auction redirect is untouched for in-flight items. Browser sends only product + quantity. |
| `resources/views/partials/navigation.blade.php` | Badge/link → the user's cart count; "return to checkout" link retained while a pending order exists. |
| `app/Livewire/Checkout/CheckoutPage.php` + blade | Render **all** lines and quantities plus order totals / wallet / payable from the frozen order. |
| `database/factories/OrderFactory.php` | Add a multi-line state for cart tests. |
| routes | `cart.show` / `cart.update` / `checkout` wiring. |

No changes to: payment actions, Paystack verification/webhook, expiry sweep,
refund, referral, delivery/fulfilment, Store Wallet ledger, auction engine.

## 7. Locking and atomicity

- Cart placement runs in **one DB transaction**, so a failure on any line rolls
  back the reservations already taken — all-or-nothing by construction.
- Lines are processed in **ascending `product_id` order** so concurrent
  placements lock product rows in a stable order and cannot deadlock (§21's
  auction→product→wallet→lots order is preserved; carts have no auction, so
  product rows first, then wallet).
- Availability at placement is read under each product row lock
  (`InventoryService::reserve` re-reads `FOR UPDATE`), never from stale or
  page-level numbers.
- The awaiting-payment guard is checked inside the placement transaction.

## 8. Invariants preserved

- Green ledger / order invariants: `line_total = unit_price × quantity −
  discount`; `subtotal − discount + delivery + tax = total`;
  `payable = total − store_wallet_applied`, `payable > 0`.
- Store Wallet applies to **catalogue Buy Now only** (`auction_id IS NULL`),
  never to auction-linked orders or settlements.
- A verified Paid order stays Paid even if fulfilment is blocked (§37/§116).
- No reservation before placement; none after expiry/cancel (multi-line release
  summed per line).
- The browser never sends a price, total, discount or wallet figure.

## 9. Tests

Follow the existing Pest layout in `tests/Feature/Orders/**`,
`tests/Feature/StoreWallet/**`, `tests/Feature/Catalog/**` and the `Pest.php`
helpers.

- Cart CRUD: add/update/remove/clear; quantity cap at available; unauthorised
  users; tampered quantities rejected.
- Immutability of the cart: no reservation row, no transaction, no order
  created by cart edits.
- Placement happy path: N lines × M quantities → correct per-line snapshots,
  totals, reservation counts, wallet portion, transition, cart cleared.
- All-or-nothing: one line short of stock → whole placement fails, nothing
  reserved, no order, no wallet movement, cart intact.
- One-pending-order rule: second place blocked; auction-linked checkout still
  allowed alongside.
- Expiry/cancel/failed-payment on a multi-line order release **every**
  reservation; no double release.
- Concurrency: two customers racing the last available units of the same
  product; one wins, the other fails atomically.
- Store Wallet multi-line: `payable > 0` enforced; release on expiry returns
  the wallet value once.
- Payment: verify charges the multi-line `payable_minor`, marks Paid, handoff
  is order-level, fulfilment-blocked Paid preserved.
- Checkout page: renders the full basket.

## 10. Risks

- **Long carts vs stock drift.** Carts hold no stock; an item can sell out
  while sitting in a cart. Placement rejects the whole order with a clear,
  per-line message — the server is authoritative, and the customer edits the
  cart and retries. That is the agreed trade-off (roads: no hostage holding).
- **`OrderLifecycle::item()` generalisation** touches money-adjacent paths
  (expiry release, sell). Multi-line tests must cover cancel/expire/fail/sell
  on an order with several lines to prove the release sums can't double-count.
- **One-pending-order rule is stricter than today.** Today two different
  products can each have an open checkout. The new rule blocks the second
  order entirely. This is the operator's decision; the header/dashboard wording
  must tell the customer an earlier payment is still owed.

## 11. Explicit non-goals (unchanged rail)

- Cart never mixes auction settlement, credit purchase or auction-linked Buy
  Now with shop items.
- No order/schema rework beyond the additive cart tables.
- One delivery per order; manual fulfilment uneffected; no new delivery
  provider, no tax/shipping engines, no wishlist/persistence beyond the cart
  itself.

## 12. Gate / exit criteria for P3

1. Local: focused cart suites + full Auction/Orders/StoreWallet/Catalog suites
   green (`php artisan test`), Pint clean, PHPStan clean.
2. Migration applies cleanly on MariaDB (staging).
3. Deployed via `stage*` tag → GitHub Actions release zip → staging (following
   the P2 deploy pattern).
4. Runbook (`docs/VERIFY_30_SHOP_CART_P3.md`, to be created at implementation)
   proves on staging: multi-product/multi-quantity order, all-or-nothing
   failure, reservation + expiry release, Store Wallet on a cart, one-pending-
   order rule, full-basket checkout page, payment through Paystack test mode.
5. No auction/financial invariant regressed (verify regressions suites).

## 13. Sequencing

1. This scope document (approved by operator) — this commit.
2. Implementation in small commits (migration → models → pricer/lifecycle →
   placement action → UI → factories/tests).
3. Focused tests, then broad suites, Pint, PHPStan.
4. `stage30.0` tag → release → staging deploy → runbook → gate close.