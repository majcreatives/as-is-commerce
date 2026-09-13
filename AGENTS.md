# AGENTS.md

# As-Is-Commerce — Universal AI Agent Development Protocol

**Project:** As-Is-Commerce
**Product:** Gamified Credit-Based E-Commerce Auction Marketplace
**Market:** Ghana
**Currency:** GHS / GH₵
**Current Stage:** Stage 22
**Stable Branch:** `main`
**Repository:** `https://github.com/majcreatives/as-is-commerce.git`
**Stage 20 Baseline Commit:** `0bafe97` — `Stage 20 — Hostinger MariaDB compatibility baseline`

---

## 1. PURPOSE

This file is the authoritative development protocol for **every AI coding agent** working on As-Is-Commerce.

It applies regardless of agent, model, IDE, or platform, including:

* Claude
* Codex
* Gemini
* Cursor
* Windsurf
* GitHub Copilot
* OpenCode
* other compatible coding agents

There is intentionally **one authoritative instruction file**.

Do not create competing agent instruction files unless explicitly requested.

### Authority rule

The repository and current implementation are authoritative.

When documentation conflicts with the actual Stage 20 implementation:

1. inspect the implementation;
2. inspect tests;
3. inspect migrations/schema;
4. inspect recent Git history;
5. identify the discrepancy;
6. do not silently choose a financial or auction behavior;
7. ask for clarification when the discrepancy affects business correctness.

Historical documentation must not override the current Stage 20 architecture.

---

# 2. CURRENT PROJECT STATE

As-Is-Commerce is a Ghana-focused e-commerce marketplace combining:

* ordinary Buy Now commerce;
* credit-based auctions;
* Paystack payments;
* platform-owned inventory;
* credit wallets and credit lots;
* cash ledgers;
* Store Wallet;
* manual fulfilment;
* operational/admin workflows.

The application is a **server-authoritative Laravel modular monolith**.

Do not introduce microservices merely for architectural fashion.

Current environment:

* Laravel 13
* PHP 8.4+
* MariaDB 11.8.x on Hostinger staging
* MySQL/MariaDB-compatible development architecture
* database-backed cache
* database-backed queue
* database-backed sessions
* Paystack
* Redis may be used later for high-concurrency auction transport/workloads
* Livewire
* Tailwind
* Vite
* Pest/PHPUnit tooling as currently configured
* PHPStan
* Laravel Pint

Redis, Reverb, Horizon, persistent workers, courier integrations, and other infrastructure must not be introduced merely because they may be useful later.

Use the infrastructure currently supported by the target deployment.

---

# 2.5. REMAINING ROADMAP

The agreed plan for work after Stage 20 lives in:

```text
docs/ROADMAP.md
```

It is the single source of truth for what comes next — the phases and the
stage-by-stage table from 20.5B (Hostinger staging deployment) through 30
(post-launch monitoring) and 31+ (growth, UX, gamification). It does not relax
any rule in this file.

A stage is complete only when its gate and exit criteria in `docs/ROADMAP.md`
have been met and verified. Do not silently skip a gate.

**GitHub is in the loop.** Every change is committed to `main` and pushed to
`origin/main` before anything is deployed. Deployables are produced by the
GitHub Actions release workflow from a pushed tag — never built ad hoc on a
developer machine and never edited on the server. Staging is for verification
and must never become the source of application logic.

---

# 3. SOURCE-OF-TRUTH HIERARCHY

When determining what is true:

1. **Current local repository**
2. **Current Git state/history**
3. **GitHub `origin/main`**
4. **Hostinger staging**
5. **Verification/testing evidence**
6. Historical documentation

Staging is for deployment and verification.

Staging must not become the source of application logic.

If a manual change is made on staging for investigation, reproduce the intended change in local source code before considering it part of the product.

---

# 4. AI AGENT OPERATING PROTOCOL

Before changing code:

1. Inspect the repository.
2. Read this `AGENTS.md`.
3. Run:

   ```bash
   git status
   ```
4. Confirm the current branch.
5. Confirm the current commit when relevant.
6. Inspect the implementation involved.
7. Inspect related tests.
8. Inspect migrations/schema when persistence is involved.
9. Inspect relevant services/actions/domain objects.
10. Understand existing behavior before changing it.
11. Make a minimal implementation plan.
12. Make the smallest correct change.
13. Run appropriate tests.
14. Inspect the Git diff.
15. Run formatting/static analysis where appropriate.
16. Commit meaningful work.
17. Push only when instructed/appropriate.
18. Deploy to staging when required.
19. Verify staging behavior.
20. Report exactly what was changed and verified.

### Never claim work that was not performed.

Do not say:

* "tested" if tests were not run;
* "deployed" if deployment was not performed;
* "verified" if verification did not happen;
* "fixed" if the implementation was not actually changed;
* "working" based only on reasoning.

Distinguish clearly between:

* implemented;
* tested locally;
* deployed;
* manually verified;
* not yet verified.

---

# 5. GIT SAFETY

Never perform destructive Git operations without explicit approval.

Do not:

```bash
git reset --hard
git clean -fd
git push --force
git push --force-with-lease
```

Do not:

* rewrite history;
* delete branches containing useful work;
* mass-delete project files;
* revert unrelated work;
* overwrite user changes;
* replace existing project instructions without first preserving their useful content.

Never run `git init` in this project.

The repository already exists.

Before modifying files, inspect:

```bash
git status
git branch --show-current
git log -5 --oneline
```

### Commit discipline

Use meaningful commits.

Prefer:

```text
Stage 20 — ...
Fix ...
Add ...
Update ...
Refactor ...
Test ...
```

Do not create meaningless commits such as:

```text
changes
update
fix stuff
test
asdf
```

---

# 6. BRANCHING

`main` is the stable branch.

For substantial work, use an appropriate feature/fix/chore branch unless the workflow explicitly requires direct work on `main`.

Do not merge unrelated work into a feature task.

Do not create unnecessary branches for trivial documentation or configuration changes.

---

# 7. SECRETS

Never commit:

* `.env`
* API secrets
* Paystack secret keys
* database passwords
* SSH passwords
* private keys
* tokens
* webhook secrets
* credentials
* customer secrets
* authentication codes

Never place secrets in:

* source code;
* tests;
* documentation;
* screenshots;
* Git history;
* logs.

Use environment variables/configuration.

Never print secret values during diagnosis.

---

# 8. ARCHITECTURE

As-Is-Commerce is a:

> **Server-authoritative modular monolith.**

Keep domain boundaries clear without prematurely converting the system into microservices.

## Core principles

The server decides:

* auction state;
* bid validity;
* bid ordering;
* credit consumption;
* inventory ownership;
* payment validity;
* order payment state;
* fulfilment state;
* Store Wallet issuance;
* financial balances;
* settlement;
* refunds.

The browser is never authoritative.

JavaScript, Livewire, forms, polling, countdowns, or UI state must never determine financial or auction outcomes.

---

# 9. LOCKED BUSINESS MODEL

The following rules are business-critical.

Do not change them without explicit approval.

---

## 9.1 Credit purchases

A credit purchase is:

> GHS paid → fixed number of credits received.

