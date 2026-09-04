# Engineering conventions — As-Is-Commerce

A credit-based auction marketplace for Ghana. Read `README.md` for setup.
This file records the rules that are not obvious from the code.

## Current stage

**Auctions run themselves, end to end.** Foundation (auth, roles,
shell), the credit and cash ledgers, Paystack credit purchases, the product
catalog with an auditable inventory ledger, the auction rules engine, the
auction engine, and checkout: `orders`, `order_items`, `order_payments`,
Paystack payments for products, verified idempotent fulfilment, and the
inventory and auction completion that follows.

Both acquisition paths run end to end, without anybody watching. Scheduled
auctions open themselves, close on their own clock, hand the winner a
settlement checkout at the moment they win, and forfeit it if the deadline
lapses. A customer buys outright — ending the auction if there was one — or
wins and settles, and in both cases the product changes hands only after a
payment verified with Paystack.

What must not be built ahead of its stage: refunds, disputes, delivery and
courier integration, tracking, notifications, a tax engine, referrals,
gamification, and real-time delivery (Redis, Reverb, WebSockets).

**There is no refund path, and do not invent one.** A paid order that could not
be completed is recorded with `fulfilment_blocked_reason` and queued for a
person. What is owed is a business decision, not something to infer.

## Three things that must never be conflated

This is the distinction most likely to be broken by someone moving fast:

```
Credit purchase   GHS buys a fixed number of credits.  A package price.
Bid               N credits, spent to bid.            Never money.
Buy Now price     GHS a product costs outright.       Never credits.
```

There is **no** arithmetic relationship between them in any code that exists
today. 500 credits costing GH₵45 does not make one credit worth 9 pesewas, and
a product priced at GH₵5,500 has nothing to do with either. A product has no
column referring to credits, wallets, bids or packages, and there is a test
asserting that.

**The one exception, and it is implemented:** a customer who consumed credits
bidding on an auction gets GH₵1 off that auction's Buy Now price per consumed
credit. 150 credits spent → GH₵150 off.

`BuyNowPricer::quote()` computes it, from the bid records, at the rate frozen
in the auction's snapshot (`buy_now_credit_discount_minor_per_credit = 100`).
Which credits qualify is narrow and must stay so: credits *this user* consumed
on accepted bids *on this auction*. Never a wallet balance, never credits
bought and not bid, never credits spent on a different auction.

A fourth figure now exists and is separate from all three above: the auction's
`settlement_amount_minor`, what a normal winner pays. Outside the Buy Now
discount, no code converts credits to money or money to credits.

Credits never become cash. Credits spent bidding are gone — for losing and
winning bidders alike, and the Buy Now discount does not give them back, it
only reduces a separate purchase price.

Terminology: say **Highest Bid (Credits)**, never "auction price"; say
**Credits**, **Credit Package**, **Credit Balance**, never "credit value" in
money.

## Catalog and inventory

### Platform-owned

The platform owns its stock. There is no seller, vendor, merchant or supplier
column anywhere in `products`, and a test asserts that. Do not add one without
a deliberate marketplace stage.

### Stock is derived from a ledger

`products.stock_on_hand` and `products.stock_reserved` are projections of
`inventory_transactions`, exactly as wallet balances are projections of the
credit ledger. The same rules follow:

- **Never write a stock column.** `$product->stock_on_hand += 5` throws.
  Post a movement through `InventoryService`.
- **Movements are append-only**, enforced by database triggers. Correct a
  mistake with an opposing adjustment, never an edit.
- **Every movement records** type, signed delta, resulting on-hand and
  reserved, reason, actor and time.
- **Stock never goes negative**, and reserved never exceeds on hand.

`available_stock = stock_on_hand - stock_reserved`. Any future checkout must
check *available*, never on-hand alone.

### Locking

`InventoryService` takes the product row with `SELECT ... FOR UPDATE` before
reading its stock. That is what makes overselling impossible rather than
unlikely: a second reservation blocks until the first commits and then reads
what the first left behind.

### Reservations

