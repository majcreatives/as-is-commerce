# Plan — pot-target bidding and a display layer for Credits

**Status: planning only. Nothing in this document is implemented. No code,
migration, or staging change has been made because of it.** It exists so the
design worked out in conversation is written down, checked, and approved
before any of it is built, the same discipline `PLAN_32_FIXED_BID_INCREMENT.md`
followed for the cumulative bidding model.

**Working title only.** This is not numbered as a stage. Stage 33 in
`docs/ROADMAP.md` is already reserved for auction sharing and referral
qualification; where this work lands in the sequence, and what it is called,
is the owner's call once the design below is agreed.

## 1. The problem this solves

Stage 32 shipped the cumulative catch-up model: a bidder's position is the
total credits they have consumed on an auction, the server works out the one
valid bid that puts them a step ahead, and the largest total wins. Simulating
it at the crowd size the business actually wants — 10,000 to 100,000 users —
surfaced a real defect, not a hypothetical one: **in a busy auction, the entry
price climbs almost the instant it opens.** At 10,000 simulated bidders under
today's rule (minimum 1 credit, step 1 credit, a fixed clock), 97.5% of people
who showed up never placed a single bid, because by the time they arrived the
cost of a first bid had already climbed past what they were willing to spend.
That is the wall the owner raised at the start of this deliberation, and it is
the founding problem this plan answers.

Several other structures were simulated and rejected before this one. They are
recorded in §7 so the reasoning is not lost and nobody re-litigates a dead end
without knowing it was already tried.

## 2. The design

Two changes, together. Neither works well alone; §6 explains why.

### 2a. A real-money target on the aggregate pot, not on any one bidder

Today, an auction closes only on its clock (`auctions:tick`, `ends_at`,
optional extensions). This plan adds a second way to close: **a target
amount, in GH₵, on the sum of every accepted bid across every bidder on that
auction.** The moment the running total of everyone's spend reaches the
target, the auction closes and whoever is currently leading wins — for their
own personal spend, which stays far below the target, because many different
people's bids contributed to it.

This is deliberately **not** a cap on any one bidder's total. An earlier draft
of this idea checked the target against the *leader's own* accumulated total,
and simulation showed why that is wrong: it would require one person to
personally out-spend the target alone to win, which contradicts the owner's
stated goal that winners should spend as little as possible, and made a target
sized to a real product's price simply unreachable in every simulated run.
Checking the aggregate instead is what makes "win with as little money as
possible, and the platform still collects a meaningful amount" coherent at
once.

If the target is never reached, the auction still closes on its clock, exactly
as today. The target is a second, earlier way to close, never a replacement
for the clock.

### 2b. A finer credit unit for the catch-up step, with Credits kept as the customer-facing unit

A target alone does not help latecomers — simulated at a small target, it
just closes the door faster. What actually lets more of the crowd in is
making each catch-up step cost less, so more bids are needed to fill the same
GH₵ target, so the auction stays open longer, so more people arrive before it
closes. Simulated at 10,000 people and a target of GH₵10,000 (two times a
GH₵5,000 item, see §3), a step re-denominated to one ten-thousandth of
today's credit took the auction from a 44-minute, 30%-of-crowd result to a
131-minute run where 87% of the crowd arrived in time and nobody was priced
out.

The owner's objection to this, correctly, is that a Starter pack re-denominated
that finely becomes "1,000,000 credits" — unreadable, and wrong for a package
label. The resolution is to do for Credits exactly what this codebase already
does for money: **the raw, stored, integer ledger unit is not the same thing
as the number a customer reads.**

```
Today:    pesewas (integer, stored)  →  GH₵12.50 (displayed, via Money)
Proposed: <raw credit unit, integer, stored>  →  Credits (displayed, decimal)
```

- The database keeps counting in whole integers. Nothing about "Credits are
  BIGINT integers, never fractional" changes — that rule is about what is
  written to the ledger, and the ledger still never writes a fraction.
- A fixed divisor (the same factor that makes the step fine — see §5, D-1)
  turns a raw count into the number a customer sees, the way `Money` turns
  pesewas into GH₵.
- **Packages are defined as clean multiples of that divisor**, so a package
  purchase, the wallet headline balance, and every place a customer already
  expects a round number keep reading exactly as they do today — "100
  Credits" stays "100 Credits" no matter how fine the raw unit underneath it
  is.
