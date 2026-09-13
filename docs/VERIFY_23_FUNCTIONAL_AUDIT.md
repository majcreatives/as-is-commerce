# Stage 23 — Full staging functional audit (operator runbook)

This is the Stage 23 gate (`docs/ROADMAP.md`): exercise every money-moving flow
for real on Hostinger staging with **Paystack test mode**. It is a verification
stage — nothing in this runbook changes application logic or the schema.

**Who runs this:** a human operator over SSH + browser (steps from
`AGENTS.md` §86). Staging is for verification; if a manual change is needed to
unblock a flow, reproduce it in local source before it counts as product.

Payment edge cases (initialization/verify edge paths, webhook signatures,
duplicate events, payment conflicts, late payments) are Stage 24 and are NOT
covered here. This stage proves the happy paths and the primary business
lifecycles.

---

## Prep

At the bash prompt (app directory):

```bash
cd /home/u146516859/domains/darksalmon-swan-978886.hostingersite.com/public_html/as-is-commerce-stage20
/opt/alt/php84/usr/bin/php artisan db:seed --class="Database\Seeders\AuctionRulesetSeeder"
/opt/alt/php84/usr/bin/php artisan db:seed --class="Database\Seeders\CatalogSeeder"
```

Both are safe to re-run (ruleset: skipped if "Standard Auction" exists;
catalog: existing SKUs left untouched).

Verify:

- `AuctionRuleset "Standard Auction"` exists, status **Active**, `is_default`
  true.
- 7 products active at `/products` (Headphones GH₵650, stock 20; etc.).
- The `.env` fixes from Stage 22 still hold:
  `APP_ENV=staging`, `APP_DEBUG=false`.

**Known default-config fact:** with `closing_window_seconds = 0` (seeded
default), auctions go **Live → PendingSettlement / Unsold** directly — the
CLOSING status is never entered. That is valid, intentional behavior. To
observe CLOSING, an operator-created ruleset with `closing_window_seconds > 0`
is required (Flow 3b).

---

## Accounts used

- **super_admin** (already created in Stage 20.5B) — admin UI.
- **Bidder A** and **Bidder B** — two test customers via `/register`.
- Paystack test card **4084 0840 8408 4081**, any future expiry, any CVV,
  OTP `1234` if prompted by the Paystack test checkout.

---

## Flow 1 — Catalog Buy Now

1. Customer logs in and opens a product (e.g. Headphones GH₵650).
2. Buy Now → checkout → Paystack test page → pay with test card.
3. Verify after redirect/callback:

| Check | Expectation |
|---|---|
| Order status | `Paid` |
| `order_payments` | one row, status `succeeded`, provider reference recorded |
| Inventory | on-hand -1, reserved -1 (sale converted from reservation) |
| Fulfilment | `FulfilmentHandoff` opened a delivery record |
| Credits | untouched (Buy Now is GHS, no credit movement) |
| Buy Now financial evidence | exactly one sale; payment amount matches the frozen order total; provider transaction/reference retained on `order_payments`; order/payment states consistent |
| `cash_transactions` | Not applicable to Buy Now revenue — `cash_transactions` records user cash-wallet activity, not platform revenue. Buy Now financial evidence is the `orders` + `order_payments` records, with Paystack as the external payment/settlement record. Platform-level revenue/fee accounting is outside this ledger and would be a future accounting/reconciliation stage if required. |

---

## Flow 2 — Credit purchase

1. Same customer → `/credits` → smallest package → Paystack test page → pay.
2. Verify:

| Check | Expectation |
|---|---|
| `credit_purchase` | status `fulfilled`, package snapshot stored |
| `credit_transactions` | one credit entry (+credits) |
| `cash_transactions` | one cash entry (-GHS) |
| Wallet balance | equals ledger projection (server authoritative) |
| Idempotency | re-visiting callback does not double-issue |

---

## Flow 3 — Auction lifecycle (the core gate)

### 3a. Create, schedule, activate (admin)

1. Admin creates an auction: product (active), "Standard Auction" ruleset,
   settlement amount (e.g. GH₵100).
2. Draft → inspect snapshot (frozen) → **publish start** (or schedule).
3. Verify: status transitions Draft → Scheduled → Live; rules snapshot frozen;
   settlement amount independent of Buy Now price.

### 3b. Closing (only if a windowed ruleset exists)

With `closing_window_seconds > 0`, a Live auction enters **CLOSING** inside the
window before `ends_at`; with the seeded 0 it skips CLOSING (documented above).

### 3c. Bidding (Bidder A and Bidder B)