The credit package defines:

* package name;
* credit quantity;
* package price;
* currency.

The package snapshot must be stored on the purchase.

Fulfilment must use the immutable purchase snapshot rather than relying on a mutable package record.

---

# 10. CREDIT / BID / BUY NOW SEPARATION

These are three different concepts.

### Credit purchase

GHS buys credits.

### Bid

A customer spends a chosen number of credits on an auction bid.

### Buy Now

Customer pays a GHS product price.

Never conflate these systems.

---

## 10.1 Explicit bid amounts

**There is no fixed one-credit-per-bid assumption.**

A bid carries an explicit number of credits chosen by the bidder.

The architecture must not silently introduce:

```text
1 credit = 1 bid
```

or a mandatory fixed bid cost.

If a future business change introduces a fixed bid cost, that is a deliberate business change requiring approval.

---

## 10.2 Highest valid credit bid

The auction winner is determined by:

> **Highest Valid Credit Bid**

The system must not silently change to:

* Last Bidder Standing;
* Lowest Unique Bid;
* highest cash bid;
* earliest bidder;
* random selection;
* another auction model.

---

## 10.3 Buy Now price

Buy Now is a GHS amount.

It is not:

* an auction price;
* a credit value;
* the current bid;
* the settlement amount.

Public UI should use:

> **Highest Bid (Credits)**

not:

> Auction Price

when referring to the credit bid.

---

# 11. CREDIT VALUATION

Credits have no universal cash value.

For qualifying purchased credits consumed on an auction, the valuation is lot-specific:

```text
floor(
    credits
    × lot.acquisition_amount_minor
    / lot.original_amount
)
```

All money is integer minor units.

No floating-point financial arithmetic.

Free, promotional, referral, and zero-value adjustment credits have zero cash-equivalent valuation unless the business rules explicitly state otherwise.

---

# 12. CREDIT LOTS

Credit balances are allocated through credit lots.

Lot allocation is deterministic.

Where applicable, use:

1. promotional credits first;
2. then earliest-expiring applicable lots;
3. then oldest applicable lots.

Do not invent a different allocation order.

Credit consumption must preserve enough information to reconstruct:

* which credits were consumed;
* which lots supplied them;
* their acquisition value;
* the resulting cash-equivalent valuation.

Financial reconciliation must be reproducible.

---

# 13. FINANCIAL RULES

These rules are non-negotiable.

1. Ledgers are authoritative.
2. Wallet balances are projections.
3. Financial transaction tables are append-only.
4. Never directly mutate a financial balance as a substitute for a ledger entry.
5. Credit and cash are separate systems.
6. Money uses integer minor units/pesewas.
7. Credits use BIGINT.
8. Financial operations occur transactionally.
9. External retries are protected by idempotency.
10. Corrections use compensating entries.

Never "repair" financial balances by simply assigning a new balance.

Never delete financial history to correct an error.

---

# 14. MONEY

All GHS amounts must use integer minor units.

Examples:

```text
GH₵10.00 = 1000 pesewas
GH₵1.50  = 150 pesewas
```

Use the project's `Money` abstraction.

Do not use:

```php
(float)
```

for financial calculations.

Do not use:

```php
(int) ($amount * 100)
```

as a financial conversion strategy.

Do not use DECIMAL arithmetic in application logic when integer minor units are appropriate.

Rates should use basis points or another explicitly integer representation.

---

# 15. AUCTION SETTLEMENT

The auction winner's settlement consists of:

1. the highest valid bid expressed in permanently consumed credits;
2. the separately configured GHS Auction Settlement Amount;
3. delivery and other applicable checkout charges.

The Auction Settlement Amount is:

* independent of Buy Now price;
* fixed for the auction;
* snapshotted/frozen;
* not dynamically derived from Buy Now price.

Do not invent margin warnings or minimum settlement requirements unless explicitly requested.

---

# 16. AUCTION RULE SNAPSHOTS

When an auction leaves Draft, its runtime rules become immutable.

At auction creation:

```php
$rules = $ruleset->toRules();

$auction->rules_snapshot = $rules->toArray();
```

Runtime behavior uses the snapshot.

Do not read mutable ruleset settings during a live auction.

If the serialized rule shape changes, bump the snapshot version.

Current corrected model is Snapshot Version 3.

Historical Version 1 last-bidder behavior must not be reintroduced.

---

# 17. AUCTION RULES

Relevant auction configuration may include:

* minimum bid credits;
* minimum bid increment;
* whether bid increases are allowed;
* duration;
* closing/anti-sniping behavior;
* extension rules;
* settlement amount;
* currency;
* other explicitly configured auction behavior.

Do not invent defaults for nullable settings.

In particular:

* `minimum_bid_credits`
* `minimum_bid_increment_credits`
* `allow_bid_increase`

must not be arbitrarily populated when the domain allows null.

Extensions are independent of who ultimately wins.

Extensions are off by default unless the auction configuration enables them.

---

# 18. AUCTION STATE MACHINE

The normal lifecycle is:

```text
DRAFT
→ SCHEDULED
→ LIVE
→ CLOSING
→ PENDING_SETTLEMENT
→ SETTLED
```

Alternative paths may include:

```text
FORFEITED
→ RELISTED
```

Cancellations may occur only through valid lifecycle transitions.

Do not manually assign terminal statuses from UI code.

---

# 19. AUCTION ENGINE

`CloseAuction` determines the winner from bid records.

The authoritative resolver is:

```text
HighestBidResolver::highestBid()
```

Do not determine the winner from a cached projection.

### Tie handling

If two bids have the same winning credit amount, use the earliest valid bid according to the auction's authoritative sequence.

Do not rely on timestamp precision for tie-breaking.

---

# 20. BID PLACEMENT

A valid bid must:

1. receive/check idempotency;
2. lock the auction;
3. validate auction state;
4. validate bidder eligibility;
5. validate bid amount;
6. consume credits;
7. write the bid;
8. rebuild/update the bid projection;
9. apply extension logic if appropriate;
10. commit atomically.

A bid must never exist without its successful credit deduction.

A rejected bid must not:

* create a successful bid record;
* consume credits;
* partially mutate financial state.

---

# 21. LOCKING ORDER

Use the established lock ordering.

For bid-related operations:

1. auction row `FOR UPDATE`;
2. product row through `InventoryService`;
3. wallet row through `CreditLedgerService`;
4. wallet credit lots by id.

Do not casually introduce a conflicting lock order.

Deadlock prevention is part of correctness.

---

# 22. AUCTION PROJECTIONS

Fields such as:

* `highest_bid_id`;
* `highest_bid_credits`;
* `bid_count`;

are projections/cache.

They are not the authoritative winner source.

`HighestBidResolver` is authoritative.

Rebuild logic recomputes projections.

Verification reports discrepancies.

Verification must not silently repair financial or auction data.

---

# 23. AUCTION CLOCK

Auction timing is server authoritative.

The authoritative values are:

* `starts_at`;
* `ends_at`.

The client countdown is presentation only.

The countdown must never:

* close an auction;
* declare a winner;
* extend an auction;
* determine payment deadlines.