`Reservation`, `Release` and `Sale` exist and are tested but are called by
nothing. They are for the Buy Now checkout. A reservation does not remove
stock from the building — it marks it as spoken for — and a sale consumes the
reservation that preceded it so the two do not remove the same item twice.

There is deliberately **no reservation expiry** yet. Do not invent one.

### Product status

`Draft → Active → Inactive/OutOfStock → Archived`, guarded by
`ProductStatus::canTransitionTo()`. Archived is terminal.

Only Active and OutOfStock are publicly visible. Only Active is purchasable —
being listed and being sellable are different questions, and conflating them
is how an archived product becomes buyable.

Public queries must start from the `publiclyVisible` scope, and
`ProductStatus::publiclyVisibleCases()` is the single definition of what that
means.

Product status is **not** auction status. The auction stage tracks its own,
and stock arriving must never publish a draft.

### Categories and brands

Neither can be deleted: a category holding products or children is refused by
the database. Archive instead. A brand is optional on a product and nulls
rather than restricting, because losing a brand label is recoverable and
losing the product is not.

### Inventory follows the auction lifecycle

Publishing an auction reserves one unit. A completed Buy Now, or a winner's
settlement, turns that reservation into a sale. Cancelling, closing with no
bids, or forfeiting releases it.

That is how one item cannot be sold twice without a second inventory system,
and it is why several auctions may run on one product only while stock covers
them — each publish reserves a unit of its own and is refused when none is
available. Never write a stock column to arrange this; the auction lifecycle
posts through `InventoryService` like everything else.

The Buy Now *payment* still does not exist. What exists is the termination
path a confirmed payment will call.

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

## The auction model

**The highest valid credit bid wins** when an auction closes normally. Not the
last bidder, not whoever bid most often, not whoever held the lead longest. A
bidder who is overtaken and later bids higher still wins on that highest bid.

**A successful Buy Now purchase ends the auction immediately**, and the
standing highest bidder does not win.

This model replaced Last Bidder Standing in a dedicated correction stage. If
you find anything implying a last-bidder winner, a "leader" who must be
unique, or a fixed cost per bid, it is wrong — those columns were dropped, and
tests assert they stay gone.

### Bids carry their own amounts

There is no fixed cost per bid. A bidder commits however many credits they
choose, and every accepted bid consumes exactly that many, permanently.
Losing bidders do not get them back, and neither does the winner.

The future bid record must therefore hold an explicit amount. Never write code
that assumes one credit per bid, or reads a per-bid price from the ruleset —
there is no such field.

### Undecided rules stay null

`minimum_bid_credits`, `minimum_bid_increment_credits` and
`allow_bid_increase` are nullable, and are null. The business has not chosen
these values.

**Do not invent one.** Null means "no rule", which is different from any
number, and a value seeded to fill the schema becomes the number everyone
designs around. `AuctionRules::smallestValidBid()` returns null when nothing
is configured; the engine must not substitute a floor of its own.

### Settlement is a per-auction amount, not a ruleset field

A normal winner pays the auction's own `settlement_amount_minor`, in integer
pesewas, chosen at creation and frozen into its snapshot, plus applicable
delivery and tax.

It stays off the ruleset deliberately: two auctions on the same product may
settle at GH₵50 and GH₵150. `toRules()` still takes no price, and a test
asserts no settlement key appears in the *rules* half of a snapshot.

Never derive it. Not from the product's Buy Now price, not from the winning
bid, not from a credit balance. Auction winner and Buy Now buyer remain
different roles in different columns.

**Settlement amounts are meant to be low, and that is the business model.**
Do not add a margin check, a warning that an amount looks too low, or anything
that raises it. The platform accepts that some auctions are subsidised.

### The one place credits meet money

One consumed bid credit gives **GH₵1 off the Buy Now price**, stored as
`buy_now_credit_discount_minor_per_credit = 100` — pesewas per credit, an
explicit versioned integer rather than a conversion assumed in code.

```
Buy Now price   GH₵5,500
150 credits consumed bidding on that auction
Discount        GH₵  150
Payable         GH₵5,350
```

