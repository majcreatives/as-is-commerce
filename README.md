# As-Is-Commerce

A credit-based auction marketplace for the Ghanaian market. Users buy virtual
bidding credits and commit them as bids on live auctions. **The highest valid
credit bid wins** when an auction closes — unless a customer buys the product
outright first, which ends the auction immediately.

All monetary values are in Ghana Cedis (GH₵) and are stored as integer minor
units (pesewas). Never as floating point.

> **Development status — auctions run themselves, and customers are told what happened.**
> This repository contains the application foundation (authentication, roles,
> application shell), the auction rules engine, the credit and cash ledgers,
> Paystack credit purchases, the product catalog with an auditable inventory
> ledger, the auction engine, and checkout: orders, payment attempts, Paystack
> payments for products, verified idempotent fulfilment, and the inventory and
> auction completion that follows.
>
> Both acquisition paths are complete end to end and run without anybody
> watching. Scheduled auctions open themselves, close on their own clock, hand
> the winner a settlement checkout at the moment they win, and forfeit it if
> the deadline lapses. A customer can buy a product outright — ending its
> auction if it had one — or win an auction and settle it, and in both cases
> the product changes hands only after a payment this platform has verified
> with Paystack itself.
>
> A payment the platform could not deliver against is recorded, queued for a
> person, and -- once somebody decides -- given back through Paystack, without
> ever returning a credit or a unit of stock. Everything else is packed,
> dispatched and delivered by hand, with every move recorded.
>
> All of it is now presented as a marketplace somebody can actually shop in:
> discover products, compare buying outright against bidding, and understand
> exactly what a credit does before spending one.
>
> Delivery is deliberately manual: no courier API, no driver app, no shipping
> pricing engine.
>
> What is deliberately absent: disputes and chargebacks, physical returns and
> reverse logistics, courier and driver integration, automated tracking, a tax
> engine, and real-time delivery (Redis, Reverb, WebSockets). Nothing refunds
> itself and nothing dispatches itself: both remain decisions a person makes.

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

## The marketplace

What a customer actually sees, and the rules that keep it honest.

```
/                     both paths, and what is live right now
/products             the shop, with search, filters and sorting
/products/{slug}      one product, with Buy Now or its auction
/auctions             every auction open to bid on
/auctions/{auction}   one auction: bid, or buy it outright
/how-it-works         credits, bidding, Buy Now, settlement, delivery
/dashboard            one customer's own account
```

### Availability is not the same question as stock

A live auction reserves the unit it is selling. So a product with one unit and
an auction running on it has **zero available stock** — and a page that asked
the catalog alone would tell a customer an item was unavailable while an
auction for it was open in the next tab.

`ListingAvailability` answers it properly: when an auction holds the unit, the
auction's own state decides whether the product can be had; otherwise the
catalog does. There is a test that creates the real reservation and checks the
page does not call the product unavailable.

### Nothing stale ever reaches a card

Only a currently relevant auction — live, closing or scheduled — is attached to
a listing. A settled, cancelled, forfeited or unsold auction is history: it
never appears among things to bid on, and its highest bid never appears on a
card. Both are tested for every one of those states.

### Credits and cedis are rendered by different components

`x-money` prints GH₵ from a `Money` object; `x-credits` prints a count. Neither
can be used for the other without it being obvious in the markup, which is the
point. On cards the label is **Highest Bid (Credits)**, so the number beside it
cannot be read as a price, and the phrase "auction price" appears nowhere on
the platform.

### The dashboard shows two credit figures and never adds them

| | |
| --- | --- |
| Available credits | in the wallet, ready to bid with |
| Credits committed | consumed on bids, and gone |

A combined total would be half spendable and half spent. The page shows both,
labelled, and says of the second: *not returned, whether you won or lost*.

### Bidding takes two deliberate actions

Entering an amount opens a confirmation that states what it costs, what the
current highest bid is, what the balance will be afterwards, and — in plain
words — that the credits go immediately and do not come back if you lose. Only
then is the bid placed.

Every rule is still the domain's. The confirmation is checked again against a
locked auction row when the bid is actually placed, so one left open while
somebody else bid cannot commit credits against state that has moved on: the
bid is refused, the confirmation closes, and the page re-reads the auction.

