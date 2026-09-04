# As-Is-Commerce

A credit-based auction marketplace for the Ghanaian market. Users buy virtual
bidding credits and commit them as bids on live auctions. **The highest valid
credit bid wins** when an auction closes — unless a customer buys the product
outright first, which ends the auction immediately.

All monetary values are in Ghana Cedis (GH₵) and are stored as integer minor
units (pesewas). Never as floating point.

> **Development status — Auction engine built; payments for products not.**
> This repository contains the application foundation (authentication, roles,
> application shell), the auction rules engine, the credit and cash ledgers,
> Paystack credit purchases, the product catalog with an auditable inventory
> ledger, and the auction engine: auctions, bids, the highest-bid winner rule,
> the clock and its extensions, and the Buy Now termination path.
>
> What is deliberately absent: **taking money for a product**. Neither the
> Buy Now checkout nor the winner's settlement checkout exists, so nothing in
> the interface can complete a purchase. `CompleteBuyNow` and
> `AuctionLifecycle::settle()` are written, tested and called by nothing —
> they are the point a confirmed payment will attach to, and wiring a button
> to either without a payment would be recording a sale that never happened.
> Real-time delivery (Redis, Reverb, WebSockets), orders and delivery are also
> later stages.

---

## Requirements

| Requirement | Version |
| --- | --- |
| PHP | 8.3 or newer |
| Composer | 2.x |
| MySQL | 8.0 or newer |
| Node.js | 20 or newer |
| npm | 10 or newer |

Redis is **not** required yet. Queues, cache and sessions run on the database
driver during the foundation stage. Redis, Horizon and WebSockets arrive with
the auction engine.

### Required PHP extensions

`openssl`, `pdo_mysql`, `mbstring`, `tokenizer`, `xml`, `ctype`, `json`,
`bcmath`, `fileinfo`, `curl`, `zip`, `intl`

---

## Installation

### 1. Create the databases

The application and the test suite use separate schemas. Both must exist
before migrating:

```sql
CREATE DATABASE as_is_commerce
  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE DATABASE as_is_commerce_testing
  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
```

### 2. Install and configure

```bash
composer install
npm install

cp .env.example .env          # Windows: copy .env.example .env
php artisan key:generate
```

Then open `.env` and set `DB_USERNAME` and `DB_PASSWORD` for your local MySQL
server. `.env` is never committed.

### 3. Migrate and seed

```bash
php artisan migrate
php artisan db:seed
```

Seeding creates only reference data — the `customer`, `admin` and `super_admin`
roles. No demo users, products, auctions, balances or transactions are ever
seeded.

### 4. Build assets

```bash
npm run build
```

### 5. Serve

```bash
php artisan serve
```

The application is then available at <http://localhost:8000>.

---

## Development

Run the PHP server and the Vite dev server side by side. In two terminals:

```bash
php artisan serve
npm run dev
```

`npm run dev` provides hot module replacement; without it, run `npm run build`
after changing anything under `resources/`.

### Creating an administrator

Admin accounts are never seeded, and no password is ever committed to this
repository. Create one explicitly:

```bash
php artisan app:create-admin --role=super_admin
```

The command prompts for the phone number and for the password with hidden
input. For non-interactive use, set `ADMIN_PHONE` and `ADMIN_PASSWORD` in your
local `.env` — and remove them again afterwards. Passwords shorter than 12
characters are refused.

---

## Testing

```bash
php artisan test
```

Tests run against MySQL using the `as_is_commerce_testing` schema, which is
migrated fresh for each run. They do **not** use SQLite: the ledger depends on
MySQL row-locking semantics (`SELECT ... FOR UPDATE`), CHECK constraints and
triggers that SQLite cannot reproduce, so testing on a different engine would
give false confidence in the area where correctness matters most.

The `Concurrency` suite is separate because it opens a second database
connection, which cannot see rows written by an uncommitted transaction on the
first. Those tests truncate between runs instead of wrapping in a transaction:

```bash
php artisan test --testsuite=Concurrency
```

### Code quality

```bash
./vendor/bin/pint --test     # code style check (drop --test to fix)
./vendor/bin/phpstan analyse --memory-limit=512M # static analysis, level 6
```

---

## The rules engine

**The highest valid credit bid wins.** When an auction closes normally, the
participant holding the highest valid credit bid takes the item -- not the last
bidder, not whoever bid most often, and not whoever held the lead longest. A
bidder who is overtaken and later bids higher still wins on that highest bid.

The one thing that overrides it: **a successful Buy Now purchase ends the
auction immediately**, and the standing highest bidder does not win.

> This layer was originally built for Last Bidder Standing and was corrected in
> a dedicated stage before the auction engine. The obsolete columns --
> `unique_leader`, `bid_cost_credits` and a default checkout price -- were
> dropped rather than reinterpreted, so nothing survives that would send a
> future developer back to the old model.

### Bids carry their own amounts

There is no fixed cost per bid. A bidder chooses how many credits to commit:

```
A bids 20 → B bids 50 → C bids 100 → A bids 150
Highest valid bid: A, with 150 credits. A wins.
```

Every accepted bid consumes the credits it commits, permanently. Losing
bidders do not get them back, and neither does the winner.

The rules constrain which amounts are acceptable:

| Rule | Meaning | Status |
| --- | --- | --- |
| `minimum_bid_credits` | Smallest bid that can ever be submitted | **Not decided** — null |
| `minimum_bid_increment_credits` | How far a bid must exceed the standing highest | **Not decided** — null |
| `allow_bid_increase` | Whether a bidder may raise their own bid | **Not decided** — null |
| `minimum_bid_interval_ms` | Anti-spam gap between a user's bids | 1000 |

Null means *no rule*, which is deliberately different from any particular
number. The business has not chosen these values, and seeding one would make
the choice by default. `AuctionRules::smallestValidBid()` returns null when
nothing is configured, so the engine cannot invent a floor of its own.

The upper bound is not a rule at all: a bid may not exceed the bidder's
spendable credits, which the wallet decides.

### Buy Now

`buy_now_enabled` says whether the product can be bought outright while its
auction runs. When that purchase succeeds the auction ends at once.

`buy_now_credit_discount_enabled` and
`buy_now_credit_discount_minor_per_credit` express the one place in the whole
system where credits relate to money:

> **One consumed bid credit gives GH₵1 off the Buy Now price.**

Stored as `100` — pesewas per credit — so the rate is an explicit integer,
versioned with everything else, rather than a conversion assumed in code.

```
Product Buy Now price     GH₵5,500
Credits consumed bidding      150
Discount                  GH₵  150
Payable                   GH₵5,350
```

The credits stay consumed. This reduces a separate purchase price; it does not
give them back. Only credits a user actually consumed bidding *on that
auction* count — not a wallet balance, not credits bought and never bid, not
credits spent elsewhere.

### Settlement is a per-auction amount

**What a normal winner pays is the auction's own `settlement_amount_minor`**,
in integer pesewas, chosen when the auction is created and frozen into its
snapshot.

It is deliberately *not* a ruleset field. Two auctions on the same product may
settle at GH₵50 and GH₵150, so the figure belongs to the auction rather than to
shared configuration — putting it in the ruleset would recreate the
`default_checkout_price_minor` column the correction stage removed, and would
force a new ruleset version for every auction with different economics.

It is also not derived from anything:

```
Product Buy Now price        GH₵5,500.00   what buying it outright costs
Auction settlement amount    GH₵  100.00   what a normal winner pays
Winning bid                         180    credits, consumed and gone
```

The winner owes the settlement amount plus applicable delivery and tax — not
GH₵180, and not the GH₵5,500 the product sells for. `toRules()` still takes no
price argument, and a test asserts no settlement key appears in the *rules*
half of a snapshot.

