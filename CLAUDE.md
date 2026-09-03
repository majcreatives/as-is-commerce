# Engineering conventions — As-Is-Commerce

A credit-based auction marketplace for Ghana. Read `README.md` for setup.
This file records the rules that are not obvious from the code.

## Current stage

**Payments & credit packages.** Foundation (auth, roles, shell), the auction
rules engine, the credit and cash ledgers, and buying credits with real GHS
through Paystack.

The auction engine, bidding, product catalog, Buy Now, orders, delivery,
referrals and gamification do **not** exist yet and must not be built ahead of
their stage. There is deliberately no `auctions`, `bids` or `products` table,
and credit *consumption* is still wired to nothing. The next stage is the
Product Catalog & Inventory Foundation.

## Three things that must never be conflated

This is the distinction most likely to be broken by someone moving fast:

```
Credit purchase   GHS buys a fixed number of credits.  A package price.
Bid               N credits, spent to bid.            Never money.
Buy Now price     GHS a product costs outright.       Never credits.
```

There is **no** arithmetic relationship between them. 500 credits costing
GH₵45 does not make one credit worth 9 pesewas, and a product priced at
GH₵5,500 has nothing to do with either. Never write code that converts credits
to money or money to credits outside the explicit credit-package purchase.

Credits never become cash. Credits spent bidding are gone — for losing and
winning bidders alike.

Terminology: say **Highest Bid (Credits)**, never "auction price"; say
**Credits**, **Credit Package**, **Credit Balance**, never "credit value" in
money.

## Payments

### A browser callback is not proof of payment

A customer returning from Paystack proves only that a browser arrived. They
may have abandoned the payment or edited the URL. The callback takes the
reference, looks up a purchase **the signed-in user owns**, and then runs the
same verified fulfilment path the webhook uses. It is not a second, weaker way
to obtain credits.

### Credits are granted only through verified, idempotent server-side fulfilment

`FulfillCreditPurchase` is the only path. Every route into it does this:

1. **Verify server-to-server.** A webhook body is a claim, not evidence. Ask
   Paystack directly what happened to the transaction.
2. **Check against the purchase snapshot** — status success, reference,
   currency, amount. Any mismatch is a refusal, never "close enough".
3. **Run under `IdempotencyGuard`**, keyed on the purchase, so repeated
   deliveries produce one grant.
4. **Inside one transaction**: lock the purchase row, re-check it is not
   fulfilled, post cash, post credits, mark fulfilled.

A purchase is marked `FULFILLED` only after credits exist. If posting fails,
everything rolls back and the purchase stays visibly outstanding rather than
looking complete — that is what lets a retry put it right.

### Webhook security

- Public and unauthenticated; Paystack cannot log in.
- Signature is HMAC SHA512 over the **raw body**, keyed with the secret,
  compared with `hash_equals`. Never re-encode a decoded payload before
  verifying — the bytes change and the signature fails.
- Verified before anything is stored, parsed for meaning, or acted on.
- Events are stored under a unique `(provider, provider_event_id)` before
  processing, so a redelivery is recognised by the database rather than by an
  application check that would race.
- Returns 2xx promptly. A processing failure returns 5xx so Paystack retries;
  the event is already stored, so a retry is safe.
- CSRF is exempted for this route only, in `bootstrap/app.php`.

### The package snapshot

`credit_purchases` carries `package_name_snapshot`, `credit_amount`,
`amount_minor` and `currency`, frozen when the transaction was opened.
Fulfilment reads the snapshot and **never** the package record. Repricing a
package must not change what an already-open purchase costs or grants.

### Refunds and reversals

A refund event is recorded but **does not** claw credits back. They may
already have been spent, and reversing a spend is a business decision, not
something to infer from a provider event. Do not invent a claw-back policy.

### Environment

```
PAYSTACK_SECRET_KEY     server-only, never sent to a browser, never committed
PAYSTACK_PUBLIC_KEY
PAYSTACK_BASE_URL       https://api.paystack.co
PAYSTACK_CURRENCY       GHS
PAYSTACK_TIMEOUT        seconds; short, since verify runs inside the webhook
```

Never disable TLS verification. Never log the secret, a full payload
containing authorization data, or card details.

### Testing payments

Tests need no real credentials: the HTTP client is faked and the signature
verifier works against whatever secret is configured.