A bidder is told when they lead and when they have been outbid, with the amount
to beat and nothing else. No bidder is ever named to another bidder.

### The countdown decides nothing

It is a number the server worked out when the page rendered. An auction ends
when its own `ends_at` says so and the sweep notices, whether or not anybody is
watching. When a countdown reaches zero the page does not announce a result —
it has no way of knowing one — it refreshes and shows whatever the server says.

"Ending soonest" is ordered by the auction's own timestamp, never by anything a
browser computed.

### An auction owns the Buy Now path for its unit

A product with a live auction sends Buy Now to the auction page. That price
carries the bidder's credit discount and completing it ends the auction;
opening a second, discount-free checkout on the same unit would be wrong in
both directions.

### Read models

`app/Domain/Marketplace/Queries/` holds the three query services the pages use.
They are read-only: no writes, no decisions, no caching, no second source of
truth. Search terms are trimmed and length-capped before reaching the database,
and availability for a whole page is resolved in one query rather than one per
card.

### What is deliberately not here

No blog or CMS, no coupons, no referrals, no loyalty scheme, no reviews or
ratings, no recommendation engine, no personalisation or tracking, no analytics
platform, and no gamification beyond the auction mechanism itself. Related
products are the same category, topped up from the same brand — deterministic,
explicable, and identical for every customer.

No real-time infrastructure either: the auction page works on ordinary request
and response, because the database is authoritative and a socket would not make
it more so.

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

## Running auctions

Everything below happens without a browser open. Two scheduled commands do the
work, and both are safe to run repeatedly.

```bash
php artisan auctions:tick              # start, mark closing, close, forfeit
php artisan orders:expire-checkouts    # release stock held by abandoned checkouts
```

Both run every minute under `withoutOverlapping()`. Missing a run delays an
outcome; it never changes one, because every decision is re-derived from
persisted UTC timestamps and the bid records when the sweep finally runs.

### The operational path

| Step | What happens |
| --- | --- |
| Scheduled → Live | the sweep opens auctions whose start time arrived; the unit was already reserved at scheduling |
| Live → Closing | informational, once inside the closing window; bidding is unchanged |
| Closing → PendingSettlement | the highest valid credit bid wins, and **the winner's settlement checkout is opened there and then** |
| PendingSettlement → Settled | a verified payment sells the unit the auction was holding |
| PendingSettlement → Forfeited | the deadline lapsed; the checkout is cancelled and the unit goes back on sale |
| Live/Closing → Unsold | nobody bid; the unit is released |

### The winner does not have to ask

Closing opens the settlement checkout, through `SettlementHandoff` — an
interface in the auction domain implemented by the orders domain, because the
checkout layer already depends on auctions and a direct call back would tie
them together in both directions.

The deadline starts running at closure. An obligation that only existed once
the winner happened to visit the page would be one they were already late for.

If the handoff fails, **the closure still stands**. The highest bid won and the
credits are consumed; an administrator seeing a `PendingSettlement` auction
with no order can act on it, which is far better than an auction that failed to
close because its paperwork did.

### Every way an auction stops closes the winner's checkout with it

Forfeiting and cancelling both go through actions — `ForfeitAuction`,
`CancelAuction` — that close the outstanding settlement order in the same
transaction as the auction transition. Doing only the transition would release
the unit while leaving a payable order pointing at it, and the winner could pay
for something the platform had just put back on sale.

A settlement that was already **paid** is never touched: that is a completed
transaction, and `Settled` is terminal.

### What a losing bidder is told

Plainly: that they did not win, what the winning bid was, and that their
credits remain consumed. There is deliberately no refund control anywhere on
that page, because there is no refund — bid credits are spent when the bid is
accepted.

An auction ended by Buy Now shows **Sold via Buy Now** and states that there is
no auction winner. The leading bidder is told what happened to their credits
rather than left to work it out.

### Payment conflicts

A payment that succeeds against something that can no longer be delivered is
**recorded, never discarded**. The attempt is marked successful, the order
carries a `fulfilment_blocked_reason`, and it appears in the admin attention
queue. No silent cancellation and no automatic refund: what is owed is a
decision for a person, who then acts on it through the refund workflow.
Nothing here decides on its own.

This covers three cases:

- another legitimate transaction took the unit first,
- the checkout expired while the customer was paying,
- the auction was forfeited before the settlement payment landed.