Scheduled auction processing is performed by server-side commands such as:

```bash
php artisan auctions:tick
```

The command must be idempotent.

---

# 24. BUY NOW AUCTIONS

A successful Buy Now ends the auction.

It does not allow the standing bidder to become the winner.

The closure must record appropriate fields such as:

```text
closure_reason = buy_now
buy_now_user_id = purchaser
winner fields = null
```

Database constraints must prevent incompatible winner/Buy Now states.

---

# 25. AUCTION FREEZING

Once an auction leaves Draft, freeze the business-critical values including:

* rules snapshot;
* snapshot version;
* settlement amount;
* product;
* currency;
* other values explicitly defined as immutable by the current schema.

Do not allow ordinary product/ruleset edits to silently change a live auction.

---

# 26. INVENTORY

The platform owns the marketplace inventory.

Do not introduce seller/vendor/merchant ownership concepts unless explicitly approved.

Inventory truth is represented through inventory transactions.

`products.stock_on_hand` and `products.stock_reserved` are projections.

Never directly mutate them as a business operation.

Use:

```text
InventoryService
```

for inventory movements.

---

# 27. INVENTORY TRANSACTIONS

Inventory movements are append-only.

Corrections use opposing adjustment movements.

Every movement should record appropriate:

* movement type;
* signed delta;
* resulting on-hand;
* resulting reserved;
* reason;
* actor;
* timestamp.

Stock must never become negative.

Reserved stock must never exceed on-hand stock.

Available stock:

```text
on_hand - reserved
```

---

# 28. INVENTORY LOCKING

`InventoryService` must lock the product row before reading and changing stock.

Use:

```sql
SELECT ... FOR UPDATE
```

or the equivalent Laravel locking mechanism.

Never trust a previously-read stock value during a concurrent acquisition.

---

# 29. AUCTION RESERVATIONS

Publishing an auction may reserve one inventory unit.

Depending on the lifecycle:

* auction publication reserves;
* Buy Now or successful settlement converts reservation into sale;
* cancellation releases;
* no-bid closure releases;
* forfeiture releases.

Multiple concurrent auctions are only allowed where available inventory supports the reservations.

Do not invent reservation expiry behavior where the current domain does not provide it.

---

# 30. PRODUCT STATES

Product lifecycle generally follows:

```text
Draft
→ Active
→ Inactive / OutOfStock
→ Archived
```

Only appropriate public statuses are visible.

Only `Active` products are purchasable.

Categories and brands should be archived rather than deleted.

Public queries must use the project's `publiclyVisible` behavior.

---

# 31. MARKETPLACE AVAILABILITY

Catalog stock alone does not determine whether a product can be purchased.

A live auction may hold the unit.

Use the marketplace availability resolver, such as:

```text
ListingAvailability
ProductDiscoveryQuery::availabilityFor()
```

Do not blindly call:

```text
isInStock()
```

from product/listing pages when auction state affects availability.

Avoid per-card queries.

Use page-level/batched read queries.

---

# 32. ORDERS

An Order represents a GHS obligation.

It is distinct from:

* auction;
* bid;
* payment attempt;
* inventory;
* fulfilment;
* delivery.

Creating checkout does not mean payment succeeded.

Creating an order does not mean inventory was sold.

---

# 33. PAYMENT AUTHORITY

A payment becomes successful only through server-side Paystack verification.

The browser callback is not proof of payment.

The callback must reference an owned purchase/order and then execute the same verified fulfilment pathway as appropriate.

Never accept an amount supplied by the browser as authoritative.

The server calculates the expected amount.

---

# 34. PAYSTACK CREDIT PURCHASE FLOW

`FulfillCreditPurchase` is the authoritative credit-purchase fulfilment path.

It must:

1. verify with Paystack server-to-server;
2. compare against the immutable purchase snapshot;
3. verify success status;
4. verify reference;
5. verify currency;
6. verify amount;
7. apply idempotency protection;
8. lock the purchase;
9. ensure it has not already been fulfilled;
10. post the cash transaction;
11. post the credit transaction;
12. mark the purchase fulfilled;
13. commit atomically.

The purchase must not be marked fulfilled before the credits exist.

If anything fails, the financial transaction rolls back.

---

# 35. PAYSTACK WEBHOOK

Webhook processing is unauthenticated at the application level and therefore must be strongly verified.

Paystack webhook signature:

* use HMAC SHA-512;
* use the raw request body;
* use the Paystack secret;
* use `hash_equals`.

Verify the signature before:

* trusting payload contents;
* parsing for business purposes;
* storing;
* acting.

Protect against duplicate events using a unique combination such as:

```text
provider + provider_event_id
```

Return success promptly for successfully handled events.

Return an appropriate failure status when processing genuinely fails so Paystack can retry.

CSRF exemption should apply only to the webhook route.

---

# 36. PAYSTACK CONFIGURATION

Relevant environment configuration includes:

```text
PAYSTACK_SECRET_KEY
PAYSTACK_PUBLIC_KEY
PAYSTACK_BASE_URL=https://api.paystack.co
PAYSTACK_CURRENCY=GHS
PAYSTACK_TIMEOUT
```

Never log:

* secret keys;
* full authorization payloads;
* card information;
* sensitive payment credentials.

Never disable TLS verification.

---

# 37. PAYMENT CONFLICT POLICY

This is a locked business rule.

If:

1. Paystack payment succeeds;
2. but the single inventory unit was legitimately acquired by another transaction first;

then:

* preserve the order as **Paid**;
* preserve the successful payment;
* mark fulfilment as blocked;
* record an appropriate `fulfilment_blocked_reason`;
* place it into the administrative exception workflow;
* do not silently cancel;
* do not fabricate a failed payment;
* do not automatically invent a refund.

Recovery/refund is a separate business decision and later workflow.

The payment must not be represented as failed merely because fulfilment became impossible.

---

# 38. OTHER PAYMENT CONFLICTS

Relevant conflict cases include:

* another transaction acquired the inventory unit;
* checkout expired during payment;
* auction was forfeited before payment completed.

The system must always preserve the actual verified payment state.

Do not manipulate payment status merely to make inventory fulfilment easier.

---

# 39. ORDER PAYMENT

`Paid` must only be reachable through the verified payment pathway.

There is no:

```text
mark as paid
```

administrative shortcut.

Do not create one.

A payment attempt is verified against its immutable attempt/order data, not mutable browser input.

---

# 40. PAYMENT AND INVENTORY RACE

Inventory acquisition is determined by locking and server-side transactions.

It is never determined by:

* checkout creation time;
* browser click order;
* UI leader state;
* JavaScript;
* who appeared first on a screen.

One successful payment may result in a fulfilment-blocked Paid order if another legitimate transaction acquired the unit first.

That is intentional.

---

# 41. ORDER EXPIRY

For ordinary Buy Now checkout without an auction reservation, use the existing `payment_due_at`/expiry mechanism.

Scheduled expiry:

```bash
php artisan orders:expire-checkouts
```

must be idempotent.

Expired orders release their appropriate reservation through the proper lifecycle.

Do not directly mutate lifecycle states.

---

# 42. SETTLEMENT HANDOFF

