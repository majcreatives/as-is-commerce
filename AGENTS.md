# Engineering conventions — As-Is-Commerce

A credit-based auction marketplace for Ghana. Read `README.md` for setup.
This file records the rules that are not obvious from the code.

## Current stage

**Settings & auction rules engine.** Foundation (auth, roles, shell) plus the
configuration layer governing auction behaviour.

The wallet, credit ledger, payments, auction engine, bidding, orders,
delivery, referrals and gamification do **not** exist yet and must not be
built ahead of their stage. There is deliberately no `auctions` or `bids`
table. The next stage is the Wallet & Credit Ledger.

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