**Settlement amounts are meant to be low.** The platform intends bidders to
acquire products at very low effective cost and treats participation and scale
as the business model. Nothing in the code compares the settlement amount to
the Buy Now price, warns that it is low, or raises it — there is no
margin-protection mechanism, by design.

### Timing

Auction duration and late-bid extension are separate concerns.

`base_duration_seconds` is how long an auction runs. The extension fields
(`closing_window_seconds`, `extension_seconds`, `max_extensions`,
`max_extension_total_seconds`) are anti-sniping: a bid near the end can push
the clock back so others can respond.

Extension survived the correction because it is still useful, but it is now
**independent of who wins** — it changes how long bidding lasts, never the
rule by which the winner is chosen. It is also **off by default**: whether to
use it, and with what window, has not been decided.

### Configuration flows in one direction

```
Auction ruleset  (mutable, versioned configuration)
       │
       │  toRules()   ← taken once, when an auction is created
       ▼
AuctionRules     (immutable value object, snapshot version 2)
       │
       │  toArray() → JSON, stored on the auction row
       ▼
Auction engine   (reads the snapshot, never the ruleset)
```

### Why the snapshot exists

An auction must never hold a live reference to configuration an administrator
can edit. If it did, changing a rule on a Tuesday would silently rewrite how an
auction that ran on Monday is explained — and with money and competitive
outcomes involved, that is not recoverable.

So an auction takes a **complete copy** of its rules at creation, as an
immutable `AuctionRules` value object serialized into its own row. Editing,
archiving or even deleting the ruleset afterwards has no effect on it,
including the Buy Now discount rate. Tests assert exactly that.

Every snapshot records `winner_rule` explicitly, so the engine reads its
winner rule from the auction's own frozen configuration rather than inferring
it from whatever the code happens to do that week.

Snapshot version 2 is the corrected model. A version 1 snapshot is refused
rather than reinterpreted — its fields do not mean what version 2 would read
them as. None exist: no auction has ever been created.

### Ruleset lifecycle

```
Draft ──activate──► Active ──archive──► Archived
  │                                        ▲
  └────────────────archive─────────────────┘
```

- **Draft** — freely editable. New rulesets always start here; nothing takes
  effect until it is explicitly activated.
- **Active** — in service, and **no longer editable**. To change an active
  ruleset, draft a new version of it.
- **Archived** — retired, never deleted, so past configuration stays readable.

Rulesets are versioned per name: activating `Standard Auction v2` archives
`v1` automatically. At most one version of a name may be active, and exactly
one ruleset may be the global default — both enforced by unique indexes in the
database, not only in application code.

The default ruleset cannot be archived while it is the default. Designate
another first, so auction creation is never left with nothing to fall back on.

### Validation

Three layers, deliberately:

1. **Form** — per-field rules with messages an administrator can act on.
2. **Domain** — `RulesetInvariants` catches contradictions no single-field
   check can see: a closing window longer than the auction, an extension
   budget shorter than one extension, extensions configured with no closing
   window to trigger them.
3. **Database** — `CHECK` constraints and unique indexes, so a bad row cannot
   be written by any path, including a hand-run SQL statement.

---

## The ledger

Two separate accounting systems: **bidding credits** and **real money**. They
are not interchangeable, and there is deliberately no shared balance — a
single mixed balance would make it possible for a credit refund to settle as a
cash liability.

### The ledger is the truth

```
credit_transactions   ← authoritative, append-only
       │
       ├── credit_lots                  which credits, from where, expiring when
       │      └── credit_lot_consumptions   which lot each debit drew from
       │
       └── credit_wallets.balance       a cached projection, never the truth
```

`credit_wallets.balance` exists so a balance can be read without summing every
transaction. It is written **only** inside a ledger service, in the same
database transaction as the row that justifies it, and application code cannot
write it at all — the attempt throws.

### Append-only, enforced by the database

Ledger rows and consumption records are never updated or deleted. That is not
a convention; MySQL triggers refuse both, so it holds for a raw SQL session,
a console command or a bad migration:

```sql
UPDATE credit_transactions SET amount = 999 WHERE id = 1;
-- ERROR 1644: Financial history is append-only ...
```

A mistake is corrected by posting the opposite:

```
PURCHASE   +100      ← the mistake, left on the record
REVERSAL   -100      ← the correction
PURCHASE   +50       ← what should have happened
```

### Credit lots and consumption order

Credits arrive in **lots**, each carrying its source and its expiry. Promotional
credits may lapse; purchased credits do not by default. Tracking lots is what
lets the system answer *which* credits were spent — a question a flat balance
cannot answer, and which both expiry and refunds depend on.

Debits draw from lots in a fixed, deterministic order:

1. **Source** — promotional, then referral, then adjustment, then purchased.
   Promotional credits are the ones that can be lost by expiring, so spending
   them first is the outcome that favours the customer. Purchased credits are
   preserved longest.
2. **Soonest expiry first**, within a source.
3. **Lots with no expiry last**, since they cannot be lost by waiting.
4. **Oldest lot first**, to break any remaining tie.

### Concurrency

The locking order, observed everywhere:

```
1. the wallet row              SELECT ... FOR UPDATE
2. that wallet's credit lots   SELECT ... FOR UPDATE ORDER BY id
```

Always the wallet first, always lots by ascending id, so concurrent operations
queue rather than deadlock. Lots are *locked* in id order but *consumed* in
business order — the allocator reorders them once the locks are held.

Because the wallet row is locked before its balance is read, two simultaneous
debits cannot both see the same starting balance. The second waits, then reads
what the first left behind. A wallet holding 10 credits cannot pay out 10
twice, and there is a test that proves the lock actually blocks a second
connection rather than assuming it does.

### Idempotency

Payment providers retry webhooks. Customers double-tap buttons. Queued jobs run
twice. Any of those would otherwise grant credits a second time.

`IdempotencyGuard::execute($operation, $key, $userId, $work)` runs the work at
most once per key. The claim and the work share one transaction, so a failure
rolls back both and the key is free to retry — claiming separately would leave
a key marked as taken for an operation that never happened. Concurrency is
handled by a unique index on `(operation, key)`: the loser's insert blocks
until the winner commits, then fails and returns the winner's stored result.

### Reconciliation

`CreditLedgerReconciler` checks that the stored balance, the sum of ledger
movements, the remaining credit in lots, and every row's `balance_after` all
agree.

It **reports** discrepancies and never repairs them. A silent fix would
destroy the evidence needed to find the cause, and would let a real bug keep
producing wrong numbers while looking healthy. Repair is a human decision,
made with a compensating entry.

---

## Buying credits

Customers buy **credit packages**: a fixed number of bidding credits for a
fixed price in GHS, paid through Paystack.

### Three things that are never the same

```
Credit purchase   GHS buys a fixed number of credits.  A package price.
Bid               N credits, spent to bid.            Never money.
Buy Now price     GHS a product costs outright.       Never credits.
```

There is no arithmetic relationship between them. 500 credits costing GH 45
does not make one credit worth 9 pesewas, and a product's price has nothing to
do with either. Credits never convert back into money.

### The flow

```
Customer picks a package
        │  the browser sends a slug -- never a price, never a quantity
        ▼
Purchase created, carrying an immutable snapshot of what was bought
        │
        ▼
Paystack transaction opened server-side, for the snapshot amount
        │
        ▼
Customer pays  ──►  webhook (signed)      ──┐
                    callback (browser)    ──┤  both go through the same path
                                            ▼
                              Verify server-to-server with Paystack
                                            │
                              Check status, reference, currency, amount
                                            │
                              IdempotencyGuard, keyed on the purchase
                                            ▼
                    Cash ledger  ──  Credit ledger  ──  FULFILLED
```

### A browser callback is not proof of payment