Closing an auction should hand off to settlement through:

```text
SettlementHandoff
```

Do not bypass the lifecycle by directly calling an unrelated checkout-start operation.

If settlement handoff fails:

* the auction remains closed;
* it must not be reopened simply because checkout creation failed;
* the issue becomes an operational exception.

---

# 43. FORFEITURE / CANCELLATION

`ForfeitAuction` and `CancelAuction` must use the proper order/lifecycle pathways.

If there is an outstanding winner settlement checkout, close it through the correct lifecycle.

Do not directly mutate order status.

Paid settlement orders must not be silently altered.

Settled is terminal.

---

# 44. STORE WALLET

Store Wallet is a separate cash-value ledger.

It is **not a credit refund**.

When an auction ends because another customer acquires the product, qualifying losing bidders may receive Store Wallet value equal to the lot-based valuation of their consumed purchased credits.

The Store Wallet system currently covers:

> **catalogue checkout only**

Do not expand Store Wallet usage to auction Buy Now or other flows without explicit approval.

---

# 45. STORE WALLET EXCLUSIONS

Store Wallet must not be issued to:

* the auction winner;
* the Buy Now purchaser;
* a customer whose credits were free/promo/referral credits with zero valuation;
* customers where the auction ended by cancellation/forfeiture with no acquiring customer.

Store Wallet issuance must be idempotent.

Use a unique auction+bidder/business-event idempotency key.

---

# 46. STORE WALLET LEDGER

The current Store Wallet implementation uses:

```text
store_wallets
store_wallet_transactions
```

Transactions are append-only.

Use:

* unique idempotency keys;
* wallet row locking;
* projected balance;
* deterministic valuation.

Do not directly set the wallet balance.

---

# 47. STORE WALLET CHECKOUT

Store Wallet may be used as partial payment for eligible catalogue purchases.

It cannot necessarily cover an entire checkout where the current business rules prohibit full coverage.

The browser must not choose an arbitrary authoritative wallet deduction.

Application code computes the valid amount.

When an order is cancelled, expires, or payment fails, an applied Store Wallet amount is released through the proper lifecycle.

Existing idempotency keys include patterns such as:

```text
store-wallet:applied:order:{orderId}
store-wallet:released:order:{orderId}
```

Auction-linked orders do not use Store Wallet.

Do not revalue Store Wallet balances.

Do not claw back value based on later changes in credit pricing.

Do not convert:

```text
Store Wallet ↔ Credits
```

unless explicitly approved.

---

# 48. REFUNDS

A refund is a separate financial event.

Never edit the original payment to represent a refund.

`order_payments.amount_minor` remains the original amount.

Refund transactions represent separate amounts/statuses.

Do not:

* return credits automatically;
* restore stock automatically;
* reopen a closed order;
* rewrite payment history.

---

# 49. REFUND POLICY

The current refund architecture primarily supports fulfilment-blocked cases.

A healthy Paid order is not automatically refundable simply because an operator wants a convenience action.

Delivered orders are a different return/fulfilment concern.

Refunds are asynchronous with Paystack.

Provider terminal status determines whether a refund actually succeeded.

---

# 50. REFUND PROCESSING

Conceptually:

```text
RequestRefund
→ Pending
→ ProcessRefund
→ provider result
→ terminal state
```

Provider failures should be recorded.

Do not throw in ways that create uncontrolled provider retries unless that is explicitly the intended provider behavior.

Refund amount is calculated server-side:

```text
refundable =
    original payment
    - succeeded refunds
    - in-flight refunds
```

Lock the order before the relevant payment/refund state.

Reconciliation reports discrepancies.

It does not silently repair them.

---

# 51. REFERRALS

Referral credits are ordinary credits.

Use:

```text
CreditLedgerService::addCredits()
```

with the appropriate referral source.

Do not create a separate referral cash/credit wallet.

Qualification occurs when:

* the referred customer has a verified successful payment;
* the order reaches Paid;
* the payment is not fulfilment-blocked.

Paid is intentionally used rather than Fulfilled.

---

# 52. REFERRAL INTEGRITY

Rules include:

* one referrer per customer;
* one reward per referral;
* no self-referral;
* no reassignment;
* reward amount snapshotted at issue;
* issued rewards are immutable;
* no clawback.

`qualify()` and `reward()` are separate.

A qualified referral may remain retryable if reward issuance fails.

Reconciliation reports problems.

It does not silently grant rewards.

---

# 53. REFERRAL SETTINGS

Relevant settings may include:

```text
referrals_enabled
referral_reward_credits
referral_max_rewards_per_referrer
```

A zero maximum means no cap where that is the established setting definition.

Permissions include appropriate:

```text
referrals.view
referrals.manage
referrals.settings
```

Privacy wording should describe:

* joining;
* credits received.

Do not describe referrals as:

* commissions;
* earnings;
* withdrawable income.

---

# 54. NOTIFICATIONS

Notifications are informational.

They are never authoritative.

Business state must not depend on a notification being delivered.

Notifications should be dispatched after the relevant database transaction commits.

Notification handlers must not throw failures back into the commerce transaction.

Use unique event keys based on:

```text
business event + recipient
```

with database uniqueness.

---

# 55. NOTIFICATION WORDING

Notification wording must clearly distinguish:

* credits;
* GHS settlement;
* Store Wallet;
* payment;
* fulfilment;
* refund.

Do not use language implying that consumed credits have been refunded.

Do not promise a refund before provider confirmation.

For a blocked paid order, wording should communicate:

* payment was received/verified;
* fulfilment is blocked;
* investigation/recovery is required.

Do not promise the eventual outcome.

---

# 56. CUSTOMER MARKETPLACE

The marketplace UI is presentation over domain decisions.

It must not contain business logic that decides:

* winners;
* payment;
* inventory ownership;
* credit deductions;
* auction closure.

Use domain services/queries.

---

# 57. MARKETPLACE READ MODELS

Read-only marketplace queries belong in:

```text
app/Domain/Marketplace/Queries/
```

Examples include:

```text
ProductDiscoveryQuery
AuctionDiscoveryQuery
CustomerDashboardQuery
```

Queries should be optimized and bounded.

Avoid N+1 query patterns.

---

# 58. CUSTOMER DASHBOARD

Display available and committed/spent credits separately.

Never imply that:

```text
available + committed
```

is a single spendable balance.

Use clear terminology.

---

# 59. CUSTOMER AUCTION UI

Use:

```text
Highest Bid (Credits)
```

not:

```text
Auction Price
```

when displaying the current bid.

Use:

```text
x-money
x-credits
```

or the project's equivalent formatting components.

Money is GHS.

Credit counts are not currency.

---

# 60. BIDDING UI

The customer may:

1. review the auction;
2. choose the bid amount;
3. submit;
4. server validates;
5. server consumes credits;
6. server records the bid;
7. UI reflects the authoritative result.

Live countdowns and JavaScript may enhance presentation but cannot determine the result.

---

# 61. CUSTOMER PRIVACY

Bidder identities are private.

Do not expose:

* bidder names;
* phone numbers;
* wallet information;
* credit lots;
* credit transactions;
* payment information.