The second and third used to raise an exception, which returned a 5xx and had
Paystack retrying the same delivery forever against an order that could never
accept it. Recording the fact and acknowledging the webhook is both truthful
and terminal.

### Concurrency guarantees

One physical unit is acquired once, by one person, whichever paths competed for
it. Decided by database locks in a fixed order — order, auction, product,
wallet — and never by checkout creation time, page load time, button click
time, browser timestamp, or who was leading.

The concurrency suites prove each race against real MySQL locks on a second
connection: settlement against settlement, settlement against Buy Now, Buy Now
against Buy Now, payment against closure, two workers fulfilling one paid
order, duplicate sweeps, and one credit balance funding simultaneous bids.

---

## Checkout, orders and payment

An **order** is one customer's obligation to pay for one thing, in GHS. A
**payment attempt** is one try at settling it. Neither is an auction and
neither is an inventory movement — each of those owns its own table and its
own lifecycle.

```
orders             the obligation: frozen amounts, source, status
order_items        what was bought, with the name, SKU and price snapshotted
order_payments     what was asked of the provider, and what came back
order_transitions  append-only lifecycle history
```

### Two paths, two different amounts

| | Buy Now | Auction win |
| --- | --- | --- |
| Subtotal | the product's own Buy Now price | the auction's own settlement amount |
| Discount | GH₵1 per credit consumed bidding on that auction | **none** |
| Ends the auction | yes, once paid | no — it already closed |

A winner's consumed credits bought them the win; they do not also reduce what
winning costs. A CHECK constraint refuses a settlement order carrying a
discount at all.

```
Product Buy Now price      GH₵5,500.00   what buying it outright costs
Auction settlement amount  GH₵  100.00   what a normal winner pays
Winning bid                       180    credits, consumed and gone
```

None of those is derived from another. `discount_credits` on an order is a
count kept as evidence for the cedis in `discount_minor`; it is not money, and
nothing converts one into the other.

### The components stay separate

```
subtotal - discount + delivery + tax = total
```

Asserted in `CheckoutPricing` and again by a database CHECK constraint.
Delivery is never folded into a product price and a discount is never folded
into a delivery charge, so a customer disputing a total can be shown which
part they are disputing.

Delivery and tax come from the auction's frozen snapshot when there is one,
and otherwise from settings an administrator owns. Both are seeded at zero:
no delivery charge and no tax rate is invented anywhere.

### The browser never sends an amount

A request names a product or an auction. That is the whole of its influence.
`CheckoutPricer` reads the product row, the auction's frozen snapshot and the
bid records, computes every figure, and freezes them onto the order before the
provider is contacted. `InitializeOrderPayment` takes an order and nothing
else — there is no parameter through which a price could enter, and a test
asserts the method has exactly one.

### Opening a checkout does not end an auction

It creates an obligation. The auction runs on, other people keep bidding, and
the standing highest bidder is still in the running. Only a payment verified
with Paystack ends it. Terminating on a click would let an abandoned checkout
kill a live auction that other people were still competing in.

### Reservations, and why only sometimes

A Buy Now checkout on a plain catalog product holds one unit aside so it
cannot be sold from under a customer who is paying — with an explicit
deadline, after which `orders:expire-checkouts` gives it back. That deadline is
the reason the hold is safe: without it, one abandoned checkout would take a
product off sale permanently.

An auction-linked order holds nothing. The auction reserved that unit when it
was published and it is the same unit being bought; reserving again would take
two units off the shelf for one sale. The `holds_reservation` column says
which, explicitly, because releasing a reservation nobody took would overstate
available stock.

### Fulfilment: one path, four steps

`FulfillOrderPayment` is the only way an order becomes paid. The webhook and
the browser callback both arrive here; the callback does not get to skip
verification because a customer is watching.

1. **Ask Paystack server-to-server.** A webhook body says what someone sent
   us, not what was paid. A browser returning proves only that a browser
   returned.
2. **Check against the frozen payment attempt** — status, reference, currency,
   amount. Against the attempt, not the order: the attempt records what the
   provider was actually asked for, and a trigger refuses to let it change.
   Any mismatch is a refusal, never "close enough".
3. **Run under the idempotency guard**, keyed on the attempt, so five
   deliveries produce one fulfilment.