- **Only while a bidder is actively mid-auction** does a decimal appear —
  "you have committed 13.84 Credits so far" — which is small and legible,
  not a wall of trailing zeros. Up to 4 decimal places, trimmed (D-3).
- The raw unit's proposed name, pending confirmation, is "subcredits" — see
  D-5. It is never shown to a customer; it exists so code and ledger records
  have a word for the raw count that is never confused with the displayed
  "Credits" figure, the way pesewas is never called "0.01 GH₵."

This is new work, not a reuse of `Money` itself (Credits and cash stay
separate systems, per the ledger's non-negotiable rules), but it is the same
*pattern*: one value object, one formatting convention, applied to a second
currency-like count that has never before needed sub-unit precision.

## 3. Where the target is set, and by whom

**The target is an explicit, per-auction, admin-entered figure — never a
formula the code derives from the product's price.** This mirrors exactly how
`settlement_amount_minor` already works: chosen at creation, frozen into the
auction, never read back from the product. Two auctions on the same product
may already deliberately settle at different prices; the same is true here —
nothing forces a target, and nothing computes one automatically.

The owner's guideline — **roughly twice the product's Buy Now price** — is a
number for the admin to have in mind when filling in the field, shown perhaps
as a read-only hint next to the product's own price (the same pattern the
auction-creation form already uses to show the Buy Now price beside the
settlement amount, so an admin can see the relationship without the code
enforcing one). It is guidance, not a constraint the form validates against.
This keeps faith with the rule that a product has no column referring to
credits or bids, and there is a test asserting that; a derivation in code
would quietly break it.

**Precise definition of "the target" (D-2, decided):** the sum of every
accepted bid's `amount_credits`, valued at the auction's own credit rate,
across every bidder including the eventual winner's own winning bid. This is
what was simulated throughout.

## 4. What stays exactly as it is

- The catch-up rule itself: `AuctionRules::catchUpBid()`, the auction-row
  lock, the validator's exact-equality check, the leader-cannot-bid rule —
  untouched. This is an additional closing condition and a finer unit, not a
  new bidding mechanic.
- `settlement_amount_minor` — the winner still pays their own, separately
  chosen, frozen settlement price. The pot target decides *when the auction
  ends and who wins*; it has no bearing on what the winner then pays to take
  the item home, and a CHECK constraint already refuses conflating the two
  kinds of figure.
- The Buy Now discount (`floor(credits × lot.acquisition_amount_minor /
  lot.original_amount)`) and Store Wallet valuation. Confirmed directly
  against the real formula in `LotValuation::forLot()`: it is a pure
  proportion between credits consumed and a lot's own recorded cost and
  size. As long as `credit_lots.original_amount` and consumed credits are
  expressed in the same raw unit — which a redenomination would do
  consistently — the formula needs no change at all. This is not a hopeful
  assumption; it was checked against the actual code before writing this
  plan.
- The single-highest model, for every auction made under it. Untouched,
  exactly as Stage 32 left it: still in the engine for the old regression
  suite and for explaining old auctions, still not offered for a new one.
- The lock order, idempotency, notification rules, Store Wallet spend rules —
  none of this plan touches payments, orders, or delivery.

## 5. Decisions

Settled with the owner on 2026-09-22. Each one records what was decided and,
where relevant, what is still open underneath it.

- **D-1 — the re-denomination factor: 10,000.** A displayed Credit is made of
  10,000 raw units. Simulated at a GH₵10,000 target and 10,000 bidders: a
  Starter pack becomes 1,000,000 raw units, 87% of the crowd got a chance to
  bid, and nobody was priced out.

  | Factor | Starter pack, raw units | Crowd that got a chance | Priced out |
  |---|---|---|---|
  | 100 | 10,000 | 30% | 28% |
  | 1,000 | 100,000 | 46% | 8% |
  | **10,000 (chosen)** | **1,000,000** | **87%** | **0%** |

  Still open: whether every ruleset must use the same factor, or whether a
  ruleset could choose a different one. Not assumed here — carried forward as
  a follow-on question if it comes up when the ruleset form is built.

- **D-2 — definition of the target: all accepted bids, including the
  winner's own winning bid.** This is what was simulated throughout §2 and
  §6. Not the narrower "losing bidders only" reading.

- **D-3 — display precision: up to 4 decimal places, trimmed.** "13.84
  Credits" for a mid-auction figure; "100 Credits" when it lands on a whole
  number, matching how a package already displays. Applies only where a
  live, in-progress figure is shown — the wallet balance, package labels and
  every other place a customer already expects a round number are
  unaffected, because they are defined as clean multiples of D-1's factor.