`Http::fake()` **appends** stubs and the first match wins, so faking again
inside a test does not override a `beforeEach` stub — the test would pass
while proving nothing. Use `fakeHttp()` / `fakePaystackVerify()` from
`tests/Pest.php`, which swap the factory and genuinely replace the stubs.

## Financial rules — non-negotiable

## Financial rules — non-negotiable

This codebase handles real money and virtual credits. These ten rules are the
ones that must never be relaxed:

1. **The ledger is authoritative.** `credit_wallets.balance` and
   `cash_wallets.balance_minor` are materialized projections of it, never the
   source of truth.
2. **Transactions are append-only.** `credit_transactions`,
   `cash_transactions` and `credit_lot_consumptions` are never updated or
   deleted. Database triggers enforce this, not just the models.
3. **No direct balance mutation.** Never write a balance column. Post a
   transaction through the ledger service; the guard trait will reject
   anything else.
4. **Credits and cash are separate systems.** Different tables, different
   services, different enums. Never introduce a shared or convertible balance.
5. **Money is integer minor units** (pesewas) in a `Money` value object.
6. **Credits are `BIGINT` integers.** Never fractional.
7. **Financial writes are transactional.** Wallet, ledger, lots and
   consumptions all succeed together or none of them do.
8. **Idempotency is required** for anything an external system may retry —
   webhooks, payments, refunds, adjustments. Use `IdempotencyGuard`.
9. **Credit consumption uses deterministic lot ordering** — promotional first,
   then soonest-expiring, then oldest. Never ad hoc.
10. **Corrections use compensating entries.** Never edit history.

### Locking order

Always the same sequence, or concurrent operations will deadlock:

```
1. the wallet row              SELECT ... FOR UPDATE
2. that wallet's credit lots   SELECT ... FOR UPDATE ORDER BY id
```

Lots are **locked** in id order but **consumed** in business order — the
allocator reorders them after the locks are held. Keep those two orders
separate.

The wallet lock is taken before the balance is read, which is what makes
overspending impossible rather than merely unlikely: a second debit blocks
until the first commits and then reads the balance the first left behind.

### Never do these

- `$wallet->balance += 100` — there is no code path that permits it.
- `$transaction->update(...)` on any ledger row.
- Compute a balance by summing lots and writing it back outside a service.
- Add a "set balance" admin control. Adjustments only, with a reason.
- Repair a reconciliation discrepancy automatically. Report it; a human
  decides, and fixes it with a compensating entry.

### Credit expiry

Lots carry a nullable `expires_at`. Purchased credits do not expire by
default; promotional credits may. `CreditLedgerService::expireLots()` writes
off the unspent remainder with an `EXPIRATION` transaction.

The scheduled worker that calls it belongs to a later stage. When it is built:
it must stay transactional, and it is already naturally idempotent because an
expired lot's remainder reaches zero on the first run and is skipped
thereafter. There is a test asserting exactly that.

## Auction rules — the snapshot rule

## Stack

Laravel 13 · PHP 8.3+ · MySQL 8 · Livewire 4 · Tailwind v4 · Vite · Pest

Redis, Horizon and Reverb are deliberately not installed. They arrive with the
auction engine. Do not add them earlier, and do not add packages that duplicate
something the framework already provides.

## Non-negotiable rules

These exist because this application handles money and competitive outcomes.

1. **The server decides.** Auction state, timers, balances, bid validity and
   winners are determined server-side. Never trust a client-supplied balance,
   timestamp or outcome.
2. **No credit movement without a ledger row.** Once the wallet exists, every
   change in balance must have an immutable transaction record. Never mutate a
   balance column on its own.
3. **No bid without a successful credit deduction**, in the same database
   transaction.
4. **Financial operations use database transactions** with explicit locking and
   idempotency keys.
5. **Never fabricate.** No fake payments, fake auction results, fake winners,
   fake balances or seeded demo data. Where data does not exist, render an
   empty state.
6. **Business rules are configuration.** Bid cost, auction duration, closing
   window, extension length, maximum extensions, fees, taxes and commissions
   are configurable values — never constants in code.
7. **Money is integer minor units** (pesewas). Never floats.
8. **UTC everywhere** in storage; local time only at the presentation layer.
9. **Never commit secrets.** `.env` is ignored; `.env.example` holds only
   placeholders.
10. **Never bypass authorization for convenience**, including in tests.

## Code organisation