Public auction transport must use a deliberately limited payload.

---

# 62. SEO

Public pages must contain truthful SEO information.

Authenticated/private pages should not accidentally become indexable.

Do not build a CMS/blog/personalization system merely because it could improve SEO unless explicitly requested.

---

# 63. FULFILMENT

Current fulfilment is primarily manual.

Separate these concepts:

* payment;
* order;
* fulfilment;
* delivery;
* refund.

A delivery status change must never alter:

* payment;
* credit balance;
* inventory ownership;
* auction result.

---

# 64. DELIVERY LIFECYCLE

Use the established:

```text
DeliveryLifecycle
```

as the writer of delivery state.

Transitions must be:

* authorized;
* recorded;
* locked where appropriate;
* idempotent.

Do not create delivery records merely because a checkout page was opened.

---

# 65. FULFILMENT HANDOFF

Successful payment may create fulfilment/delivery through the proper:

```text
FulfilmentHandoff
```

path.

A fulfilment-blocked order does not receive ordinary fulfilment.

Paid-but-blocked orders enter the operational exception workflow.

---

# 66. DELIVERY SCOPE

Manual delivery is the current baseline.

Do not introduce:

* Bolt integration;
* Uber integration;
* Yango integration;
* driver assignment;
* GPS tracking;
* route optimization;
* automated shipping pricing;
* courier webhooks;
* automated tracking;
* reverse logistics;

unless explicitly approved as a later stage.

When a future delivery-provider integration is implemented, provider APIs should be the source of provider-calculated delivery pricing rather than invented local calculations.

Accra-first delivery constraints may apply as a business rule.

---

# 67. ADMIN / OPERATIONS

The admin application is a control surface over the domain.

It must not become a second implementation of business logic.

Admin actions should call the same domain services used by the customer/application workflows.

Examples:

```text
InventoryService
AuctionLifecycle
CreditLedgerService
RefundLifecycle
DeliveryLifecycle
RewardReferral
```

---

# 68. ADMIN ROLES

Current role model includes:

* Super Admin
* Finance Admin
* Auction Manager
* Product Manager
* Customer Support
* Fulfillment Manager
* Marketing Manager
* Fraud Analyst

Do not authorize business operations merely because a user has a convenient role name.

Use explicit permissions.

---

# 69. AUTHORIZATION

Authorization should use permissions rather than hard-coded role checks wherever possible.

Relevant permissions include examples such as:

```text
admin.dashboard.view
exceptions.view
customers.view
audit.view
refunds.view
refunds.request
refunds.process
refunds.retry
refunds.inspect
referrals.view
referrals.manage
referrals.settings
```

Super Admin behavior may be provided through the project's Gate configuration.

Every sensitive operation must be authorized server-side.

---

# 70. ADMIN READ-ONLY SCREENS

Read-only screens must remain read-only.

Examples include:

* OperationsDashboard
* ExceptionCentre
* GlobalSearch
* CustomerDetail
* CustomerIndex
* OrderPaymentIndex
* AuditLog

Do not add hidden financial mutation actions to read-only pages.

---

# 71. EXCEPTION CENTRE

The exception centre detects and reports problems.

It does not automatically repair financial state.

Do not add:

* automatic dismissal;
* automatic reconciliation;
* automatic refunds;
* automatic credit grants;
* automatic balance repair.

Human decisions should remain explicit where the business requires them.

---

# 72. AUDIT LOG

Use the project's activity logging conventions.

For Activitylog v5:

* model diffs belong in `attribute_changes`;
* manual business properties belong in `properties`;
* actor context should use the appropriate `CauserResolver`.

Audit logs are evidence.

Do not rewrite history to hide an error.

---

# 73. SEARCH

Operational search must be bounded.

Normalize phone numbers to E.164 where appropriate.

Do not create unbounded database scans for admin convenience.

---

# 74. PHONE / EMAIL / OTP

Phone is the primary customer identity/contact channel.

Email is secondary/optional where configured.

Phone numbers use E.164 normalization.

OTP behavior must be secure.

If OTP is unconfigured, the application should fail explicitly.

Do not create fake development OTPs such as:

```text
123456
```

unless a controlled test-only mechanism explicitly exists.

Never bypass authentication in tests to make a workflow easier.

---

# 75. SETTINGS

Business settings must use the project's typed settings access mechanism.

Use:

```text
settings()
```

and the established SettingsSeeder definitions.

Do not scatter magic configuration values throughout controllers/components.

Settings that affect financial/auction behavior must be explicit and auditable.

---

# 76. LIVE AUCTION TRANSPORT

Polling is authoritative.

Broadcasting is an enhancement.

The application must continue functioning if:

* Redis is unavailable;
* Reverb is unavailable;
* JavaScript is disabled;
* broadcast delivery fails.

Current broadcast connection may be:

```text
BROADCAST_CONNECTION=null
```

Do not make commerce correctness dependent on broadcasting.

---

# 77. BROADCAST EVENTS

Existing domain events may include:

```text
BidAccepted
AuctionClosed
AuctionSoldViaBuyNow
AuctionForfeited
```

Transport subscribers may listen to these events.

Do not alter domain event semantics merely to accommodate a transport mechanism.

Broadcast failures must never turn a successfully committed bid into an HTTP 500.

---

# 78. PUBLIC BROADCAST PAYLOAD

Public auction payload must be deliberately limited.

Allowed concepts include:

```text
auction_id
status
highest_bid_credits
bid_count
ends_at
sequence
extended_by_seconds
```

Never expose:

* bidder identity;
* wallet state;
* credit lots;
* credit transactions;
* order information;
* payment information;
* settlement amount;
* Buy Now purchaser;
* delivery information;
* refund information;
* referral information;
* notification content.

---

# 79. BROADCAST SEQUENCING

Clients should maintain a sequence high-water mark.

Rules:

* newer sequence replaces state;
* stale sequence is discarded;
* duplicate sequence is discarded;
* terminal state messages must still apply;
* reconnecting clients reread current state through HTTP.

Keep polling available.

---

# 80. REDIS / REVERB / HORIZON

Redis may be introduced later where required for high-concurrency workloads.

Do not assume Redis is available on Hostinger Web/Cloud.

Do not introduce Laravel Horizon simply because queues exist.

Do not install Laravel Reverb unless explicitly required.

Do not add redundant framework packages.

Current Hostinger deployment must work without persistent daemons where the hosting tier does not support them.

---

# 81. QUEUED WORK

Financial operations must not depend on asynchronous queues.

Financial operations are:

* synchronous;
* transactional;
* idempotent.

The current queue use is primarily communication/presentation, such as:

```text
SendNotificationEmail
```

Queued jobs must not become the only mechanism that makes money, credits, inventory, or payment state correct.

---

# 82. SCHEDULED COMMANDS

Relevant commands include:

```bash
php artisan auctions:tick
php artisan orders:expire-checkouts
```

and later/appropriate reconciliation commands such as:

```bash
php artisan refunds:reconcile
php artisan referrals:reconcile
```

Scheduled jobs must be:

* idempotent;
* bounded;
* safe to retry.

---