A customer returning from Paystack proves only that a browser arrived — they
may have abandoned the payment or edited the URL. The callback takes the
reference, looks up a purchase the signed-in user owns, and runs the same
verified fulfilment the webhook uses. It is not a weaker way in, and
refreshing it cannot produce a second grant.

Mobile money settles asynchronously, so arriving before payment completes is
normal. That shows as pending, honestly, rather than as success or failure.

### Webhook security

The endpoint is public because Paystack cannot log in, so its signature is the
only thing separating the provider from anyone else who finds the URL.

- HMAC SHA512 over the **raw body**, compared with `hash_equals`.
- Verified before anything is stored, parsed for meaning, or acted on.
- Events stored under a unique `(provider, provider_event_id)` before
  processing, so a redelivery is caught by the database rather than by an
  application check that would race.
- Failures return 5xx so Paystack retries; the event is already stored, so a
  retry is safe.

### The snapshot

A purchase carries the package name, credit quantity, price and currency,
frozen when the transaction was opened. Fulfilment reads the snapshot and never
the package record, so repricing a package cannot change what an already-open
purchase costs or grants.

### What fulfilment produces

One verified payment produces exactly one of each:

- a `PURCHASE` credit ledger entry, referencing the purchase;
- a `PURCHASED` credit lot with **no expiry** — purchased credits do not
  expire;
- two cash entries: money in, then immediately out to buy the credits, netting
  to zero, because the customer now holds credits rather than a cash balance;
- a purchase marked `FULFILLED` — but only after the credits exist.

If any step fails, the whole transaction rolls back and the purchase stays
visibly outstanding rather than looking complete, so a retry can put it right.

### Refunds

A refund event is recorded but does **not** claw credits back. They may
already have been spent, and reversing a spend is a business decision rather
than something to infer from a provider event.

---

## The catalog

The platform owns its stock. There is no seller, vendor or merchant anywhere
in the product model, and a test asserts that no such column exists.

### Product, category, brand

A product belongs to exactly one category and optionally to a brand. Not every
product has a conventional brand -- a generic cable does not -- so forcing one
would mean inventing it.

Categories nest: Electronics contains Phones and Laptops. Neither categories
nor brands can be deleted -- a category holding products or child categories is
refused by the database, so nothing is ever orphaned. Archiving is how either
is retired.

### Condition

Products are `new`, `used` or `refurbished`, shown prominently. A marketplace
selling all three has to be unambiguous about which is which.

### Status

```
Draft ──► Active ──► Inactive ──► Archived
            ▲  │
            │  ▼
        OutOfStock
```

Only **Active** and **OutOfStock** appear publicly. Only **Active** is
purchasable -- being listed and being sellable are different questions, and
conflating them is how an archived product becomes buyable through some
alternate path. Out-of-stock products stay visible deliberately: "we have this,
just not right now" is more useful than a 404 on a bookmarked page.

Archived is terminal. The listing is the record of what was sold under it.

---

## Inventory

### Stock is derived from a ledger

Exactly as wallet balances are derived from the credit ledger:

```
inventory_transactions   ← authoritative, append-only
       │
       └── products.stock_on_hand      cached projections,
           products.stock_reserved     never the truth
```

`$product->stock_on_hand += 5` throws. Stock moves only through
`InventoryService`, which writes the movement and the projection in one
transaction. Movements are append-only under database triggers, so a stock
history cannot be quietly rewritten to match a discrepancy someone would
rather not explain.

Every movement records its type, a signed delta, the resulting on-hand and
reserved figures, a reason, the actor and the time.

### Available stock

```
available_stock = stock_on_hand - stock_reserved
```

This is the figure that matters. A future checkout must check *available*,
never on-hand alone -- otherwise two customers can buy the same last item.

### Reservations

A reservation does not remove stock from the building; it marks it as spoken
for. Only a sale takes it away, and a sale consumes the reservation that
preceded it so the two do not remove the same item twice.