The credits stay consumed. This reduces a separate purchase price; it does not
refund them. Only credits a user actually consumed bidding **on that auction**
qualify — never a wallet balance, credits bought and never bid, or credits
spent on something else. The future engine must derive the figure from
auditable bid and ledger records.

Outside this one path, no code converts credits to money or money to credits.

### Timing

`base_duration_seconds` is how long an auction runs. The extension fields are
anti-sniping and are **independent of who wins** — extending the clock gives
others a chance to bid higher, it does not change how the winner is chosen.

Extensions are off by default because the rule is unfinalized. Do not switch
them on with invented numbers.

## Auction rules — the snapshot rule

The single most important invariant in this codebase.

An auction takes an **immutable copy** of its rules when it is created:

```php
$rules = $ruleset->toRules();                 // AuctionRules value object
$auction->rules_snapshot = $rules->toArray(); // stored as JSON on the auction
```

Never give an auction a foreign key to `auction_rulesets` and read rules
through it at runtime. If you do, an administrator editing configuration
retroactively changes how past auctions behaved, and a disputed result becomes
unexplainable.

Rules are read from the snapshot, always. `AuctionRuleset` is mutable
configuration for *creating* auctions; `AuctionRules` is what the engine runs
on. Every snapshot records `winner_rule` explicitly, so the engine reads that
from the auction rather than inferring it from the code of the day.

Consequences to respect:

- `AuctionRules` is a `readonly` class. Keep it that way.
- Bump `AuctionRules::SNAPSHOT_VERSION` if its serialized shape changes.
  Version 2 is the corrected model; version 1 was the last-bidder shape and is
  refused rather than reinterpreted.
- Only **draft** rulesets are editable. Changing an active one means drafting
  a new version, never mutating it.
- Archived rulesets are never deleted.

## The auction engine

An auction is one product, one frozen snapshot, one period. A bid is a number
of credits one user committed to it.

### Where the winner comes from

`CloseAuction` reads `HighestBidResolver::highestBid()` — the bid records —
at the moment of closing. **Never the cached projection.** The projection is
for listing pages; deciding an auction is exactly the case where it must not
be trusted.

Ties go to the earliest bid at the winning amount, ordered by the per-auction
`sequence` allocated under the auction row lock. Not by timestamp: two bids in
the same millisecond would be genuinely ambiguous.

### The lock order — observe it everywhere

```
1. the auction row      SELECT ... FOR UPDATE
2. the product row      inside InventoryService
3. the wallet row       inside CreditLedgerService
4. that wallet's lots   ORDER BY id
```

Every race in this stage is decided at step 1. Two Buy Nows, a bid against a
Buy Now, two bids — the first through commits, the second blocks on the lock,
then reads what the first left behind and is refused. Checking a status
without the lock, or in the browser, would let both believe they had won.

Do not reorder this, and do not add a step between 1 and 2.

### Placing a bid

`PlaceBid` is the only path. The order is: idempotency key, auction row lock,
validation against the locked state, consume credits, write the bid, rebuild
the projection, apply any extension.

- **A bid never exists without its credit consumption.**
  `bids.credit_transaction_id` is NOT NULL and both happen in one transaction.
- **A rejected bid leaves nothing** — no row, no credits moved. Validation is
  inside the transaction.
- **Never assume one credit per bid.** A bid of 150 consumes 150.
- **Never read the standing highest bid without the lock.** Two bidders would
  measure themselves against the same figure and both clear an increment only
  one of them actually cleared.

### Bids are append-only

Triggers refuse UPDATE and DELETE. A bid consumed credits that no longer
exist and its amount decides who wins.

`BidStatus` has one case, `Accepted`, and that is a statement rather than an
oversight: a rejected bid has no row, and nothing voids a bid because credits
are never refunded. If a case is ever added, the `match` expressions in that
enum will force a decision at every call site.

### The highest-bid projection

`highest_bid_id`, `highest_bid_credits` and `bid_count` are a cache of the bid
query, guarded exactly like wallet balances and stock:

- only `HighestBidResolver` writes them,
- `rebuild()` recomputes from the bid records alone,
- `verify()` **reports** a mismatch and never repairs it. Report it; a human
  decides.