- **D-4 — where the target lives: always per-auction, admin-entered, no
  default.** A new column, parallel to `settlement_amount_minor`, frozen at
  creation, appearing in the rules snapshot. Never derived from the
  product's price in code, and never pre-filled from the ruleset. The
  owner's 2× guideline stays a hint shown beside the product's own price on
  the creation form, exactly as the settlement amount is shown beside Buy
  Now price today — never a validated constraint.

- **D-5 — the raw unit's name: "subcredits."** Chosen to read as obviously
  related to "Credits" while avoiding two words already loaded with other
  meaning in this codebase — "points" (too close to the loyalty-points
  concept the project deliberately does not build) and "tokens" (already
  means something specific and sensitive: API tokens, remember tokens).
  A subcredit is never shown to a customer; it is the word code and ledger
  records use for the raw integer count.

- **D-6 — the fallback clock gets its own closure reason.** When an auction
  closes because its clock ran out rather than because the pot reached the
  target, that is recorded distinctly (parallel to how `buy_now` already has
  its own `closure_reason`), so an administrator can see afterwards which of
  the two ways a given auction actually ended.

- **D-7 — a per-account bid-rate limit is included, defensively.** Simulation
  found scripts winning 0% of runs under the aggregate-pot target actually
  proposed here (versus up to 10% under the rejected leader-total version),
  so it is not fixing an observed problem — it is cheap insurance against
  real behaviour diverging from what was simulated. The limit's exact value
  is not chosen here; it belongs with the other configurable business rules
  (bid cost, duration, extension length) that are never constants in code.

- **D-8 — snapshot version moves to 5.** A direct consequence of D-1 through
  D-6 changing what is frozen into an auction at creation. Same discipline
  Stage 32 used for 3→4: refuse a snapshot version or shape it doesn't
  understand, rewrite existing snapshots under one transaction, keep the
  freeze trigger intact across the migration.

- **D-9 — the credit re-denomination happens now, on staging, by conversion
  rather than reset.** *Revised after checking the schema — see the note
  below.* This is explicitly *not* a decision about production; doing the
  same thing after real money exists is a separate, higher-stakes operation
  this plan does not authorize.

  **Why the original "just reset staging" answer was wrong.** It was offered
  on the assumption that wiping test wallets is a small, contained act. The
  schema says otherwise: `bids.credit_transaction_id` is a NOT NULL foreign
  key with a unique index, so every bid is chained to the ledger row that
  paid for it. Clearing credit data therefore cascades — credit transactions
  → the 51 bids on staging → the auctions whose projections and winners
  those bids decide → the settlement orders those auctions opened → the
  Store Wallet issuances they produced. That would destroy the 16 auctions
  staging currently uses for testing, including every Stage 32 acceptance
  case.

  **Conversion instead**, multiplying stored credit counts by D-1's factor.
  The tables involved: `credit_wallets.balance`, `credit_transactions`
  (amount and `balance_after`), `credit_lots` (`original_amount`,
  `remaining_amount`), `credit_lot_consumptions`, `credit_packages`,
  `credit_purchases`, `bids` (`amount_credits`, `cumulative_credits`),
  `auctions.highest_bid_credits`, the three ruleset bid columns, the credit
  figures inside `auctions.rules_snapshot`, and the
  `referral_reward_credits` setting.

  Several of these are append-only or frozen by database trigger —
  `credit_transactions_no_update`, `credit_lot_consumptions_no_update`,
  `bids_no_update`, `credit_lots_acquisition_frozen`,
  `auctions_frozen_configuration` — so the migration drops and restores them
  in a `finally`, exactly the pattern the Stage 32 v3→v4 snapshot migration
  already established and proved.

  **Store Wallet issuance lines are deliberately left alone.** Each records
  its own `credits` and `lot_original_amount` together, so the ratio that
  produced a past valuation stays self-consistent untouched; scaling one
  side would corrupt a historical record of money already issued.

## 6. What was tried and rejected, and why

Recorded so none of this gets re-proposed without knowing it was already
tested and found wanting. All of the following were tested with a simulated
crowd of up to 10,000, never against the real database or real users.

- **Today's rule, unmodified, at scale.** The founding problem: 97.5% of a
  10,000-person crowd priced out before ever bidding.