`Reservation`, `Release` and `Sale` exist and are tested but are called by
nothing yet -- they belong to the Buy Now checkout. There is deliberately no
reservation expiry.

### Concurrency

`InventoryService` takes the product row with `SELECT ... FOR UPDATE` before
reading its stock. A second reservation blocks until the first commits and
then reads what the first left behind. One item, two competing reservations,
exactly one succeeds -- and there is a test proving the lock actually blocks a
second connection rather than assuming it does.

---

## The auction engine

An **auction** is one product offered under one frozen set of terms for a
period. A **bid** is a number of credits one user committed to it.

```
auctions              the instance: product, frozen snapshot, clock, outcome
bids                  append-only; each carries its own amount_credits
auction_transitions   append-only lifecycle history
```

### The winner rule, and how it is decided

The user holding the **highest valid credit bid** wins when an auction closes
normally. `CloseAuction` resolves it from the bid records at the moment of
closing — never from the cached projection, and never from anything a browser
sent.

Equal highest bids are broken by the **earliest bid at that amount**, ordered
by a per-auction `sequence` allocated under the auction row lock rather than by
a timestamp, so two bids in the same millisecond are still ordered. This does
not change the rule: highest still wins, the tie-break only makes equal values
name one person.

Every snapshot records `winner_rule` explicitly, so the engine reads it from
the auction rather than inferring it from the code of the day.

### The lifecycle

```
Draft → Scheduled → Live → Closing → PendingSettlement → Settled
```

`Closing` is optional: an auction with no closing window goes from Live
straight to PendingSettlement, because the window exists only to make late-bid
extension possible. Terminal and exception states are `Settled`, `Unsold`
(closed with no bids at all), `Cancelled`, `Forfeited` and `Relisted`.

Every move is checked against `AuctionStatus::allowedTransitions()`, applied by
`AuctionLifecycle` inside one transaction, and recorded in
`auction_transitions` with a reason. Nothing else may write `auctions.status`.

**Inventory follows the lifecycle**, which is how one item cannot be sold
twice without a second inventory system:

| Event | Stock movement |
| --- | --- |
| Publishing (schedule or start) | reserves one unit |
| Buy Now completes, or a winner settles | turns that reservation into a sale |
| Cancelled, unsold or forfeited | releases it |

Several auctions may run on one product while stock covers them: each publish
reserves a unit of its own and is refused when none is available.

### The clock

`starts_at` and `ends_at` are the only authority on when an auction runs. No
browser countdown, JavaScript timer, session or process lifetime affects it.
The interface displays a number the server computed.

`php artisan auctions:tick` starts, marks closing, closes and forfeits
auctions whose time has come. It is scheduled every minute and is idempotent:
each step re-reads its auction under a row lock and returns unchanged if
another run got there first. A missed run delays a closure; it never changes
the outcome, because the winner is resolved from bid records that do not move
while the sweep is late.

Late-bid extension is anti-sniping and is **independent of who wins** —
extending gives everyone else a chance to bid higher. Both ruleset limits
apply, and the auction can never run longer than
`AuctionRules::maximumPossibleDurationSeconds()`.

### Placing a bid

`PlaceBid` holds this order, and nothing may reorder it:

1. **The idempotency guard claims the key.** A retried request replays the
   first result instead of consuming the credits again.
2. **The auction row is locked.** Status, clock, standing highest bid and the
   next sequence number are all read from state nobody else can change.
3. **Validation runs against that locked state** — never against anything the
   browser sent.
4. **The credits are consumed**, which locks the wallet and then its lots in
   id order, continuing the ledger's own order.
5. **The bid is written**, referencing that consumption.
6. **The projection is rebuilt** from the bid records, and a late bid may
   extend the clock.

A bid is never recorded without the credit consumption that paid for it, and
credits are never consumed without the bid they paid for: both happen in one
transaction, and `bids.credit_transaction_id` is NOT NULL. A refusal at any
point rolls everything back, so a rejected bid leaves no row and moves no
credits.

### The lock order