4. **Inside one transaction**: lock the order, re-check it is not already
   paid, mark the payment successful, hand the product over, then mark the
   order paid.

The order is marked paid **last**, after the product has changed hands. If
anything fails the whole transaction rolls back and the order stays visibly
outstanding rather than looking complete.

### The lock order

```
1. the order row     SELECT ... FOR UPDATE
2. the auction row   inside CompleteBuyNow / AuctionLifecycle::settle
3. the product row   inside InventoryService
4. the wallet row    (untouched by checkout, but the order is unchanged)
```

The same sequence as the auction engine, extended by one step at the front.
Every race — two Buy Nows, a Buy Now against a settlement, a webhook against a
callback — serializes on step 1.

### One successful payment, one sale

Three routes hand the product over, and each produces exactly one inventory
sale:

| Order | How the product is handed over |
| --- | --- |
| Buy Now with an auction | `CompleteBuyNow` sells the unit the auction held and ends it |
| Buy Now on its own | the order sells the unit it reserved at checkout |
| Auction win | `AuctionLifecycle::settle()` sells the unit the auction held |

### When a payment succeeds but nothing can be delivered

Somebody else acquires the item while a customer is paying. The payment is
real, so it is recorded: the order becomes `Paid`, and
`fulfilment_blocked_reason` records why nothing further happened. It appears in
the admin "needs attention" queue.

It is not marked fulfilled, and it is not silently swallowed. What is owed to
that customer is a decision for a person, who can then return the money through
the refund workflow -- never automatically, and never as a credit.

### Nothing marks an order paid by hand

There is no such method and no such control, anywhere. `Paid` is reachable
only through a verified payment, so an administrative button would have
nothing to call. `OrderLifecycle::advance()` refuses any target but
`Processing` and `Fulfilled`, which are ordinary operational work and are
audited like everything else.

### Frozen once paid

From the moment a payment is verified, an order's amounts, source, auction,
winning bid and customer are historical fact. The model guard refuses a change
and a database trigger refuses it again. A correction is a separate, explicit
financial act — not a rewrite of the original transaction.

### Credits are never charged at checkout

They were consumed when the bids were placed, permanently. No checkout or
payment path posts a credit transaction in either direction, and a regression
test asserts the count does not move.

---

## Fulfilment and delivery

Getting the thing to the customer. By hand, on purpose.

```
Payment verified  →  Delivery opened (Pending)
                          ↓  address
                     Preparing → ReadyForDispatch → Dispatched → OutForDelivery → Delivered
                                                         ↓              ↓            ↓
                                                    DeliveryFailed ─────┘        Order Fulfilled
                                                         ↓
                                                    retry (a person decides)
```

### Five things that are not one thing

| Question | Answered by |
| --- | --- |
| Did the customer pay? | the payment |
| What did they buy? | the order |
| Is it being prepared? | the delivery, and the order's `Processing` |
| Has it reached them? | the delivery |
| Did money go back? | the refund |

An order sits at `Processing` throughout `Preparing`, `ReadyForDispatch`,
`Dispatched` and `OutForDelivery`, because commercially nothing changes — the
platform was paid and is getting the item to the customer, whether the box is
on a shelf or in a van. Only `Delivered` completes the order.

Collapsing any two of these would mean a member of staff carrying a box
appearing to make a statement about money. The whole design is arranged so
they cannot.

### Manual, and therefore guarded

Every transition is somebody in a warehouse saying what they just did. That is
exactly why each one is checked against the state machine, authorized,
recorded with an actor and a time, and applied under a lock.

A manual process has no courier API to ask afterwards what really happened. The
`delivery_transitions` table is the only account there will ever be, so it is
written for every move and never edited — database triggers refuse both updates
and deletes.

### What a delivery cannot do

There is no method for any of it:

- **Mark an order paid.** `Paid` is reachable only through a payment verified
  with the provider. A control here would have nothing to call.
- **Move a credit.** Bid credits are consumed permanently. A package failing,
  being cancelled, or arriving returns none of them, and the `deliveries` table
  has no column that could express one.
- **Post an inventory movement.** The unit was sold when the payment was
  verified. A package coming back does not put it on the shelf — that is a
  physical return, and reverse logistics do not exist here.
