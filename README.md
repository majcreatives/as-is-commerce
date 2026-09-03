# As-Is-Commerce

A credit-based auction marketplace for the Ghanaian market. Users buy virtual
bidding credits, spend them to place bids on live auctions, and the account
holding the leading position when the server-side countdown expires wins the
right to buy the product at the auction's checkout price.

All monetary values are in Ghana Cedis (GH₵) and are stored as integer minor
units (pesewas). Never as floating point.

> **Development status — Settings & rules engine.**
> This repository currently contains the application foundation
> (authentication, roles, application shell) and the configuration layer that
> governs auction behaviour. The wallet, credit ledger, payments, auction
> engine, bidding, orders and delivery are built in later stages and are
> deliberately absent — there is no `auctions` or `bids` table yet.

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
migrated fresh for each run. They do **not** use SQLite: the auction engine
will depend on MySQL row-locking semantics (`SELECT ... FOR UPDATE`) that
SQLite cannot reproduce, so testing on a different engine would give false
confidence in the area where correctness matters most.

### Code quality

```bash
./vendor/bin/pint --test     # code style check (drop --test to fix)
./vendor/bin/phpstan analyse # static analysis, level 6
```

---

## The rules engine

The platform runs **Last Bidder Standing** auctions: bidding spends credits,
and whoever holds the lead when the server-side countdown expires wins the
right to buy the product at a separate, predetermined checkout price. The
credits spent bidding never determine that price.

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
│   ├── Settings/         Typed, cached application settings
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
