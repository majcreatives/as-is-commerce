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

> **Discrepancy fixed mid-audit (operator decision).** AGENTS.md §45 says Store
> Wallet must NOT be issued for "cancellation/forfeiture with no acquiring
> customer," yet Stage 16.5 (`784c66a`) had added `compensateLosers(..., null)`
> to both `CancelAuction` and `ForfeitAuction`, compensating every bidder — the
> forfeited winner included. Ruled **code is wrong**; actions now close the
> outstanding checkout and transition the auction only, issuing no Store
> Wallet (commit `5b8248e`), with regression tests. Verification below runs
> against that corrected build (`stage23.1`).

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
| 2 Credit purchase | PASS — purchase `id:4` ref `AIC-20260913-SGLAMNBCHJDOYHCNMT` status `fulfilled`, amt 1000 = Starter 100 credits, package snapshot stored; `credit_tx:1`, `cash_tx:2` (deposit+debit net zero), cash wallet 0; `projection 600 == ledger_sum 600`; lot source `purchased` acq 1000 remaining 100 (0 consumed) |
| 3a Create/schedule/activate | PASS — auction `1` (product "Northline N7 Smartphone 128GB", ruleset "Standard Auction") created Draft, then **live**; snapshot frozen: envelope `v1` / rules `v3`, `min_bid_interval_ms:3000`, `closing_window:0`, `buy_now_enabled:1`; `settlement_minor:10000` ≠ Buy Now `550000`; reservation posted (on-hand 4, reserved 1) |
| 3b Closing | skipped by default config — seeded `closing_window_seconds=0`, Live → PendingSettlement directly (documented). CLOSING not yet observed; needs an operator-created windowed ruleset |
| 3c Bidding | PASS — 16 accepted bids (users 2/3 alternating), each with `credit_transaction_id` set and lot consumption == bid amount; amounts rose 10→135; bid #16 (135, user 2) = `highest_bid_id`. 3000ms interval frozen in snapshot; discrete sub-3000ms rejection attempt not separately exercised this run |
| 3d Winner/settlement/compensation | PASS — `closure:highest_bid`; winner user 2 (Adom Nas) at 135 credits (`winning_bid_id:16` = `highest_bid_id`); `settlement_due_at` set; settlement order `AIC-O-20260913-DPJKPLULVX` source `auction_win` total 10000 now **Paid** (17:00:34); auction `settled`; winner credit wallet 600→147 (453 consumed, matches bids); loser user 3 wallet 500→120 (380 consumed); loser Store Wallet `auction_loss_compensation` **3420 minor = floor(380×4500/500)** key `auction-loss:1:3` single row; winner excluded (no `auction-loss:1:2`); free-credit contribution 0 (lot 4 source `purchased`, paid 380 / free 0); Store Wallet ledger sum == `balance_minor` MATCH (then applied, see Flow 5) |
| 4 No-bid / cancel / forfeit / relist / Buy Now | No-bid close PASS — auction `2` (Volta V3 Smartphone 64GB) started live, 0 bids, tick after `ends_at` → `unsold`, `closure_reason=no_bids`, no winner, no settlement order, no `auction-loss` Store Wallet; inventory reservation **released** (`release` tx, reserved 1→0, on-hand 7); transitions `draft → live → unsold`. **Buy Now PASS** — auction `3` (Kwaku Studio Over-Ear Headphones) live with 1 accepted bid (user 3, 50 credits); user 2 clicked Buy Now → `closure:buy_now`, `buy_now_user_id:2`, `winner_user_id`/`winning_bid_id` null; order `AIC-O-20260913-NTN51KIT90` `paid` 65000, payment success (provider ref `AIC-P-20260913-RJRE2D6WO1Z2QSOK`, tx `6555137095`, channel card); user 3 compensated `450 minor = floor(50×4500/500)` key `auction-loss:3:3`; buyer had no own bid credits so Buy Now discount 0 (correct); inventory reservation → sale (reserved 2→1; residual reserved unit belongs to unrelated checkout `AIC-O-20260913-SH0GBI1GKP`). **Forfeit/Cancel correction** — see discrepancy note above (commit `5b8248e`); awaiting `stage23.1` deploy before running forfeit+relist and admin-cancel on staging |
| 5 Store Wallet apply/release/freeze/conflict | **PASS (Stage 23 scope)** — compensation → apply → release → paid-freeze all verified. Applied: `store-wallet:applied:order:5` (−450, order `AIC-O-20260913-KGGLSZJAFX` 220000 → payable 219550 pending_payment). **Release PASS**: cancelled via checkout page ("Cancel this checkout", reached at `/checkout/AIC-O-20260913-KGGLSZJAFX` — order route binds by `order_number`, not id) → `store-wallet:released:order:5` (+450), balance back to 450; inventory reservation released. **Paid-order freeze PASS**: raw `UPDATE orders SET total_minor=1` on paid order id 2 → DB trigger `orders_frozen_after_payment` SQLSTATE 45000, row unchanged. Note: cancelled order keeps its `pending` payment attempt (attempts are append-only history; order no longer accepts payment). Payment-conflict is Stage 24 |

Verdict: **PASS / FAIL / NEEDS REVIEW**

---

## Gate close

- **PASS all** → Stage 23 gate closes; Stage 24 (payment/webhook audit) opens.
- **Any FAIL or blocked item** → do not proceed. Record evidence, triage the
  discrepancy (never silently repair financial/auction data), and return.