- Domain logic lives in `app/Domain/<Context>/`, not in controllers.
- Controllers and Livewire components stay thin: validate, delegate, respond.
- Multi-step operations belong in an Action class under
  `app/Domain/<Context>/Actions/`.
- Use PHP enums instead of magic strings.
- Bind interfaces in `AppServiceProvider` so implementations stay swappable —
  see `PhoneNumberNormalizer` and `OtpChannel` for the pattern.

## Auction rules — the snapshot rule

The single most important invariant in this codebase.

An auction takes an **immutable copy** of its rules when it is created:

```php
$rules = $ruleset->toRules($checkoutPrice);   // AuctionRules value object
$auction->rules_snapshot = $rules->toArray(); // stored as JSON on the auction
```

Never give an auction a foreign key to `auction_rulesets` and read rules
through it at runtime. If you do, an administrator editing configuration
retroactively changes how past auctions behaved, and a disputed result becomes
unexplainable.

Rules are read from the snapshot, always. `AuctionRuleset` is mutable
configuration for *creating* auctions; `AuctionRules` is what the engine runs
on.

Consequences to respect:

- `AuctionRules` is a `readonly` class. Keep it that way.
- Bump `AuctionRules::SNAPSHOT_VERSION` if its serialized shape changes, and
  handle older versions in `fromArray()`. Stored snapshots must stay readable.
- Only **draft** rulesets are editable. Changing an active one means drafting
  a new version, never mutating it.
- Archived rulesets are never deleted.

## Bid cost and checkout price are unrelated

Credits spent bidding do **not** determine what the winner pays. `bid_cost_credits`
and `checkout_price` are separate values and must never be derived from one
another. The checkout price belongs to the product and is supplied at auction
creation; the ruleset's `default_checkout_price_minor` is a nullable fallback
that normally stays null.

## Money

Integer minor units (pesewas), in a `Money` value object, in `BIGINT` columns
named `*_minor`. Never float, never `DECIMAL` arithmetic in PHP, never a
currency symbol stored with an amount.

Parse with `Money::fromDecimalString()`, which does integer string parsing.
Do not write `(int) ($value * 100)` anywhere — it is wrong for most decimals.

Rates and percentages are **basis points** as integers: 1000 = 10%.

## Settings

Read through `settings()`, never by querying the `Setting` model directly, so
reads stay cached and casting stays in one place. Use the typed accessor you
need (`getString`, `getInt`, `getBool`, `getMoney`) rather than casting at the
call site.

Adding a setting means adding it to `SettingsSeeder::definitions()`. The
seeder owns structure (key, type, group, label); administrators own values.

## Authorization

Gate on **permissions**, not role names, so narrower administrative roles can
be introduced later without editing call sites:

```php
$this->authorize('auction_rulesets.activate');   // yes
if ($user->hasRole('admin')) { ... }             // no
```

Add new permissions to `PermissionSeeder::PERMISSIONS`. A super admin passes
every check via a `Gate::before` hook, so it does not need re-seeding.

## Audit logging

Configuration changes are logged with `spatie/laravel-activitylog`. Two things
to know about v5:

- Model-event diffs land in the **`attribute_changes`** column.
  `properties` holds only what you attach manually with `withProperties()`.
- Pass the actor explicitly through `CauserResolver::withCauser()` in actions,
  so console commands and queued jobs attribute changes correctly rather than
  recording a null causer.

Never log passwords, tokens or payment credentials.

## Phone numbers

Phone is the primary identity; email is optional. Numbers are normalized to
E.164 (`+233XXXXXXXXX`) before storage so the unique index sees one canonical
form. Always normalize before comparing or persisting — never compare raw
input. `GhanaPhoneNumberNormalizer` can be replaced with a libphonenumber-backed
implementation when the platform supports more than one country.

## OTP

No SMS provider is integrated. `OtpChannel` is bound to
`UnconfiguredOtpChannel`, which throws rather than pretending a code was sent.
Do not add a hard-coded or logged development code — a verification flow that
appears to succeed without a provider is a fake success.

## Testing

Tests run against MySQL (`as_is_commerce_testing`), not SQLite: the auction
engine depends on `SELECT ... FOR UPDATE` semantics SQLite cannot model.

Write tests for financial and auction-critical behaviour. Do not write tests
that assert nothing in order to raise coverage.

## Before finishing any change

```bash
./vendor/bin/pint
./vendor/bin/phpstan analyse
php artisan test
```