- **A finer step with the existing fixed clock, no target.** Fixes the wall,
  but the finish becomes a scramble: at a step fine enough for real
  participation, the leader changed on the order of a thousand times in the
  simulated final ten minutes, and nobody who was leading ten minutes before
  the end kept it in any run.
- **An uncapped ("ends when quiet") soft close**, the mechanism DealDash-style
  auctions use. At a crowd this size, with enough people still able to
  afford tiny bids, the simulated auction did not reliably go quiet within
  48 simulated hours. It also reopens two rules this codebase deliberately
  closed — no fixed cost per bid, not a last-bidder-wins outcome — and
  carries real regulatory exposure in some jurisdictions that a qualified
  opinion should assess before it is considered further.
- **A rule where a bid does not take the lead** (so a leader could genuinely
  hold, only overtaken by a real majority). Reopens "no fixed cost per bid."
  Simulated, it became a pure speed contest: a script filled the ladder in
  about five minutes and won every run, with 92% of the crowd locked out
  before they had a chance.
- **A target checked against the leader's own total**, rather than the
  aggregate pot. Unreachable once the target is sized to a real product's
  price — see §2a.
- **Lowest-unique-bid and a weighted ticket draw.** Both solve the wall
  cleanly, but both stop the result being deterministic (spend improves odds
  rather than guaranteeing a win) and both carry meaningfully higher
  regulatory exposure than anything else considered. Set aside pending legal
  advice, not developed further.
- **Splitting one product across many smaller, parallel auctions.** The one
  lever not driven by the bid rule at all — smaller live crowds behave well
  under almost any reasonable rule. Set aside because the owner's business
  model is explicitly one large auction per product, drawing on the whole
  user base at once; not rejected as a bad idea, just not the chosen shape.

## 7. Implementation sequence

Each step is its own reviewable change, deployed and confirmed before the
next, exactly as the project's standing rule requires.

**The display layer comes before the scale change, deliberately.** An earlier
draft of this section put the re-denomination first and the display layer
third. That ordering is wrong: between those two steps, every credit figure
on every screen would read ten thousand times larger — the exact "too many
zeros" problem this plan exists to avoid, live on staging for however long
the gap lasted. Reversing them means no intermediate state is ever ugly.

1. **`CreditAmount` value object and display convention, at factor 1.** A
   pure refactor: introduce the type, apply it everywhere a credit count
   renders, with the divisor set to 1 so every rendered figure is byte-for-byte
   what it is today. Fully testable, and visibly a no-op.
2. **The re-denomination.** Convert stored counts by D-1's factor (D-9) and
   set the divisor to 10,000 in the same change. Because step 1 already
   routes every display through the value object, the two cancel exactly and
   customers see the same numbers before and after — "100 Credits" stays
   "100 Credits". This is the step whose migration drops and restores the
   append-only triggers.
3. **Schema for the target:** the per-auction column (D-4), the new closure
   reason (D-6), `SNAPSHOT_VERSION` 5 and its snapshot rewrite (D-8).
4. **Engine:** pot tracking and target-closing in the close path, alongside
   the existing clock path, both converging on one close. Plus the
   per-account rate limit (D-7).
5. **Room and customer wording:** a live "committed so far" figure, the
   target explained honestly, updated notification text.
6. **Admin:** the target field on auction creation, the 2× guideline hint
   beside the product's Buy Now price.
7. **Verification runbook and staging deployment**, mirroring
   `VERIFY_32_CUMULATIVE_BIDDING.md`.

Steps 1 and 2 are the riskiest part of the whole plan — they touch every
credit figure in the application — and they are also the two with the
clearest pass/fail test: *nothing a customer sees may change*. That is worth
holding to literally, as an assertion in the suite rather than an intention.

## 8. What this plan does not do

It does not touch payments, orders, delivery, refunds, or referrals. It does
not change the single-highest model or any auction already made under it.

Every decision in §5 is settled. Two numbers are deliberately still open, and
both belong with the configurable business rules that are never constants in
code: the per-account rate limit's value (D-7), and whether a ruleset may
choose a re-denomination factor other than the platform default (D-1).

§5 and §7 were approved by the owner on 2026-09-22, which authorizes work to
begin on step 1 of §7. It does not authorize steps 2 onward, and in
particular does not authorize the re-denomination migration or any staging
deployment; each of those needs its own go-ahead when its step is reached.