# 83. SCHEDULER LOCKS

Do not use bare:

```php
withoutOverlapping()
```

where the project's explicit scheduler lock mechanism is required.

Use:

```text
App\Support\ScheduleLocks
```

with explicit expiry.

Typical defaults:

```text
SWEEP_MINUTES = 5
RECONCILE_MINUTES = 30
```

The purpose is to prevent stale scheduler locks from becoming outages.

---

# 84. SWEEP LIMITS

Scheduled sweeps should be bounded.

Typical auction/order sweep limits may be around:

```text
200 records
```

per execution.

Refund reconciliation provider calls should remain bounded, for example:

```text
100
```

per reconciliation execution.

Do not create unbounded provider/network loops.

---

# 85. HOSTINGER PRODUCTION MODEL

Hostinger hPanel cron is the scheduler.

Do not assume a persistent worker or daemon exists.

The baseline architecture must not depend on:

* Supervisor;
* Horizon workers;
* persistent queue workers;
* persistent WebSocket server;

unless the deployment tier explicitly supports and requires them.

A scheduler cron may run:

```bash
php artisan schedule:run
```

according to the hosting configuration.

---

# 86. HOSTINGER STAGING

Current staging domain:

```text
darksalmon-swan-978886.hostingersite.com
```

Current application path:

```text
/home/u146516859/domains/darksalmon-swan-978886.hostingersite.com/public_html/as-is-commerce-stage20
```

Hostinger PHP 8.4 binary:

```text
/opt/alt/php84/usr/bin/php
```

Composer command:

```text
/opt/alt/php84/usr/bin/php /opt/alt/php83/usr/bin/composer.phar
```

SSH:

```text
ssh -p 65002 u146516859@89.116.53.20
```

Never store the SSH password in this file.

---

# 87. HOSTINGER DATABASE

Staging database configuration is private.

Known structural configuration:

```text
database: u146516859_asiscomm
user: u146516859_asisadmin
host: 127.0.0.1
port: 3306
```

Never put the database password into Git or documentation.

---

# 88. HOSTINGER DOMAIN ROUTING

The Hostinger domain configuration currently uses:

```apache
RewriteEngine On
RewriteRule ^(.*)$ as-is-commerce-stage20/public/$1 [L]
```

This is deployment infrastructure.

Do not copy Hostinger-specific routing into application source unless the architecture requires it.

---

# 89. STAGING DEPLOYMENT

A typical staging deployment includes:

1. verify Git state;
2. update code;
3. install dependencies;
4. verify `.env`;
5. ensure application key;
6. run migrations;
7. verify application status;
8. optimize caches appropriately;
9. ensure storage link;
10. seed only where explicitly intended;
11. create/administer required admin account;
12. run smoke checks;
13. verify UI and critical workflows.

Admin creation command:

```bash
/opt/alt/php84/usr/bin/php artisan app:create-admin --role=super_admin
```

Never expose credentials in this document.

---

# 90. PRODUCTION

Production deployment requires explicit approval.

Never infer permission to deploy to production merely because staging is verified.

Never make a production migration or financial change casually.

Production deployment must be treated as a separate controlled operation.

---

# 91. DATABASE COMPATIBILITY

The application must remain compatible with the target MariaDB/MySQL environments.

Stage 20 specifically establishes compatibility with:

```text
MariaDB 11.8.x
```

Do not assume MySQL-only syntax where MariaDB compatibility matters.

---

# 92. STAGE 20 MIGRATION DISCIPLINE

Applied migrations should not be casually rewritten.

Prefer:

```text
new migration
```

over rewriting history.

Stage 20 included compatibility corrections from:

```text
DROP CHECK
```

to:

```text
DROP CONSTRAINT
```

for relevant constraints such as:

```text
chk_rulesets_bid_cost
chk_rulesets_checkout_price
chk_rulesets_discount_rate
```

Do not reintroduce incompatible syntax.

Before changing migrations, inspect the current migration history and target database behavior.

---

# 93. DATABASE TESTING

The project uses MySQL/MariaDB-compatible testing because database behavior matters.

Do not switch important financial/concurrency tests to SQLite merely for convenience.

The test database must support behavior relied upon by the application, including:

```text
FOR UPDATE
```

where applicable.

Verify the test environment explicitly.

Do not assume `phpunit.xml` configuration is being applied correctly merely because it appears correct.

---

# 94. TEST ENVIRONMENT

Verify:

* test database;
* cache driver;
* queue driver;
* session driver;
* mail driver;
* external service fakes.

Tests must not accidentally use the development database.

A previous Stage 16.5 issue involved tests unexpectedly using:

```text
as_is_commerce
```

instead of:

```text
as_is_commerce_testing
```

Do not repeat this mistake.

---

# 95. PAYSTACK TESTING

Use HTTP fakes for Paystack.

The project may use helper functions such as:

```text
fakeHttp()
fakePaystackVerify()
```

Be aware that:

* the first HTTP fake may win;
* service resolution may capture an older factory/configuration.

When a payment test behaves unexpectedly, inspect service resolution and HTTP fake ordering rather than guessing.

---

# 96. TEST COVERAGE EXPECTATIONS

For financial, inventory, auction, and payment changes, test:

### Happy path

The normal successful workflow.

### Failure path

Expected validation/provider/business failures.

### Retry path

Repeated webhook/callback/command attempts.

### Concurrency

Two or more legitimate actors competing for the same resource.

### Security

Unauthorized and tampered requests.

### Idempotency

Duplicate requests/events do not duplicate financial outcomes.

---

# 97. TESTING FINANCIAL INVARIANTS

When touching financial code, test that:

* balances equal ledger projections;
* credits cannot be created without a ledger entry;
* credits cannot be consumed without a ledger entry;
* money is integer based;
* transactions roll back atomically;
* retries do not duplicate credits;
* retries do not duplicate Store Wallet;
* payment conflicts preserve Paid status;
* inventory cannot become negative;
* reserved cannot exceed on-hand.

---

# 98. STAGE 16.5 ECONOMIC BASELINE

The following Stage 16.5 work is part of the current economic foundation and must not be accidentally regressed.

Implemented:

* Store Wallet ledger;
* append-only Store Wallet transactions;
* unique Store Wallet idempotency;
* wallet row locking;
* lot-based credit valuation;
* Snapshot Version 3;
* Store Wallet catalogue checkout coverage;
* OrderLifecycle-based Store Wallet release;
* `minimum_bid_interval_ms` seeded at `3000ms`.

The lot-based loss valuation is:

```text
floor(
    credits × lot.acquisition_amount_minor / lot.original_amount
)
```

Valuation is reconstructed from:

```text
bid records
→ credit transactions
→ lot consumptions
```

Do not replace this with a simplistic wallet-average or current-package calculation.

---

# 99. STAGE 16.5 TEST FIXES

Known historical fixes included:

* queued-email sync configuration;
* Buy Now polling query-budget/cache/session issue;
* referral/reference assertion collision caused by date-based values matching a user ID;
* test database configuration correction.

These are reminders of known failure patterns.

Do not assume they remain fixed if changing adjacent infrastructure; rerun relevant tests.

---