1. Each bidder is funded (Flow 2).
2. Bidder A bids X credits; Bidder B bids Y credits after the 3000ms cooldown.
3. Verify per bid: bid row `accepted`, `credit_transaction_id` set, wallet
   decreased, `bids_highest_bid_index` projection updated.
4. **Winner rule**: when the clock closes the auction, the winner is the
   bidder with the **highest valid credit bid** (earliest sequence on ties) —
   verified through `HighestBidResolver`, never from the projection.

### 3d. Winner, settlement handoff, loser compensation

After `auctions:tick` closes the auction:

| Check | Expectation |
|---|---|
| Winner | highest valid credit bid, `settlement_due_at` set |
| Settlement checkout | opened via `SettlementHandoff`, no direct checkout call |
| Loser Store Wallet | losing bidder with **purchased** credits gets `floor(credits × lot.acquisition_amount_minor / lot.original_amount)` |
| Free credits | produce no Store Wallet value |
| Winner | excluded from compensation |
| Idempotency key | `auction-loss:{auctionId}:{userId}` unique, single row |

---

## Flow 4 — Auction edge lifecycles

| Scenario | How | Expectation |
|---|---|---|
| No-bid close | publish, never bid, tick to close | `closure_reason` set, inventory reservation **released** |
| Cancel | admin cancel with reason | `CancelAuction` path, order/checkout closed correctly |
| Forfeit | let settlement lapse past `settlement_due_at` | `ForfeitAuction`, relist per policy |
| Relist | admin relist with new ruleset + amount | new Draft from the forfeited auction |
| Buy Now on live auction | customer clicks Buy Now in the auction room | auction `closure_reason=buy_now`, `buy_now_user_id` set, **winner columns null**; DB constraint prevents incompatible state |

---

## Flow 5 — Store Wallet

1. **Loss compensation** with a second losing bidder if desired (Flow 3d).
2. **Checkout application**: losing bidder uses Store Wallet on a catalog
   checkout → partial coverage, server-computed amount, order shows
   `store_wallet_applied_minor`.
3. **Release**: cancel/expire that order → applied Store Wallet released via
   `OrderLifecycle` with key `store-wallet:released:order:{orderId}`.
4. **Paid-order freeze**: confirm the `orders_frozen_after_payment` +
   `order_payments_frozen_request` triggers block edits on a paid order (DB
   enforces, not just app code).
5. **Payment conflict**: if another buyer takes the last unit while a payment
   completes, the losing order must remain **Paid + fulfilment blocked**, never
   reframed as failure.

---

## Verifiers

Run at the bash prompt after each major flow:

```bash
/opt/alt/php84/usr/bin/php artisan tinker --execute="
\$a = App\Models\Auction::latest('id')->first();
dump(['id' => \$a->id, 'status' => \$a->status->value, 'highest_bid_id' => \$a->highest_bid_id]);
dump((new App\Domain\Auction\Services\HighestBidResolver())->verify(\$a));
"
```

Ledger verify (choose the relevant wallet):

```bash
/opt/alt/php84/usr/bin/php artisan tinker --execute="
\$u = App\Models\User::where('phone','like','%2345%')->first();
dump((new App\Domain\StoreWallet\Services\StoreWalletLedgerService())->verify(\$u->storeWallet));
"
```

Admin Exception Centre: after all flows it must show no unexplained exceptions
(blocked-paid orders are expected and intentional).

---

## Results

Record per-flow PASS / FAIL with the verifying query/output.

| Flow | Result |
|---|---|
| 1 Catalog Buy Now | PASS — order `AIC-O-20260913-NAO4RZ6Z6N` `paid`; one `order_payments` row `succeeded` (provider ref `AIC-P-20260913-NMVVIYOTMYGH6EZW`, 340000 minor); inventory `reservation` → `sale` (on-hand −1, reserved −1); delivery `pending`; credits untouched; no `cash_transactions` expected (see Flow 1 note); Livewire `wire:click` dead-click incident resolved via config/route cache refresh |
| 2 Credit purchase | |
| 3a Create/schedule/activate | |
| 3b Closing | |
| 3c Bidding | |
| 3d Winner/settlement/compensation | |
| 4 No-bid / cancel / forfeit / relist / Buy Now | |
| 5 Store Wallet apply/release/freeze/conflict | |

Verdict: **PASS / FAIL / NEEDS REVIEW**

---

## Gate close

- **PASS all** → Stage 23 gate closes; Stage 24 (payment/webhook audit) opens.
- **Any FAIL or blocked item** → do not proceed. Record evidence, triage the
  discrepancy (never silently repair financial/auction data), and return.