### The clock

`starts_at` and `ends_at` are the only authority. Never a browser countdown, a
JavaScript timer, a session or a process lifetime.

`php artisan auctions:tick` sweeps every minute. Every step is idempotent and
re-locks its auction, so overlapping runs close an auction once. A missed run
delays a closure and never changes its outcome.

Bid placement checks the clock **as well as** the status, because an auction
past its end time stays marked Live until the sweep notices.

Extension is anti-sniping and independent of who wins. It cannot make a late
bidder win.

### Buy Now termination

A click, a checkout screen, a redirect or a payment attempt do **not** end an
auction. Only `CompleteBuyNow`, called after a server-confirmed payment.

A completed Buy Now records `closure_reason = buy_now`, puts the buyer in
`buy_now_user_id`, and leaves `winner_user_id` and `winning_bid_id` null. A
CHECK constraint refuses a row claiming both. The standing highest bidder does
not win and gets nothing back.

Nothing calls it yet, and the customer page quotes a price rather than
offering a purchase button.

### Which credits earn the Buy Now discount

Credits this user consumed on accepted bids **on this auction**, summed from
the bid records by `HighestBidResolver::consumedCreditsBy()`.

Never a wallet balance. Never credits bought and not bid, promotional credits
not bid, credits spent on another auction, or another user's credits. A
balance moves with everything else the user does; bids are historical facts
chained to the transactions that paid for them.

### What is frozen

`rules_snapshot`, `snapshot_version`, `settlement_amount_minor`, `product_id`
and `currency` cannot change once an auction leaves Draft — model guard and
database trigger both. There is deliberately no admin form for editing a live
auction, because there is no code path that would let one succeed.

### Auction status is not product status

They are separate lifecycles. An auction closing does not archive a product,
and a product going Active does not open an auction.

## Running auctions

Two scheduled commands do all the operational work, and both are idempotent:
`auctions:tick` and `orders:expire-checkouts`. Nothing about an auction's
correctness may depend on a browser, a page being open, or a realtime channel.

### Closing hands the winner a checkout

`CloseAuction` opens the settlement order in the same transaction, through
`SettlementHandoff`.

That interface exists for a reason: the orders domain already depends on the
auction domain, so a direct call back would couple them both ways. **Do not
replace it with a direct call to `StartSettlementCheckout`.**

A handoff failure must never reopen a closed auction. The highest bid won and
the credits are consumed; closing stands, and the missing order is an
administrative problem rather than a reason to un-close.

### Stopping an auction closes its settlement order too

Use `ForfeitAuction` and `CancelAuction`, never `AuctionLifecycle::forfeit()`
or `->cancel()` directly. The lifecycle methods are the bare state transition;
the actions also close the winner's outstanding checkout.

Skipping them releases the unit while leaving a payable order pointing at it,
and the winner could pay for stock that has already gone back on sale.

A **paid** settlement is never touched. `Settled` is terminal.

### A verified payment is always recorded

Even when nothing can be delivered against it. Mark the attempt successful, set
`fulfilment_blocked_reason`, let it reach the admin queue. Never throw.

Throwing returns a 5xx to Paystack, which retries the same delivery forever
against an order that can never accept it. That was a real defect, and the fix
is to record the fact and acknowledge the webhook.

Three cases reach it: another transaction took the unit, the checkout expired
mid-payment, or the auction forfeited before the payment landed.

### Never decide an acquisition by anything but a lock

Not checkout creation time, page load time, click time, browser timestamp, or
who was leading. The database decides, in the fixed lock order: order, auction,
product, wallet.

### The losing-bidder page has no refund control

Because there is no refund. Bid credits are spent when the bid is accepted, for
losers, for the winner, and when a Buy Now ends the auction. Do not add a
button, a balance adjustment, or wording that implies otherwise.

## Checkout, orders and payment

An order is a GHS obligation. It is not an auction, not a payment and not an
inventory movement — each owns its own table and its own lifecycle, and an
order relates to them.

