# Engineering conventions — As-Is-Commerce

A credit-based auction marketplace for Ghana. Read `README.md` for setup.
This file records the rules that are not obvious from the code.

## Current stage

**A customer marketplace over a finished engine, now operable by a person.**
Auctions run themselves, customers are told what happened, money that could not
be delivered against can be given back, everything else is packed and delivered
by hand, and the whole of it is presented as a shop somebody can actually use,
with a narrow referral programme on top that pays in ordinary credits. Over all
of it sits an operations layer: one dashboard counting what is true from the
records, one exception list holding everything that needs human judgement, one
search box, a support view of a customer, and the activity log made visible.
Every one of those screens reads and routes; the actions stay where the records
live. Foundation (auth, roles,
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

What must not be built ahead of its stage: disputes and chargebacks, physical
returns and reverse logistics, courier and driver integration, automated
tracking, shipping pricing, a tax engine, gamification, automated
compensation, and real-time delivery (Redis, Reverb, WebSockets).

**A paid order that could not be completed is recorded with
`fulfilment_blocked_reason` and queued for a person.** Nothing refunds it
automatically -- not a webhook, not a sweep, not the fulfilment path that
recorded it. An administrator decides, and the Refunds section below governs
what happens then. What is owed is still a business decision, never inferred.

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

Broadcasting is wired but switched off: `BROADCAST_CONNECTION=null`, because
Hostinger states Redis is unavailable on the Web and Cloud plans this deploys
to, and those plans run cron tasks rather than persistent daemons. The
application can broadcast (`pusher/pusher-php-server` is installed and speaks
Reverb's protocol); the Reverb *server* and Redis are not installed, because
running them makes a VPS a requirement rather than a choice.

Horizon is not installed. Do not add packages that duplicate something the
framework already provides.

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

## The customer marketplace

The presentation layer, and nothing more. It displays what the domain decided;
it decides nothing itself.

### Availability is not `availableStock()`

A live auction reserves the unit it is selling, so an auctioned product has
**zero available stock by design**. Asking the catalog alone would print
"currently unavailable" on something anybody can bid for this minute.

`ListingAvailability` answers the question properly: when an auction holds the
unit, the auction's state decides; otherwise `Product::isPurchasable()` does.
Never call `isInStock()` from a listing or a product page — that is the trap
this layer exists to avoid, and there is a test that reproduces it with a real
reservation.

### Nothing stale reaches a card

Only a **currently relevant** auction — live, closing or scheduled — is passed
to a card. A finished auction says nothing about present availability and its
highest bid must never appear. `ProductDiscoveryQuery::availabilityFor()`
resolves this for a whole page in one query; do not fetch per card.

### The two quantities are rendered by different components

- `x-money` renders GH₵ from a `Money` object.
- `x-credits` renders a count.

Never render a credit figure through `x-money`, and never write GH₵ in front of
one. On cards the locked label is **Highest Bid (Credits)** — naming the unit
in the label is what stops the number beside it reading as a price. "Auction
price" must appear nowhere.

### Read models, not queries in views

`app/Domain/Marketplace/Queries/` holds `ProductDiscoveryQuery`,
`AuctionDiscoveryQuery` and `CustomerDashboardQuery`. They are read-only: no
writes, no decisions, no caching, no second source of truth. A page that needs
data asks one of them rather than building a query in a component or a Blade
template.

Search terms are trimmed and length-capped before they reach a LIKE.

### The dashboard never sums the two credit figures

Available credits are in the wallet and can be bid with. Committed credits have
been consumed on bids and are gone. A combined number would be half spendable
and half spent, and would mean nothing. Both are shown, labelled, and the
second says plainly it is not coming back.

### Bidding takes two deliberate actions

`review()` validates the shape of the amount and opens a confirmation showing
what it costs and that the credits go immediately. `bid()` places it. Every
business rule is still the domain's, checked against a locked auction row — so
a confirmation left open cannot commit credits against state that has moved on,
and a refused bid closes the confirmation and re-reads the auction.

Do not move any of that logic into the component, and do not compute a minimum
bid or a discount in Blade or JavaScript. `BidValidator` and `BuyNowPricer` are
the answers; the page displays them.

### The countdown decides nothing

It is a number the server worked out at render time. An auction ends when
`ends_at` says so and the sweep notices. When a countdown reaches zero the page
must not announce an outcome — it has no way of knowing one.

"Ending soonest" is ordered by `ends_at`, never by anything a browser computed.

### Customer-facing status wording

`AuctionStatus::customerLabel()` and `DeliveryStatus::customerLabel()` exist so
engine vocabulary stays out of customer screens. A settled auction that ended
through Buy Now is labelled by `x-auction-status-badge`, which knows to say
"Sold via Buy Now" — the status alone cannot tell.

No bidder is ever named to another bidder, and no Buy Now buyer to anybody.

### An auction owns the Buy Now path for its unit

A product with a live auction sends Buy Now to the auction page rather than
opening a plain checkout: the auction's price carries the bidder's credit
discount, and completing it ends the auction. Two checkouts on one unit would
be wrong in both directions.

### SEO

The layout emits title, description, canonical, Open Graph and — where a page
supplies it — structured data. Signed-in pages carry `noindex`. Structured data
is truthful only: availability follows the same authoritative reading the page
shows a human, and nothing invents a rating or a review count.

### Do not build here

A CMS, blog, coupons, referrals, loyalty, reviews, recommendations,
personalisation, analytics, or any gamification beyond the auction mechanism
itself. This layer improves usability; it does not change economics.

## Fulfilment and delivery

Delivery is manual. There is no courier integration and none is coming in this
stage — do not add one.

### Five concepts, never collapsed

Payment, order, fulfilment, delivery, refund. An order stays `Processing`
through every delivery state up to `Delivered`, because the box moving is not a
commercial event. Collapsing any two would mean staff carrying a package
appearing to make a statement about money.

### Manual does not mean ungoverned

`DeliveryLifecycle` is the only thing that writes `deliveries.status`. Every
move is checked against `DeliveryStatus::allowedTransitions()`, authorized,
recorded in `delivery_transitions` with an actor, and applied under a lock.

Never let a browser set a status. Never add a second path that moves a package.

The history table matters more here than anywhere else on the platform: a
manual process has no provider to ask afterwards what happened, so those rows
are the only account. Triggers refuse updates and deletes.

### What a delivery must never do

Mark an order paid, create or alter a payment, refund anything, move a credit,
post an inventory movement, or change an auction result. There is no method for
any of it, and the domain does not import the payment gateway. Keep it that
way.

A failed or cancelled delivery changes nothing financial. If money is owed it
goes through the refund workflow, which has its own permission and its own
record.

### The address is copied, never referenced

`addresses` is the customer's book; a delivery holds its own frozen copy. A
trigger refuses to let that copy change once the status leaves `pending`.

Never make a delivery read the address book at render or dispatch time. The
whole point of two tables is that editing an address cannot redirect a package
that has already gone.

The address may still be supplied while `pending` — that is the auction
winner's path, since their order is created by the closing sweep with nobody at
a keyboard.

### Creation

`OpenDelivery` runs from `FulfillOrderPayment`, through the `FulfilmentHandoff`
interface so the orders domain does not depend on the delivery domain. One
order, one delivery, enforced by a unique index. A blocked order gets none.

Do not create deliveries at checkout: an abandoned cart is not work.

### Order completion

`Delivered` moves the order to `Fulfilled` through `OrderLifecycle::apply()`,
in the same transaction, so the order's own guards still apply. Never write
`orders.status` from the delivery domain directly.

### Lock order

```
1. the order row      SELECT ... FOR UPDATE
2. the delivery row
```

Appended after everything financial. A delivery reaches no auction, product or
wallet.

### Idempotency

A move to the status a delivery is already at does nothing: no history row, no
order change, no notification. That is what makes a double-click and two staff
pressing the same button harmless. Preserve it.

### Wording

Never say dispatched before a dispatch is recorded, or delivered before a
handover is. Never accuse a customer of a failed delivery, and never offer a
refund in a delivery message — that decision belongs to somebody else.

### Do not build

Courier APIs, driver accounts or apps, GPS, route optimisation, automated
dispatch, delivery commissions, shipping pricing, zones, weights, automated
retries, or physical returns and restocking.

## Referrals

A controlled growth layer over the credit ledger. Not a promotions engine, and
not a second financial system.

### Referral credits are ordinary credits

They enter through `CreditLedgerService::addCredits` as `ReferralCredit`, land
in a lot whose source is `CreditLotSource::Referral`, and are consumed in the
ledger's own deterministic order like everything else. Stage 3 already defined
both, including the consumption priority.

There is **no referral balance, reward balance, bonus balance or promo wallet**,
and there must never be one. `referrals.credit_transaction_id` is a pointer into
the real ledger, not a copy of it. A test asserts no such column or table
exists.

### What qualifies, precisely

A verified successful payment, on an order that reached `Paid`, which is **not
fulfilment-blocked**, made by the referred customer.

Each clause excludes something. Registration alone earns nothing. An
initialised or failed payment earns nothing. A cancelled or expired order never
qualifies even when a payment later succeeds against it. And a blocked order
does not qualify: the money is real but the platform owes that customer an item
or a refund, and paying a referrer for it would reward a transaction the
business could not complete.

`Paid` rather than `Fulfilled` was a genuine ambiguity, resolved deliberately —
waiting for a manual delivery would make a reward depend on warehouse timing and
on the customer supplying an address, neither of which says whether the purchase
was real. See `ReferralProgramme`'s class comment.

### One of everything, enforced by the database

- One referrer per customer: `referred_user_id` is **unique**.
- One reward per referral: `credit_transaction_id` is **unique**.
- No self-referral: a CHECK constraint, as well as the application check.
- The relationship cannot be reassigned: a trigger refuses any change to the
  pair or the code used.
- An issued reward cannot be revalued, repointed or deleted: another trigger.

Application checks exist too, but the database is what makes these guarantees
rather than conventions.

### The reward amount is snapshotted

Read from settings once, at the moment of issue, and written onto the referral.
Never recompute a historical reward from today's setting — the ledger would
disagree, and the customer's balance is the honest record.

### Nothing is ever clawed back

There is no clawback method, and there must not be one. If a qualifying purchase
is refunded, or a referral turns out to be fraudulent, the credits stay — they
may already be spent on bids that cannot be unwound. An administrator can refuse
a referral *before* it is paid; afterwards, the honest record is that it was
paid. Reversal policy is a future financial stage.

### Two steps, so a failure is recoverable

`qualify()` records that a real purchase happened. `reward()` issues the
credits, and may legitimately decline — cap reached, programme off, no amount
configured. A declined reward leaves the referral at `Qualified`, which is
retryable and is what `referrals:reconcile` surfaces. Never collapse these.

### Reconciliation reports and never repairs

`referrals:reconcile` must never issue a credit. A command that granted what it
thought was missing would mint credits on every run of a buggy qualifying rule.
It reports and exits non-zero.

### Settings and permissions

`referrals_enabled`, `referral_reward_credits`,
`referral_max_rewards_per_referrer` (zero means no cap, stated explicitly).

`referrals.view`, `referrals.manage`, `referrals.settings` — staff only. A
customer sees their own referrals because the query is scoped, not because of a
permission.

### Privacy and wording

A referrer is never told who they referred — only that somebody joined and what
it earned. Rewards are always a count of credits, never a cedis figure, and no
copy anywhere promises income, earnings, commission or a payout.

### Attribution is at registration only

A code in the query string, resolved on the server. No cookie, no session, no
tracking window — the simplest safe design, and the one the brief prefers. A
mistyped code attributes nothing and must never break registration.

### Do not build here

Loyalty points or tiers, coupons, promo codes, cashback, cash commissions,
withdrawals, customer-to-customer transfers, marketing campaigns, or fraud AI.

## Refunds

Money the platform received and gave back. Nothing else.

### A refund is a separate event, never an edit

After a full refund the `order_payments` row still reads `success` for its
original amount, because that is what happened. Two questions, two answers:

```
What was originally paid?   order_payments.amount_minor
How much was refunded?      SUM(refunds.amount_minor) WHERE status = succeeded
```

Rewriting the payment would destroy the first answer to store the second. A
database trigger refuses any change to a refund's amount, currency, order or
payment, and refuses to reopen a settled one — a retry is a new refund with its
own row, so a failure is never overwritten by the success that followed it.

### Three things a refund never does

- **Returns no credits.** Bid credits are consumed permanently and stay
  consumed through every outcome, refunds included. The `refunds` table has no
  column that could express one, and there is a test asserting that.
- **Restores no stock.** Whether an item is back on the shelf is a physical
  question. Reverse logistics do not exist here.
- **Reopens no closed order.** An order that was cancelled or expired keeps the
  status it closed with. The refund record says the money went back; the order
  still says why it closed.

### Nothing reaches `Succeeded` on our say-so

Paystack settles refunds asynchronously. An accepted request is `Processing`
and nothing more; only the provider's own terminal status, fetched
server-to-server, produces a success. A status the code does not recognise is
treated as still in flight, never as money returned — being wrong in that
direction means telling a customer their money is back when it is not.

`refunds:reconcile` is what later asks. There is no timeout after which the
platform assumes a refund completed.

### Two steps, and the row comes first

`RequestRefund` writes the refund `Pending` and commits. `ProcessRefund` then
calls the provider. If they shared a transaction, a failed call would roll the
attempt away and nothing would show that somebody tried to return money.

`ProcessRefund` **does not throw** when the provider says no. A rejection, an
unreachable host or a mismatched answer is recorded as a failure with a reason
and left in the queue. Nothing retries by itself.

### What may be refunded

Only an order whose fulfilment is blocked — the three cases Stages 7 and 8
recorded and left open. A healthy paid order is not refundable (the sale
stands, and a refund restores no stock), and a delivered one is a return.

Widening this is a business decision with an inventory consequence. Do not slip
it in behind a button.

### The amount is computed, never supplied

```
refunded    = succeeded refunds                     what a customer is shown
refundable  = payment − succeeded − in-flight        what a new refund may be
```

In-flight refunds count against the cap. Subtracting only succeeded ones would
let two attempts for 70% of a payment coexist, and both settling would return
140% of what came in. A failed attempt releases its share again.

`RequestRefund` takes the **order row lock** first and computes everything
under it. That is what makes over-refunding impossible rather than unlikely.

### Lock order

```
1. the order row      SELECT ... FOR UPDATE
2. the payment row
```

The established sequence — order, auction, product, wallet — with the payment
taken directly after the order. A refund reaches no auction, product or wallet.

### Reconciliation detects; it does not repair

`RefundReconciler` reports unconfirmed successes, untracked refunds, amount and
currency disagreements, over-refunds and stalled attempts. It changes nothing,
for the same reason the credit ledger's reconciliation changes nothing: an
automatic repair is a guess about which of two disagreeing records is right,
made by the code whose bug may have caused the disagreement.

### Permissions

`refunds.view`, `refunds.request`, `refunds.process`, `refunds.retry`,
`refunds.inspect` — five, because seeing what is owed, deciding to give it
back, and sending it are different acts. No customer holds any of them.

### Wording

Never say a refund completed before the provider has confirmed it. Never
promise a timeline — the platform does not control when a bank posts a credit.
On an auction-linked order, say plainly that Credits stay consumed, or a refund
notice reads like a reversal of everything.

## Notifications

Notifications are informational and never authoritative. Nothing in the
application reads one to decide anything.

### Two rules, and everything else follows

**Dispatch after the commit.** Never inside a transaction. A notification
written inside one that later rolls back describes an event that did not
happen; one that throws takes the transaction with it. Every dispatch point
sits after `DB::transaction()` returns — check that before adding a new one.

**Never throw into a caller.** Handlers are wrapped and failures are logged.
If you add a listener, wrap it the same way. A failure to describe an event
must never look like a failure to do it.

There is a suite that replaces the dispatcher with one that throws on
everything and proves bids, closures, payments, settlements and the clock all
still complete. Keep it passing.

### Idempotency is the database's job

Every notification carries an `event_key` derived from the business event and
its recipient. **Never derive it from the message text** — wording changes
without the event changing. A unique index does the deduplicating, not an
application check that two simultaneous webhook retries could both pass.

### Wording

Three things to get right, because they are the three easiest to get wrong:

- Credits are a count: "180 Credits", never "GH₵180".
- A settlement is its own GH₵ figure. Never say a bid was converted into it.
- A blocked payment promises nothing. It says the money arrived and somebody
  is looking, never that a refund is coming -- one may follow, once a person
  decides, and the customer hears about it then.

### Refund messages say only what has happened

A refund that has been recorded but not sent tells the customer nothing: the
provider has not been asked, so nothing has happened yet. A refund in progress
is described as in progress. Only a provider-confirmed refund is described as
done, and no message anywhere promises a timeline.

### Never offer a credit back

Not to a losing bidder, not to a forfeited winner, not to a blocked order, and
not to a refunded one. Bid credits are consumed permanently and a refund of
money returns none of them -- on an auction-linked order the message says so
explicitly, or it reads like a reversal of everything.

### Preferences

Transactional notifications cannot be switched off. `NotificationPreferences`
enforces this rather than each caller remembering it, and
`NotificationType::isTransactional()` decides which are which. If you add a
type that concerns money or an obligation, mark it transactional.

### Privacy

A bidder is never named to another bidder. A Buy Now buyer is never named to
the people they outbid. No contact details on the staff screen.

### Two types deliberately absent

"Auction ending soon" and "settlement deadline approaching" are **not built**.
Both need a threshold nobody has decided, and seeding one would make the
decision by default — the same reason `minimum_bid_credits` is null. Do not add
either until the business chooses the value.

## The live auction transport

Polling is the transport. Broadcasting is an enhancement on top of it, and the
current production deployment runs without it.

```
Browser ── HTTP/Livewire ──► Laravel ──► MySQL          authoritative
   ▲                            │
   │                            │ domain event, after commit
   │                            ▼
   └───── WebSocket ◄──── AuctionBroadcastSubscriber ──► Reverb
```

**A Reverb or Redis outage is not a commerce outage.** Bids, closures, Buy Now,
forfeiture, settlement, payments and inventory are decided in MySQL under row
locks and committed before anything is broadcast. The worst a broken transport
can do is leave a page updating on its poll interval.

### Broadcasting is off by default

`BROADCAST_CONNECTION=null`. Hostinger Premium runs scheduled cron tasks, not
persistent processes, so there is no Reverb server to broadcast to and the
application attempts nothing. Turning it on is a deliberate act on
infrastructure that can host the server.

`laravel/reverb` is deliberately **not** installed. It is the server, it cannot
run on the current plan, and installing a daemon that cannot start would be
documentation pretending to be infrastructure. The application broadcasts
through `pusher/pusher-php-server`, which speaks the same protocol.

### The transport is a subscriber, never a flag on the domain events

`AuctionBroadcastSubscriber` listens to `BidAccepted`, `AuctionClosed`,
`AuctionSoldViaBuyNow` and `AuctionForfeited`, and reduces each to a public
payload. The domain events themselves are untouched.

This is not decoration. Making `BidAccepted` implement `ShouldBroadcast` hands
delivery to Laravel's dispatcher, and a broadcaster that throws then throws
through `PlaceBid::handle()` — giving a bidder a 500 for a bid whose credits
were consumed and whose row is committed. On a `sync` queue that is not
hypothetical. The subscriber is wrapped exactly as `NotificationSubscriber` is,
so a transport failure ends in a log line.

It is also why no file under `app/Domain/Auction/` changed to add real-time. A
bid does not know it is being broadcast, and it must not learn.

### The payload is a whitelist

`AuctionStatePayload` names seven fields and builds them explicitly. Nothing is
serialized — `BidAccepted` carries a `Bid`, which relates to a `User`, and a
default serialization would put a bidder's identity on a public channel in one
line.

```
auction_id  status  highest_bid_credits  bid_count  ends_at  sequence  extended_by_seconds
```

**Never add:** a bidder's name, id, phone, email or address; a wallet balance,
credit lot or credit transaction; an order number, payment reference or payment
detail; the settlement amount a named person owes; the identity of a Buy Now
buyer; delivery, refund, referral or notification content. A screen that needs
any of that reads it over HTTP, where authorization applies.

`highest_bid_credits` is a **count**. Not money, never through a money
formatter, never with a currency symbol. The settlement amount and the Buy Now
price are separate cedis figures and neither belongs on this channel.

### The channel is public, and that is the point

`auction.{id}`. Everything on it is already visible to anyone who opens the
page, so a private channel would add an authorization round trip that protects
nothing. What keeps it safe is the payload whitelist, not the channel type — so
review the whitelist, not the channel, when adding a field.

No `routes/channels.php` exists, because a public channel needs no
authorization callback. That is the minimum required configuration, not an
omission.

### The client replaces state; it never accumulates it

A message says "here is the public state now", never "add one bid". That is
what makes a duplicated delivery harmless: applying the same state twice leaves
the same state.

`resources/js/auction-stream.js` holds the ordering rules, and
`resources/js/auction-stream.test.mjs` proves them (`npm run test:js`):

1. Once an auction has ended, nothing more is applied.
2. A terminal message is always applied — no bid produced it, so it carries no
   sequence, and an ended auction is its own ordering.
3. A sequence at or below the one already seen is discarded. This is the
   duplicate case and the out-of-order case at once.
4. Anything strictly newer is applied and becomes the high-water mark.

`sequence` is the per-auction bid sequence, allocated under the auction row
lock, so it is monotonic without a counter of its own.

### Reconnection re-reads; it never replays

A dropped socket means messages were missed and there is no way to know how
many. The client asks the server for current state — one round trip, complete
answer. Replaying a buffer would answer partially and invite the browser to
reconstruct auction facts, which is the one thing it must never do.

### Polling stays

Never remove `wire:poll`, and never widen the Stage 15 cadence to compensate
for having a socket. The page must work with JavaScript disabled, with Echo
failing, with Reverb down and with a tab that slept — and polling answers all
of them identically, because a poll is a fresh authoritative read carrying no
assumption about what came before it.

## Queued work

Nothing financial is ever queued. Bids, credit consumption, Buy Now
acquisition, inventory movement, payment verification, order transitions,
auction closure, settlement, refunds and delivery transitions all stay
synchronous and transactional. There is no job for any of them and there must
not be.

`SendNotificationEmail` is the only job in the application. It carries a message
about something that has already committed, and it can fail without any of that
becoming untrue.

It is **off by default**, behind `notifications.queue_mail`. Queued mail needs a
running worker for anybody to hear anything, and the production target runs cron
rather than daemons — so a deployment without a worker must keep sending inline
rather than going quiet. Turning it on is a deliberate act taken once a worker
cron exists.

If you add a job, it may only ever carry communication or presentation work. A
job that decides something is a bug.

## Scheduled sweeps and their overlap locks

Every scheduled command carries an **explicit** overlap expiry, from
`App\Support\ScheduleLocks`. Never call `withoutOverlapping()` bare.

`withoutOverlapping()` defaults to a 24-hour mutex and releases it early only
through POSIX signals, guarded by `extension_loaded('pcntl')`. Development is
native Windows, where pcntl does not exist, and every sweep uses
`runInBackground()` — which releases the lock by appending `schedule:finish` to
the spawned command, and therefore releases nothing if that process is killed.
The lock lives in the cache store, which is `database`, so it survives
restarts.

With the default, one interrupted sweep stops auctions starting, closing,
choosing winners, opening settlement checkouts and forfeiting — silently, for a
day. That was a real defect, found in the Stage 15 infrastructure audit.

```
SWEEP_MINUTES     = 5    auctions:tick, orders:expire-checkouts
RECONCILE_MINUTES = 30   refunds:reconcile
```

The values come from what each command does, not from one blanket number. The
five-minute sweeps are bounded at 200 records and touch no provider.
`refunds:reconcile` waits on Paystack — up to 100 verifications plus a report
pass, each with `paystack.timeout` to spend — so a five-minute lock would pile
runs onto a host that is already failing.

**The asymmetry that sets them.** Expiring a lock early costs one duplicated
sweep, which is safe by construction: every step re-reads its row under
`SELECT … FOR UPDATE` and returns unchanged if another run got there first.
Expiring it late costs an outage. So these err short.

## Production is cron, not a daemon

The deployment target is Hostinger hPanel, which offers scheduled cron tasks
and not long-running processes. Nothing in this application may require a
persistent worker, a daemon or a supervisor to be **correct** — only to be
faster. The scheduler is one `php artisan schedule:run` cron entry, and
auctions close late rather than wrongly if it is delayed.

Redis is unavailable on Hostinger Web and Cloud plans. Cache, sessions, queue
and the scheduler mutex are therefore MySQL, and must stay that way unless the
platform moves to a VPS.

## Operations and administration

The admin area is a **control surface over the domain, never a second
implementation of it**. Every action calls the service that already owns the
rule: `InventoryService`, `AuctionLifecycle`, `CreditLedgerService`,
`RefundLifecycle`, `DeliveryLifecycle`, `RewardReferral`. No admin screen
writes a balance, sets a status, or decides an outcome of its own.

### The read-only screens are read-only in the strongest sense

`OperationsDashboard`, `ExceptionCentrePage`, `GlobalSearch`, `CustomerDetail`,
`CustomerIndex`, `OrderPaymentIndex` and `AuditLog` expose no public method
beyond `mount()`, `render()` and their own filter hooks. Tests assert exactly
that. If you want to add an action to one of them, the action already exists on
the screen that owns the record — link to it.

### Metrics are counted, never cached

`OperationsMetrics` counts from the table that owns each fact at render time.
There is no metrics table, no rollup and no cached counter. A counter that can
drift is worse than no counter.

`collected()` sums **successful payment attempts**, not order statuses. A
verified payment is a historical fact; an order's status moves when it is
refunded or cancelled. Summing statuses would quietly subtract every refund
from a figure labelled "collected" while `refunded()` reported the same money
again — netting the two under a label that claims not to. Keep them separate.

### The exception centre detects and reports

It never repairs, and it has **no dismiss and no acknowledge**. An exception
disappears when the situation it describes stops being true. Adding a way to
mark one handled without handling it would defeat the only purpose the screen
has, and there is a test asserting no such method exists.

It reuses `RefundReconciler` and `ReferralReconciler` rather than
reimplementing what "wrong" means. If you add a category, ask the service that
already knows.

Provider checks are **opt-in**: reconciling against Paystack is a network call
per refund, so the default render is local and there is a test asserting it
sends nothing. Never make an operations screen call a provider on every load —
it is most needed on the day that would take it down.

`ExceptionCentre::grouped()` memoizes per instance so one render sweeps the
tables once. `all()` and `counts()` both build on it.

### Search is bounded in the query object, not only on the screen

`OperationsSearch` caps the term at `MAX_LENGTH` and each kind at `PER_TYPE`,
and enforces the two-character minimum inside `search()` itself. A caller that
forgot to check must not be able to turn a lookup into an export.

Phone numbers are normalized to E.164 before matching, or the one search
support needs most silently returns nothing.

### The audit log is exposed, not rebuilt

Filtering only. No edit, no delete, no bulk action — an audit trail an
administrator can tidy is not an audit trail. Read diffs from
`attribute_changes` and deliberate context from `properties`; they are separate
columns in v5.

### Never add these

- **A "mark as paid" control**, permission or code path. What is actually
  wanted is a way to re-run verification against the provider.
- **Bulk actions** over orders, auction winners, credit balances, refunds,
  financial records or audit records.
- **Automatic repair** of anything a reconciler reports.
- **Any credential on a screen** — card details, secret keys, webhook secrets,
  OTP material, password hashes, remember tokens. Tests assert the support and
  payments screens render none of them.

### Trust nothing from the browser

Not a hidden field, not a Livewire property, not a URL parameter, not a
client-side total or status. Every figure on every admin screen is read
server-side from the records.

### Permissions

`admin.dashboard.view`, `exceptions.view`, `customers.view`, `audit.view`.
Every admin route carries a role check **and** a `can:` check, and each
component authorizes again in `mount()`. No customer holds any of them.

### Operational filters

`OrderManager` filters by delivery status — `none` means no delivery record
exists, which is deliberately distinct from one at Pending — and by placement
date, with boundaries computed in the display timezone and compared in UTC.

`AuctionManager` filters by the clock (`ENDING_SOON_HOURS`, a display threshold
and not a business rule) and by settlement state. Never filter "ending soon" by
status: an auction past its end time stays marked Live until the sweep notices.

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