Always this sequence, or concurrent operations will deadlock:

```
1. the auction row      SELECT ... FOR UPDATE
2. the product row      (inside InventoryService)
3. the wallet row       (inside CreditLedgerService)
4. that wallet's lots   ORDER BY id
```

This is what makes the races safe. Two Buy Nows, a bid against a Buy Now, and
two bids all serialize on step 1: the first through commits, and the second
blocks on that lock, then reads the row the first left behind and is refused.
There is a concurrency suite proving each of those against real MySQL locks.

### The highest-bid projection

`auctions.highest_bid_id`, `highest_bid_credits` and `bid_count` cache what the
bid query returns, so a listing page need not aggregate the bid table per row.
They are a cache and never a source of truth:

- only `HighestBidResolver` may write them; the model guard rejects anything
  else, exactly as the wallet and stock guards do,
- `rebuild()` recomputes them from the bid records alone,
- `verify()` **reports** a discrepancy and never repairs one — the admin screen
  shows it and says to report it. Overwriting stored state is a separate act
  from noticing it is wrong.

### The audit chain

```
Auction → Bid → CreditTransaction → CreditLotConsumption → CreditLot
```

This is what answers *exactly which credits did this user spend bidding on this
auction*, from historical records rather than from a mutable balance. The same
chain is what the Buy Now discount is computed from.

### What is frozen

An auction's `rules_snapshot`, `snapshot_version`, `settlement_amount_minor`,
`product_id` and `currency` cannot change once it leaves Draft. The model guard
refuses it and a database trigger refuses it again, so a console command or a
hand-run statement cannot rewrite the terms people are bidding under. There is
deliberately no admin form for editing a live auction.

---

## Prices, credits and bids

Three separate things, with no arithmetic relationship in any code that exists
today:

```
Credit package price   GHS buys a fixed number of credits.
Bid                    N credits, spent to bid. Never money.
Buy Now price          GHS a product costs outright. Never credits.
```

500 credits costing GH 45 does not make one credit worth 9 pesewas, and a
product at GH 5,500 has nothing to do with either. The `products` table has no
column referring to credits, wallets, bids or packages.

> **The one exception, and it is now implemented.** One credit consumed
> bidding on an auction gives GH₵1 off *that auction's* Buy Now price. Spend
> 150 credits, pay GH₵5,350 instead of GH₵5,500. `BuyNowPricer` computes it
> from the bid records, at the rate frozen in the auction's snapshot.
>
> Which credits qualify is narrow on purpose: credits this user consumed on
> accepted bids **on this auction**. A wallet balance does not count, nor
> credits bought and never bid, nor promotional credits never bid, nor credits
> spent on a different auction. The figure comes from bids rather than from a
> balance because a balance moves with everything else the user does.
>
> The credits stay consumed. This is a discount on a separate purchase, not a
> refund, a withdrawal, or a conversion.

> **A completed Buy Now ends a live auction.** `CompleteBuyNow` locks the
> auction row, sells the unit the auction was holding in reserve, and records
> the ending as `buy_now` — the buyer goes in `buy_now_user_id`, the winner
> columns stay null, and a CHECK constraint refuses any row claiming both. The
> standing highest bidder does not win, and their credits stay consumed.
>
> A click, a checkout screen or a payment attempt does **not** end an auction.
> Only a server-confirmed payment reaches that action, and the payment itself
> belongs to a later stage — so nothing calls it yet.

---

## Settings

Application configuration that is *not* auction-specific — site name,
currency, display timezone, support contacts — lives in the `settings` table
and is read through a typed, cached repository:

```php
settings()->getString('site_name');
settings()->getInt('some_number');
settings()->getBool('some_flag');
settings()->getMoney('some_amount');   // returns a Money, not a float
```

Each setting declares its own type, so no caller writes its own cast. The
whole table is read once per request and cached, so a page rendering a dozen
settings costs one query. Writes flush the cache immediately.