### Nothing marks an order paid

`Paid` is reachable only through `FulfillOrderPayment`, which asks Paystack
server-to-server first. There is no method, no admin control and no code path
that asserts money arrived — `OrderLifecycle::advance()` refuses any target
but `Processing` and `Fulfilled`.

**Do not add one.** If you find yourself wanting a "mark as paid" button, what
you actually want is a way to re-run verification.

### The browser never sends an amount

A request names a product or an auction. `CheckoutPricer` computes every figure
from server-side reads and freezes it on the order; `InitializeOrderPayment`
takes an order and nothing else. Never add a price, total or discount
parameter to any of that path.

### Verify against the attempt, not the order

`order_payments` records what the provider was actually asked for, and a
trigger refuses to let its amount, currency, reference or order change.
Checking a provider's answer against the order would be checking against a
figure that could have moved, which is not a check at all.

### The lock order

```
1. the order row     SELECT ... FOR UPDATE
2. the auction row   inside CompleteBuyNow / settle()
3. the product row   inside InventoryService
4. the wallet row    then its lots, by id
```

The auction engine's order with one step added at the front. Every race —
two Buy Nows, Buy Now against settlement, webhook against callback — is
decided at step 1. Do not reorder it.

### Opening a checkout ends nothing

It creates an obligation. The auction stays live, bidding continues, and the
highest bidder is still in the running until a payment is verified. A click, a
checkout screen, a redirect and a payment attempt are none of them the point
of no return.

### Reservations have a deadline, always

A Buy Now checkout with no auction holds one unit and sets `payment_due_at`;
`orders:expire-checkouts` releases it if nobody pays. An auction-linked order
holds nothing — the auction already reserved that unit.

`holds_reservation` says which, explicitly. Never infer it: releasing a
reservation nobody took overstates available stock.

**Never create a hold without an expiry.** One abandoned checkout would take a
product off sale permanently.

### A settlement carries no discount

Consumed credits reduce a Buy Now price. They bought the winner the win and do
not also reduce what winning costs. A CHECK constraint refuses a settlement
order with a discount on it.

### Credits are never charged at checkout

They were consumed at bid time. No checkout or payment path posts a credit
transaction in either direction — not a charge, and not a refund.

### One successful payment, one sale

Three routes hand the product over and each produces exactly one inventory
sale. If you add a fourth, it goes through `InventoryService` like the others,
inside the same transaction, after verification.

### Paid orders are historical fact

Amounts, source, auction, winning bid and customer freeze the moment a payment
is verified — model guard and database trigger both. A correction is a separate
financial act with its own records, never an edit.

### When a payment succeeds but nothing can be delivered

Record it: `Paid`, plus `fulfilment_blocked_reason`. Do not mark it fulfilled,
do not swallow it, and do not invent a refund. It goes in the admin queue for
a person.

## Product price, credits and bids are separate

Credits spent bidding do **not** determine what anyone pays. A product's
`buy_now_price_minor` is its own figure in GHS pesewas, and is never derived
from a bid, a balance, or a credit package price. Never subtract bids from it,
never update it as bidding proceeds, and never express a product price in
credits.

The only relationship is the Buy Now discount described above, which computes
against the price without changing it.

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

Two traps worth knowing, both discovered the hard way:

`Http::fake()` **appends** stubs and the first match wins, so faking again
inside a test does not override a `beforeEach` stub. Use `fakeHttp()` /
`fakePaystackVerify()` from `tests/Pest.php`, which swap the factory and
genuinely replace them.

**Resolve anything that talks to a provider after the stubs are installed.**
The Paystack gateway is constructed with the HTTP factory injected into it, so
a service resolved in `beforeEach` keeps the real factory and tries to reach
Paystack for real. Same for `config(['paystack.secret_key' => ...])`, which the
gateway reads when it is constructed.

## Before finishing any change

```bash
./vendor/bin/pint
./vendor/bin/phpstan analyse --memory-limit=512M
php artisan test
```

PHPStan needs the raised limit: the default 128M is no longer enough for this
codebase and the worker crashes rather than reporting anything useful.
