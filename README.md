# As-Is-Commerce

A credit-based auction marketplace for the Ghanaian market. Users buy virtual
bidding credits, spend them to place bids on live auctions, and the account
holding the leading position when the server-side countdown expires wins the
right to buy the product at the auction's checkout price.

All monetary values are in Ghana Cedis (GH₵) and are stored as integer minor
units (pesewas). Never as floating point.

> **Development status — Payments & credit packages.**
> This repository currently contains the application foundation
> (authentication, roles, application shell), the auction rules engine, the
> credit and cash ledgers, and buying credits with real GHS through Paystack.
> The auction engine, bidding, product catalog, Buy Now, orders and delivery
> are built in later stages and are deliberately absent — there is no
> `auctions`, `bids` or `products` table, and credit *consumption* is not yet
> wired to anything.

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
./vendor/bin/phpstan analyse # static analysis, level 6
```

---

## The rules engine

> **Unresolved: the winning mechanic.**
> This layer was built for **Last Bidder Standing**, as specified at the time.
> The Stage 4 brief instead states that the auction rule is *highest valid
> credit bid wins*. The two are not compatible: the ruleset's closing window,
> extension length, maximum extensions and unique-leader rule exist only to
> serve a countdown that late bids extend, and a highest-bid auction needs
> none of them.
>
> Nothing has been rewritten on that basis, because the decision is the
> business's to make. Payments and the ledger are unaffected either way. This
> must be settled before the auction engine is built. See the Stage 4 report.

As built, the rules engine describes **Last Bidder Standing**: bidding spends
credits, and whoever holds the lead when the server-side countdown expires
wins the right to buy the product at a separate, predetermined checkout price.
The credits spent bidding never determine that price.

Nothing about that behaviour is hard-coded. Configuration flows in one
direction:

```
Auction ruleset  (mutable, versioned configuration)
       │
       │  toRules(checkoutPrice)   ← taken once, when an auction is created
       ▼
AuctionRules     (immutable value object)
       │
       │  toArray() → JSON, stored on the auction row
       ▼
Auction engine   (reads the snapshot, never the ruleset)
```

### Why the snapshot exists

An auction must never hold a live reference to configuration an administrator
can edit. If it did, changing the closing window on a Tuesday would silently
rewrite how an auction that ran on Monday is explained — and with money and
competitive outcomes involved, that is not recoverable.

So an auction takes a **complete copy** of its rules at creation, as an
immutable `AuctionRules` value object serialized into its own row. Editing,
archiving or even deleting the ruleset afterwards has no effect on it. This is
covered by tests that assert exactly that property.

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

### Where the checkout price comes from

A ruleset carries auction *defaults*. The checkout price belongs to the
**product** being auctioned, so it is supplied when the auction is created:

```php
$rules = app(RulesetResolver::class)->rulesFor(
    Money::fromDecimalString('5500.00'),   // this product's price
    'Standard Auction',                    // optional; omit for the default
);
```

`default_checkout_price_minor` on a ruleset is nullable and normally stays
null. Building rules with neither a caller-supplied price nor a default is an
error, rather than a guess.

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