The cache is reached through Laravel's cache contract, so moving to Redis
later is a configuration change and touches no caller.

Settings marked `is_public` are safe to render publicly; everything else stays
server-side by default.

---

## Money

**Money is never a float.** Amounts are integer minor units — for Ghana,
pesewas — held in a `Money` value object and stored in `BIGINT` columns
suffixed `_minor`.

```
GH₵ 100.00   → 10000
GH₵ 5,500.00 → 550000
```

Parsing splits the decimal string and works on the halves as integers.
`(int) (0.29 * 100)` is `28`, not `29`, and that class of silent error has no
place in a system that handles real payments. `Money::fromDecimalString()`
never touches a float, and there is a test asserting exactly this.

Currency symbols are never stored alongside an amount. The currency is a
separate ISO 4217 code, and the symbol is a display setting.

Percentages — tax, commissions — are stored as **basis points**: `1000` is
10%, `0` is none. Integers again, so repeated calculation cannot drift.

---

## Time

Durations are integers in explicit units, never formatted strings:

- seconds — `base_duration_seconds`, `closing_window_seconds`,
  `extension_seconds`, `max_extension_total_seconds`
- milliseconds — `minimum_bid_interval_ms`
- minutes — `checkout_deadline_minutes`

Timestamps are persisted in **UTC** without exception (`APP_TIMEZONE=UTC`).
Ghana local time is applied at the presentation layer only, using the
`display_timezone` setting.

The auction engine will be entirely server-authoritative: browser clocks are
never trusted to decide whether an auction is live, whether a bid is accepted,
or who is leading.

---

## Architecture notes

The application is a **modular monolith**. Business logic lives under
`app/Domain/`, organised by domain rather than by technical layer, and is
invoked from thin controllers and Livewire components.

```
app/
├── Console/Commands/     Administrative commands
├── Domain/
│   ├── Auction/          Ruleset lifecycle, invariants, immutable rules
│   ├── Cash/             Real-money ledger
│   ├── Catalog/          Products, inventory ledger, stock movements
│   ├── Payments/         Gateway boundary, Paystack adapter, purchase flow
│   ├── Credit/           Credit ledger, lots, allocation, reconciliation
│   ├── Settings/         Typed, cached application settings
│   ├── Shared/Idempotency/  At-most-once execution of financial operations
│   ├── Shared/Ledger/    Guards shared by both ledgers
│   ├── Shared/Money/     Exact integer money
│   ├── Shared/Phone/     Phone normalization (E.164), swappable per country
│   └── User/             Registration, OTP contract, user exceptions
├── Enums/                UserStatus and future domain enums
├── Http/                 Controllers and middleware
├── Livewire/             Interactive components (auth, profile)
├── Models/
├── Providers/
└── Rules/                Reusable validation rules
```

### Principles this codebase follows

- **The server is authoritative.** Timers, balances, validity and outcomes are
  decided server-side. Client-supplied values are never trusted for
  authorization or for financial decisions.
- **No fabricated data.** Empty states are shown where data does not exist yet.
  The application never displays placeholder balances, auctions or activity.
- **Business rules are configurable, not hard-coded.** Bid costs, auction
  durations, closing windows and fees become configuration, not constants.
- **Money is integer minor units.** Never floats, never `DECIMAL` arithmetic
  in PHP.

### Timezone

The backend operates entirely in UTC (`APP_TIMEZONE=UTC`). Ghana local time is
applied in the presentation layer only. Business timestamps — and later, every
auction and ledger timestamp — are persisted in UTC without exception.

---

## Security

- `.env` and all `.env.*` files are git-ignored; only `.env.example` is
  committed, and it contains no real values.
- Never commit credentials, API keys or payment provider secrets.
- Never use production credentials in development.
- Login is rate-limited; authentication failures return a deliberately generic
  message so registered phone numbers cannot be enumerated.
- `/health` reports liveness and database reachability only. It exposes no
  hostnames, credentials, paths or exception detail.