- **Refund anything.** A failed delivery is an operational exception, not a
  financial decision. If money is owed it goes through the refund workflow,
  with its own permission and its own record. The delivery domain does not
  import the payment gateway at all, and there is a test asserting that.
- **Change an auction result.** A winner whose delivery failed still won, still
  has their credits consumed, and the auction does not reopen.

### The address is a copy, not a reference

Customers keep an address book. A delivery takes a **copy** when it is created,
and a database trigger freezes that copy the moment anybody starts handling the
package.

So a customer who moves house in March cannot redirect a package that went out
in February, and an order from last year still says where it actually went.
Editing or deleting an address book entry changes where the *next* order goes
and nothing else.

Before anybody has touched the package the address may still be supplied or
corrected. That is deliberate and is the auction winner's path: their order is
created by the closing sweep while nobody is at a keyboard, so they were never
asked. Their delivery begins with nowhere to go, cannot leave `Pending` until
it has somewhere, and they supply it from the tracking page.

Ghana-shaped and forgiving: who receives it, a number to call, something to
find and a town are required. Area, region, landmark and GhanaPostGPS are
optional — most people do not know their digital address, and demanding one
would block the checkout of everybody who does not.

### When a delivery is created

At the moment a payment is verified, and never before. Creating one at checkout
would fill the warehouse queue with abandoned carts, expired checkouts and
failed payments — work that does not exist, for orders nobody paid for.

One order, one package, enforced by a unique index rather than an application
check that two concurrent fulfilments could both pass. A blocked order gets no
delivery at all: there is nothing to send.

### When an order becomes Fulfilled

Only when a delivery is marked `Delivered`, in the same transaction, through
the order's own lifecycle — so every guard that governs an order reaching
`Fulfilled` still applies. A delivery cannot push an order somewhere the order
refuses to go.

### Failure and retry

A failed attempt records the reason as a controlled code, a note, who reported
it and when. It changes nothing else: the order stays where it is, the money
stays where it is, the stock stays sold.

Retrying is a decision somebody makes, never a timer. A delivery that failed
failed for a reason, and something — a corrected phone number, a different day,
a conversation — has to change before another attempt is worth making. A
scheduled retry would simply repeat the failure.

### What is deliberately not automated

No courier API, no DHL, no FedEx, no Ghana Post integration, no shipping-rate
API, no courier webhooks. No driver accounts, no driver app, no GPS, no route
optimisation, no automated dispatch, no delivery commissions. No shipping
pricing engine — what delivery costs was decided at checkout and frozen on the
order, and there is no amount column on a delivery at all.

The carrier and reference fields are free text and internal. Nothing on this
platform can be looked up anywhere else, and the customer's tracking page says
so rather than implying otherwise.

### Tracking, and telling the truth

The customer's timeline marks a step complete because a timestamp exists for
it, not because of where the package is now. A package that went straight from
dispatched to delivered never shows "out for delivery" as done, because it
never was.

Nothing internal reaches the customer: no staff notes, no internal reference,
no raw failure code, no audit trail. A failed attempt is described neutrally —
a package refused at the door and one nobody answered read the same, because
telling somebody the delivery failed because they refused it is a conversation,
not a status line.

### Permissions

`deliveries.view`, `deliveries.update`, `deliveries.dispatch`,
`deliveries.complete`, `deliveries.retry`, `deliveries.cancel` — six, because
in a warehouse these are different jobs done by different people. Packing is
not the authority to declare an order complete.

No customer holds any of them. Customers hold `addresses.manage`, which lets
them say where a package should go and never where it has got to.

---

## Refunds and recovery

Money the platform received and could not deliver against, given back.

```
Blocked order  →  RequestRefund  →  ProcessRefund  →  Paystack
                   (Pending)          (Processing)         │
                                           ↑               │
                                    refunds:reconcile  ←────┘
                                           ↓
                                  Succeeded / Failed
```

### A refund is a new event, not an edit to the old one

After a full refund the original payment still reads `success` for its full
amount, because that is what happened. The platform was paid, and then it paid
back; both are true and each has its own record. Two questions, two answers:

| Question | Answer |
| --- | --- |
| What was originally paid? | `order_payments.amount_minor` |
| How much was refunded? | the succeeded refunds against it |