# 100. SNAPSHOT VERSION HISTORY

Snapshot Version 3 is the corrected model.

Version 2 used a previous flat-rate approach and required migration.

Version 1 represented an incompatible last-bidder model.

Do not revive old snapshot behavior.

When changing snapshot serialization:

1. understand existing persisted versions;
2. preserve migration compatibility where required;
3. bump version;
4. add tests;
5. do not reinterpret historical snapshots silently.

---

# 101. APPLICATION CODE ORGANIZATION

Domain logic belongs in:

```text
app/Domain/<Context>/
```

Controllers and Livewire components should remain thin.

Multi-step business operations should use Actions/services where appropriate.

Use PHP enums rather than magic strings.

Interfaces should be bound through the established service provider architecture.

Do not move business rules into:

* Blade templates;
* JavaScript;
* Livewire rendering;
* controllers;
* form request presentation logic.

---

# 102. DOMAIN ACTIONS

Examples of domain-level operations include:

```text
PlaceBid
CloseAuction
CompleteBuyNow
ForfeitAuction
CancelAuction
FulfillOrderPayment
FulfillCreditPurchase
RequestRefund
ProcessRefund
```

Actions must preserve:

* transactionality;
* idempotency;
* locking;
* authorization;
* domain invariants.

---

# 103. IDEMPOTENCY

Use idempotency wherever retries can produce duplicate effects.

Relevant areas include:

* Paystack callbacks;
* Paystack webhooks;
* credit issuance;
* credit consumption;
* wallet transactions;
* Store Wallet issuance;
* Store Wallet application/release;
* order lifecycle;
* notification events;
* scheduled commands.

Idempotency keys should represent the business event, not arbitrary browser state.

---

# 104. LOCKING AND TRANSACTIONS

Financial and inventory operations must be transactional.

Acquire locks in the established order.

Never:

1. read a balance;
2. release the lock;
3. later assume it is still valid.

Never perform a financial write based on stale projections.

---

# 105. SECURITY

Never trust the browser.

Validate server-side:

* user ownership;
* permissions;
* auction status;
* bid amount;
* available credits;
* payment amount;
* Paystack reference;
* payment status;
* inventory;
* order state;
* Store Wallet amount.

Never trust hidden form fields.

Never trust JavaScript-calculated totals.

Never trust browser countdowns.

Never trust a payment callback without server verification.

---

# 106. STAFF BIDDING

Staff must not bid in customer auctions.

Do not introduce administrative bypasses that allow staff to participate as ordinary bidders.

Testing should use controlled test users.

---

# 107. UI / UX PRESERVATION

Do not redesign the marketplace unnecessarily while fixing backend logic.

If a task concerns backend correctness:

* preserve existing visual design;
* preserve existing flows;
* change only what is necessary.

When UI work is explicitly requested:

* inspect the existing design first;
* maintain consistency;
* verify on staging.

---

# 108. VISUAL VERIFICATION

When a task affects customer-facing or admin-facing UI:

1. deploy to staging;
2. open the relevant pages;
3. verify actual rendered behavior;
4. test representative states;
5. inspect desktop/mobile layouts where relevant;
6. check console/runtime errors where available;
7. verify that domain state matches the UI.

Do not claim visual verification from source inspection alone.

---

# 109. CURRENT MARKETPLACE OPERATING MODEL

The application is intended to be a customer marketplace over a completed commerce engine.

At the current baseline:

* auctions can operate;
* customers can participate;
* customers can be informed;
* settlement can occur;
* paid blocked orders can be surfaced to humans;
* fulfilment remains operational/manual.

Do not build large future systems simply because the product may eventually need them.

---

# 110. FEATURES NOT TO BUILD AHEAD OF APPROVAL

Unless explicitly requested as the next stage, do not introduce:

* physical returns/reverse logistics;
* automated courier/driver integration;
* GPS delivery tracking;
* shipping-price engines;
* tax engines;
* chargeback/dispute systems;
* automated compensation systems;
* AI fraud decisions;
* full gamification layer;
* loyalty systems;
* coupons;
* personalization;
* recommendation engines;
* CMS/blog infrastructure;
* unnecessary microservices;
* unnecessary real-time infrastructure.

Future ideas may be discussed separately without being silently implemented.

---

# 111. EXPERIMENTS AND MONETIZATION

As-Is-Commerce may eventually experiment with:

* auction voting;
* microtransactions;
* engagement mechanics;
* promotional mechanics;
* participation incentives.

However, monetization must never rely on:

* hidden charges;
* deceptive pricing;
* fabricated scarcity;
* misleading credit values;
* secretly changing financial rules;
* unauthorized automatic charges.

All financial behavior must remain explicit and auditable.

---

# 112. AUCTION VOTING

A future auction-voting feature may be considered as an engagement mechanism.

If implemented, it must be designed as a clearly disclosed product feature.

Do not silently convert votes into:

* paid bids;
* financial obligations;
* hidden charges;
* inventory guarantees.

Voting must not compromise auction correctness.

---

# 113. CUSTOMER CREDIT LANGUAGE

Use precise language.

Preferred:

```text
Credits
Credit Balance
Credit Package
Highest Bid (Credits)
Consumed Credits
Purchased Credits
```

Avoid:

```text
Credit Money
Auction Price
Cash Value of Every Credit
```

unless the exact context is explicitly explaining the lot-based valuation.

Credits are not inherently GHS.

---

# 114. STORE WALLET LANGUAGE

Store Wallet is not:

* cash withdrawal;
* a bank balance;
* a credit refund;
* a guaranteed compensation mechanism.

It is a platform cash-value ledger usable according to the current Store Wallet rules.

Do not tell customers that losing bids were "refunded" when Store Wallet value is issued.

---

# 115. NO AUTOMATIC FINANCIAL REPAIR

Reconciliation tools are diagnostic unless explicitly designed as controlled financial workflows.

A command that detects:

```text
balance mismatch
```

must not automatically overwrite the balance.

A command that detects:

```text
missing reward
```

must not automatically grant it unless the command is explicitly defined as the authoritative reward processor.

A command that detects:

```text
refund mismatch
```

must not invent a refund.

---

# 116. PAYMENT / REFUND TERMINOLOGY

Never say:

> "Refund successful"

until the provider confirms terminal success.

Never say:

> "Payment failed"

when Paystack actually confirmed payment and fulfilment merely failed.

Use:

> Paid + fulfilment blocked

where that is the true state.

---

# 117. DATABASE CONSTRAINTS

Prefer database constraints for hard invariants where practical.

Examples include:

* uniqueness;
* incompatible state combinations;
* valid numeric boundaries;
* idempotency keys.

Application validation and database constraints should complement one another.

Do not rely solely on UI validation for business integrity.

---

# 118. MIGRATION SAFETY

Before modifying a migration:

1. determine whether it has already been applied;
2. inspect production/staging relevance;
3. determine whether a new migration is safer;
4. preserve historical migration intent;
5. test against MariaDB.

Never rewrite an applied migration merely to make the file look cleaner.

---

# 119. PERFORMANCE

Optimize only where the evidence supports it.

Prioritize:

* bounded queries;
* appropriate indexes;
* avoiding N+1 queries;
* transaction scope;
* lock scope;
* concurrency safety;
* provider call limits.

Do not prematurely introduce distributed systems.

Correctness comes before theoretical throughput.

---

# 120. CONCURRENCY

Auction and inventory concurrency is a correctness problem.

When two customers attempt to acquire the same unit:

* lock the authoritative resource;
* determine the legitimate winner;
* record the result transactionally;
* preserve verified payment state for losing payment races;
* never rely on request arrival order alone.

Test concurrent scenarios explicitly.

---

# 121. DATABASE PROJECTIONS

Projection fields are useful for performance.

They are not automatically authoritative.

When a projection disagrees with ledger/domain history:

* investigate;
* report;
* determine root cause;
* repair only through an explicit, auditable process.

Never hide discrepancies by rewriting projections without understanding the cause.

---

# 122. CURRENT STAGE 20 BASELINE

Stage 20 baseline commit:

```text
0bafe97
Stage 20 — Hostinger MariaDB compatibility baseline
```

The current stable branch is:

```text
main
```

Repository:

```text
https://github.com/majcreatives/as-is-commerce.git
```

Stage 20's primary infrastructure focus includes:

* MariaDB compatibility;
* PHP 8.4 compatibility;
* deployment correctness;
* preserving established financial/auction invariants.

Do not treat Stage 20 as permission to redesign the business engine.

---

# 123. DEVELOPMENT COMMANDS

Before completion, normally run:

```bash
./vendor/bin/pint
./vendor/bin/phpstan analyse --memory-limit=512M
php artisan test
```

Use the project's actual configured commands if they differ.

For targeted work, run focused tests first, then the broader suite.

---

# 124. GIT DIFF REVIEW

Before committing:

```bash
git status
git diff
git diff --stat
```

Review:

* unexpected files;
* accidental secrets;
* debug statements;
* unrelated formatting;
* generated files;
* migration changes;
* environment files;
* test changes;
* deleted files.

Never blindly commit all modified files.

---

# 125. COMPLETION REPORT

A completion report should state:

### Changed

What was actually modified.

### Why

The business/technical reason.

### Tests

Exactly which tests were run.

### Verification

Whether local/staging/manual verification occurred.

### Database

Whether migrations/schema changes occurred.

### Deployment

Whether staging was deployed.

### Remaining

Any known limitation or unverified behavior.

Do not hide uncertainty.

---

# 126. WHEN TO STOP AND ASK

Stop and ask for clarification when ambiguity affects:

* money;
* credits;
* bidding;
* auction winners;
* inventory ownership;
* payment state;
* refunds;
* Store Wallet;
* authorization;
* security;
* database integrity;
* architecture;
* destructive operations.

Do not invent a business rule merely because implementation would be easier.

---

# 127. WHEN NOT TO ASK

Do not stop for trivial implementation choices where the existing architecture clearly determines the answer.

Examples:

* variable naming;
* normal formatting;
* obvious Laravel conventions;
* test naming;
* extracting a helper where behavior is unchanged.

Use existing project conventions.

---

# 128. GOLDEN RULES

If an agent remembers nothing else, remember these:

1. **The server decides.**
2. **The ledger is authoritative.**
3. **Every financial movement has an auditable ledger entry.**
4. **Never mutate balances directly.**
5. **Credit and cash are separate.**
6. **Money is integer minor units.**
7. **Bids use explicit credit amounts chosen by the bidder.**
8. **Highest valid credit bid wins.**
9. **Never reintroduce Last Bidder Standing.**
10. **Never call the highest credit bid an auction price.**
11. **Consumed bidding credits are permanently consumed.**
12. **Buy Now is GHS commerce, not a credit auction.**
13. **Auction settlement is separately configured and frozen.**
14. **Lot valuation is deterministic and reproducible.**
15. **A bid cannot exist without successful credit consumption.**
16. **Inventory changes go through InventoryService.**
17. **Payment is authoritative only after server-side Paystack verification.**
18. **A verified Paid order remains Paid even when fulfilment becomes impossible.**
19. **A payment conflict must not be silently converted into a failure.**
20. **Store Wallet is not a credit refund.**
21. **Store Wallet currently applies to catalogue checkout only.**
22. **Refunds are separate financial events.**
23. **Notifications never determine business state.**
24. **Polling remains authoritative for auctions.**
25. **Broadcasting is an enhancement, not a commerce dependency.**
26. **Do not trust the browser.**
27. **Do not invent missing business rules.**
28. **Do not silently repair financial discrepancies.**
29. **Do not claim work that was not performed.**
30. **Do not deploy production without explicit approval.**
31. **Do not commit secrets.**
32. **Do not perform destructive Git operations without approval.**
33. **Prefer minimal, reversible changes.**
34. **Preserve existing architecture unless a change is explicitly required.**
35. **When documentation conflicts with current implementation, investigate before changing behavior.**

---

# 129. FINAL AGENT CHECKLIST

Before declaring a task complete, confirm:

## Understanding

* [ ] Read this AGENTS.md.
* [ ] Inspected current implementation.
* [ ] Inspected relevant tests.
* [ ] Inspected migrations/schema where applicable.
* [ ] Checked current Git state.

## Business correctness

* [ ] No auction rule was silently changed.
* [ ] No financial rule was silently changed.
* [ ] No credit/cash concepts were conflated.
* [ ] No hidden charge was introduced.
* [ ] No inventory invariant was bypassed.
* [ ] No payment verification was bypassed.
* [ ] No refund was invented.
* [ ] No Store Wallet rule was expanded accidentally.

## Technical correctness

* [ ] Transactions used where required.
* [ ] Locks used where required.
* [ ] Idempotency preserved.
* [ ] MariaDB compatibility considered.
* [ ] No N+1 query introduced.
* [ ] No unnecessary infrastructure added.
* [ ] No secrets exposed.

## Testing

* [ ] Relevant focused tests pass.
* [ ] Broader test suite run where appropriate.
* [ ] Pint passes.
* [ ] PHPStan passes where appropriate.
* [ ] Concurrency tested where relevant.
* [ ] Retry/idempotency tested where relevant.

## Git

* [ ] Diff reviewed.
* [ ] No unrelated files included.
* [ ] No secrets included.
* [ ] Commit is meaningful.
* [ ] Push performed only when appropriate.

## Staging

* [ ] Deployment performed if required.
* [ ] Migration verified.
* [ ] Application boot verified.
* [ ] Relevant UI verified.
* [ ] Relevant business flow manually verified where required.

---

# 130. FINAL PRINCIPLE

As-Is-Commerce is a financial commerce system first and a gamified marketplace second.

The system must remain:

* server-authoritative;
* financially auditable;
* concurrency-safe;
* inventory-safe;
* payment-safe;
* idempotent;
* explicit;
* testable;
* understandable;
* deployable on the actual hosting environment.

**Do not sacrifice correctness for convenience.**

**Do not sacrifice financial integrity for UI behavior.**

**Do not invent business rules.**

**Do not silently change locked architecture.**

When uncertain about a business-critical decision:

> **Stop, inspect, and ask.**