Rewriting the payment would destroy the first answer in order to store the
second, and a customer's receipt would stop agreeing with our records. A
database trigger refuses any change to a refund's amount, currency, order or
payment — and refuses to reopen a settled one. A retry is a new refund with its
own row, so a failure is never overwritten by the success that followed it.

### Three things a refund does not do

**It returns no credits.** Bid credits are consumed the moment a bid is
accepted and stay consumed through every outcome — losing, being outbid, an
auction cancelled, a settlement forfeited, and a payment refunded. A customer
who bid 170 credits and was refunded GH₵5,500 still has 170 fewer credits. The
`refunds` table has no column that could express one, and there is a test that
bids, refunds, and then checks the ledger and the wallet are untouched.

**It restores no stock.** Whether an item is back on the shelf is a physical
question, and reverse logistics do not exist here. A refund posts no inventory
movement of any kind.

**It reopens no closed order.** An order that was cancelled or that expired
keeps the status it closed with. The refund record says the money went back;
the order still says why it closed. Overwriting that would erase the more
useful fact.

Only a `Paid` order becomes `Refunded`, and only once every pesewa has gone
back. A partial refund moves nothing — an order still owed money has not
finished being refunded.

### Nothing is called succeeded on our say-so

Paystack settles refunds asynchronously: it accepts the request, answers
`pending`, and finishes later without telling us. So an accepted request is
`Processing` and nothing more. Only the provider's own terminal status, fetched
server-to-server, produces a success.

A status the code does not recognise is treated as still in flight — never as
money returned. Being wrong in that direction means telling a customer their
money is back when it is not, and that message cannot be taken back. There is
no timeout after which the platform assumes a refund completed.

`refunds:reconcile` runs every fifteen minutes and is what asks.

### Two steps, because an attempt must survive its own failure

`RequestRefund` writes the refund and commits before anyone talks to Paystack.
If the call and the record shared a transaction, a failed call would roll the
attempt away and nothing would remain to show that a member of staff tried to
return somebody's money.

`ProcessRefund` then makes the call and **does not throw when the provider says
no**. A rejection, an unreachable host, or an answer describing a different
amount is recorded as a failure with a reason a person can read, and stays in
the queue. Nothing retries by itself: a loop would hammer the provider on a
request it has already refused, and could return money twice if one of those
attempts quietly succeeded.

### What is eligible

Only an order whose fulfilment is blocked — the three cases Stages 7 and 8
recorded and deliberately left open:

| Case | Order status | What Stage 10 adds |
| --- | --- | --- |
| Another transaction took the unit | `Paid` | Refund → `Refunded` |
| Paid after the checkout expired | `PaymentExpired` | Refund, status unchanged |
| Paid after cancellation | `Cancelled` | Refund, status unchanged |

A healthy paid order is not refundable: nothing went wrong, the customer is
getting their item, and refunding while the sale stands would give away both
the money and the stock. A delivered order is a return, which this platform
does not do.

### The amount is computed, never supplied

The browser names an order and a reason. It never names a figure.

```
refunded    = succeeded refunds                  what a customer is shown
refundable  = payment − succeeded − in-flight    what a new refund may be for
```

In-flight refunds count against the cap, and that is the whole defence against
over-refunding. Subtracting only succeeded refunds would let two attempts for
70% of a payment exist at once on the theory that only one settles — and when
both settled the platform would have returned 140% of what it received. A
failed attempt releases its share again, because it returned nothing.

`RequestRefund` takes the order row lock first and computes everything under
it, so two administrators pressing refund at the same moment serialize: the
first writes and commits, the second reads what the first left behind and is
refused. There is a test that runs exactly that race against real MySQL locks.

### Reconciliation detects; it does not repair

`refunds:reconcile` reports unconfirmed successes, refunds the provider gave no
reference for, amount and currency disagreements, over-refunds, and attempts
stalled far longer than a refund takes. It changes none of them, and exits
non-zero so a monitor notices.

This is the credit ledger's principle applied to refunds: an automatic repair
is a guess about which of two disagreeing records is right, made by the very
code whose bug may have caused the disagreement. A person looks, and fixes it
with a new financial act.

### Staff and customers

Five permissions — `refunds.view`, `refunds.request`, `refunds.process`,
`refunds.retry`, `refunds.inspect` — because seeing what is owed, deciding to
give it back, and sending it are different acts. No customer holds any of them.

The admin screen shows what is owed, what went back, and what failed. There is
no control to mark a refund succeeded, edit an amount, or delete a failed
attempt, and no method behind any of them. Refunding opens a confirmation
listing the order, the customer, what was paid, what will be returned, and what
the refund will not do.

Customers are told a refund started, and separately that it completed — the
second only once the provider has confirmed it. No timeline is promised,
because the platform does not control when a bank posts a credit, and on an
auction-linked order the message says plainly that Credits stay consumed.

---

## Notifications

Everything the platform tells a customer, and — more importantly — everything
it cannot break by telling them.

```
Domain event  →  NotificationSubscriber  →  in-app notification
                                         →  email, where warranted
```

### Informational, never authoritative

Nothing in the application reads a notification to decide anything. Deleting
every row in `notifications` leaves the ledgers, the auctions, the orders and
the inventory exactly as they are, and there is a test that does precisely that
and then checks.

That guarantee rests on two rules:

**Dispatch after the commit, never inside it.** A notification written inside a
transaction that later rolls back would describe an event that did not happen,
and one that threw would take the transaction with it. Every dispatch point
sits after its `DB::transaction()` has returned.

**Nothing may throw into a caller.** Every handler is wrapped and every failure
path ends in a log line. A test replaces the dispatcher with one that throws on
everything and proves that bids, credit consumption, auction closures, payment
verification, settlements, Buy Now terminations and the operational clock all
still complete.

### One event, one notification

Every notification carries an `event_key` derived from the business event and
its recipient — never from the message text, which may be reworded without
changing what happened. A unique index turns "this webhook arrived five times"
into one notification, and does so without an application check that two
simultaneous retries could both pass. The same defence as
`payment_webhook_events`.

### Channels

**In-app** is the notification. Laravel's own `notifications` table, extended
with `event_key` and three columns recording what happened to the email.

**Email** is supplementary, and narrow: money and obligations warrant one, a
bid does not — an email per bid on a busy auction is how an address gets marked
as spam. It is attempted after the row exists, failures are recorded on that
row rather than raised, and a customer whose mail bounces still has everything
waiting when they sign in.

Mail is sent inline rather than queued, deliberately: a queued send would need
a running worker for anybody to hear anything, which would make correctness
depend on infrastructure this stage does not introduce. Moving it onto the
queue is a one-line change once a worker exists.

**No SMS.** `OtpChannel` remains bound to its unconfigured implementation, and
no provider or credential is invented.

### Preferences

Two switches per optional category — in-app and email — and nothing else.

| Category | Switchable |
| --- | --- |
| Payments and orders | **No** |
| Bidding activity | Yes |
| Auction results | Yes |

Transactional notifications are not negotiable. A switch that silently stopped
the platform telling somebody it had taken their payment and could not deliver
would be a misleading experience rather than a quieter one, so the value object
refuses to store one and the preferences screen does not offer it.

An account that has never opened that screen has a null column and gets every
default, so nothing needs backfilling.

### What the messages say

Three things the wording is careful about, because they are the three easiest
to get wrong:

- **Credits are a count.** "180 Credits", never "GH₵180".
- **A settlement is money, and its own figure.** A winner is told their bid was
  180 Credits *and* that their Auction Settlement Amount is GH₵100. Never that
  one became the other.
- **A blocked payment promises nothing.** "We received your payment, but
  fulfilment is blocked… our support team will be in touch." No refund is
  offered or implied — one may follow, once a person decides, and the
  customer hears about it then rather than before.

Losing bidders are told plainly that they did not win and that their credits
remain consumed. There is no refund control anywhere on that page, and a refund
of money never returns a credit.

### Privacy

A bidder is never named to another bidder; an outbid message discloses the
amount to beat and nothing else. A Buy Now buyer is never named to the people
they outbid. The staff screen shows a recipient's name and no contact details.

### Pending product decisions

Two notification types were **deliberately not built**, because each needs a
threshold nobody has chosen, and seeding one would make that decision by
default:

- **"Auction ending soon"** — how soon is soon?
- **"Settlement deadline approaching"** — how long before the deadline?

Both are listed in `NotificationType`'s docblock as absent for this reason.
Once the business chooses the thresholds they become settings, and the sweeps
that would send them attach to the existing `auctions:tick`.

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
