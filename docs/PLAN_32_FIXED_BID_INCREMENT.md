# Stage 32 (revised) — Cumulative standing with a fixed catch-up bid

Status: **proposal for review. Nothing in this plan is implemented.** The only code
change made alongside it is the admin field *label*, renamed "Minimum increment" →
"Bid increment"; its column, validation and behaviour are untouched.

This **supersedes** the "maximum bid increment" design in
`PLAN_31_UX_BIDCAP_SHARING.md`. An exact step makes a cap on the size of a jump
redundant.

It also **supersedes the first version of this plan**. That version kept today's
rule (a bid consumes its whole amount) and showed the consequence: under forced +1
steps, credits consumed grow with the square of the final price. The decision (D-1)
was to change the model instead, as below.

---

## 1. The decision, in plain terms

> A bidder's position is their **cumulative credits consumed on that auction**. To
> overtake the leader they must consume enough additional credits to exceed the
> leader's cumulative total. **The server calculates that bid; the bidder does not
> choose it.**

Concretely (variant "A", confirmed):

```
standing        a bidder's total credits consumed on this auction
leader          the bidder with the highest standing
opening bid     minimum_bid_credits
catch-up bid    leader's standing + increment − your standing     (you land exactly one increment ahead)
winner          the leader's standing when the auction closes
```

Worked example, minimum bid 1, increment 1, two bidders:

| Bid | Bidder | Standing before | Leader | Bid (credits added) | Standing after |
|---|---|---|---|---|---|
| 1 | A | 0 | none | **1** (opening) | 1 |
| 2 | B | 0 | A: 1 | 1+1−0 = **2** | 2 |
| 3 | A | 1 | B: 2 | 2+1−1 = **2** | 3 |
| 4 | B | 2 | A: 3 | 3+1−2 = **2** | 4 |
| 5 | A | 3 | B: 4 | 4+1−3 = **2** | 5 |

Minimum 5, increment 2: A opens with 5; B bids 7; A bids 4 (→ 9); B bids 4 (→ 11).

Each bid still consumes exactly the credits it adds, permanently. What changes is
what *ranks* a bidder: the running total, not the size of one bid.

## 2. This replaces a locked rule, deliberately

Today the highest **single** bid wins. `HighestBidResolver` says so in its docblock
("not … the largest total committed across several bids"), and `HighestBidTest` opens
by listing "the largest total committed" as a plausible alternative that must fail
loudly. This plan makes the largest total the *rule*. That is a business decision,
not a refactor, and it is recorded here as such.

`CLAUDE.md` ("The auction model", "Bids carry their own amounts", "Which credits earn
the discount") must be rewritten when this is approved, and `HighestBidTest` is kept
but scoped to legacy auctions with a mirror test for the new rule.

**A finding that shapes the design.** Every snapshot already records `winner_rule`,
but the engine never *reads it back*: `AuctionRules::winnerRule()` returns the class
constant `WINNER_RULE`, and `fromArray()` ignores the stored value. So today the
snapshot cannot switch winner rules. Making it able to is part of this work.

## 3. Economics: what the change does

Total credits consumed to reach a final standing of P, increment 1:

| Reaches | Old model (bid consumes full amount) | This model, 2 bidders |
|---|---|---|
| 20 | 210 | 39 |
| 100 | 5,050 | 199 |

Each bid after the first adds about `bidders × increment` credits (three bidders
rotating: about 3 per bid), so spend scales with competition and roughly linearly
with the price, not quadratically. Consequences in machinery that does not change:

- **Store Wallet.** A loser is issued the value of all their consumed purchased
  credits. At the illustrative 9 pesewas per credit, a loser at a price of 100 is
  owed about GH₵9.00, against about GH₵229.50 under the old model.
- **Buy Now discount.** Already computed from credits a user consumed on that
  auction, which is now exactly their standing. No change.
- **Winner's cost.** The winner has consumed their own standing, so what they paid
  in credits is the "highest" figure they can see. Predictable, and no double-spend.

These follow from the rule as stated. Whether the resulting economics are right for
the business is yours to judge; I have not modelled acquisition rates or margins.

## 4. Blast radius

Two different quantities are currently both called "the bid amount", and the change
is mostly separating them:

```
amount_credits       what THIS bid consumed          unchanged, still the ledger fact
standing             where that leaves the bidder    new; cumulative_credits on the bid row
```

Sites that mean *what was consumed* keep `amount_credits`. Sites that mean *the
figure that leads or won* switch to the standing.

| Area | What changes |
|---|---|
| **Bid record** | New nullable `bids.cumulative_credits`, written at insert under the auction lock and never updated (append-only triggers stay). Populated only for new-model auctions. Legacy bids are not touched. |
| **Resolver** | `HighestBidResolver` (`highestBid`, `highestAmount`, `highestBidForUpdate`, `rebuild`, `verify`) ranks by `cumulative_credits` for a new-model auction and by `amount_credits` for a legacy one, chosen by the auction's frozen rule. New index `(auction_id, cumulative_credits DESC, sequence)`. |
| **Projection** | `auctions.highest_bid_credits` holds the ranking value of the leading bid (the leader's standing). Same guarded, rebuildable, report-never-repair cache. |
| **PlaceBid / BidValidator** | Computes `mine` and the leader **under the auction lock** and requires `amount === expected`. Lock order unchanged (auction → product → wallet → lots); reading `mine` is a bid query under the auction lock, not a new lock. |
| **Closing** | `CloseAuction` names the leader by standing; `winning_bid_id` is the bid that put them there. Ties cannot occur under an exact step; the earliest-sequence tie-break stays for legacy. |
| **Settlement / orders** | `StartSettlementCheckout`, `CheckoutPricer`, `AuctionSettlementHandoff`, `CheckoutPricing` record `winning_bid_credits` from `amount_credits`. For new auctions that must be the winner's standing. Paid orders are frozen history; old orders are untouched. Settlement price stays a separate per-auction amount and is never derived from this. |
| **Buy Now** | `consumedCreditsBy` sums `amount_credits` per user per auction, which now equals the standing. No change. A test asserts the two agree. |
| **Store Wallet** | Unchanged. It values credits consumed, from the ledger. |
| **Notifications** | `NotificationSubscriber` bid-placed, outbid and won messages quote `amount_credits`. Wording must say what happened: "Your bid of 2 credits put you in the lead at 22 credits." Credits stay a count. |
| **Realtime** | Payload whitelist unchanged. `highest_bid_credits` now carries the leader's standing. The next bid is per-user and is never broadcast. |
| **Customer UI** | Room, product-page auction panel, auction card, dashboard, admin manager and detail, and the discovery "highest bid" sort (which reads the projection, so it follows for free). |
| **Bid history** | Rows show what each bid added and the resulting standing, by participant number. Bidder identity stays hidden. |
| **Ruleset / admin** | See §7. |
| **Tests** | About 54 lines across 8 files mention the increment, and 6 files assert the winner rule string. |

**Does not change:** credit lots and their consumption order, the credit ledger,
inventory and its reservation lifecycle, payments and webhooks, refunds, delivery,
referrals, the closing sweep and its clock, extensions.

## 5. Historical integrity and the snapshot

The snapshot rule is not negotiable: an auction's rules are frozen when it is
created, and editing configuration must never change how a past auction behaved.
Reinterpreting an existing snapshot key is therefore ruled out. Staging shows why it
is not hypothetical:

| Auction | Status | Frozen rule | Bids | Highest |
|---|---|---|---|---|
| #8 | relisted | min bid 1, increment 5 (lower bound) | 2 | 26 |
| #11 | forfeited | min bid 1, increment 5 (lower bound) | 1 | 50 |
| #13 | forfeited | min bid 1, increment 5 (lower bound) | 6 | 205 |
| **#14** | **live** | no increment, no minimum | **11** | 104 |

There are 14 auctions and 45 bids, all under snapshot version 3.

**Design: one discriminator, snapshot version 4.** A single `bid_model` in the
snapshot, so an invalid combination cannot be expressed:

```
single_highest   legacy. winner_rule = highest_valid_credit_bid. Progression is
                 whatever the legacy fields say: minimum increment (lower bound) or
                 free-form when none.
cumulative_step  new. winner_rule = highest_cumulative_credits. Catch-up bid.
```

- `AuctionRules` reads `bid_model` and **derives** `winnerRule()` from it, replacing
  the constant. `winner_rule` stays in the snapshot for continuity, as a derived label.
- A migration rewrites every v3 snapshot to v4 with `bid_model = single_highest`. It
  follows the precedent of
  `2026_09_10_100200_replace_flat_buy_now_credit_discount_with_lot_valuation`: drop
  `auctions_frozen_configuration`, `JSON_SET`, recreate it, in one migration.
  **Every existing auction behaves identically afterwards.**
- **Live auction #14** keeps taking free-form bids under its frozen snapshot, and
  finishes under the old winner rule. It cannot be flipped mid-flight: its 11 recorded
  bids would be judged by a rule they were not placed under. The room therefore has
  two modes until no legacy auction is live.
- A **verification command** recomputes, for every existing auction, the leader and
  the smallest valid bid from the old and the new snapshot and reports any difference.
  It reports and repairs nothing.
- The legacy branches are never deleted while a snapshot can still need them.

**The ruleset column** is renamed `minimum_bid_increment_credits` →
`bid_increment_credits`, so it does not keep a name that says "minimum" while meaning
an exact step. Existing values carry over. CHECK constraints referencing it are
dropped and recreated with MariaDB `DROP CONSTRAINT`.

## 6. Bidding flow and the stale-click problem

```
Leader: Bidder #2 with 22 credits           Your standing: 10 credits
To take the lead you need to add 13 credits (you would lead with 23).
[ Bid 13 credits ]
```

- **Two deliberate actions stay.** The button opens the existing confirmation ("You
  are about to bid 13 credits; they are consumed immediately and not returned"); a
  second click places it. Credits are irreversible, and this is a locked rule.
- **The confirmed amount travels with the request and the server enforces it.** If
  somebody takes the lead while you are looking, your confirmed 13 is no longer the
  catch-up bid. The domain refuses it ("Somebody bid first. To lead you now need to add
  15"), the confirmation closes, the page re-reads, and **nothing is consumed**. The
  server never quietly substitutes a larger amount: that would spend more credits than
  the bidder agreed to.
- **Tampering.** A crafted request for any other figure fails the same equality check.
  The browser's display is presentation only.
- **The leader.** With `allow_bid_increase` false a leader has no bid to place and the
  page says they hold the lead. With it true, their next bid adds one increment
  (standing + increment). See D-4.
- **Affordability.** If the catch-up bid exceeds the balance, the page shows the
  shortfall and links to credit packages. Nothing is placed.
- **Legacy auctions keep the current form.** #14 renders the existing amount field,
  chosen by the frozen `bid_model`, never by anything the browser sends.

## 7. Admin

- Label **Bid increment (credits)** on the ruleset form and auction detail (done).
- Help text changes with the semantics, not before: *"Each bid must put the bidder
  exactly this many credits ahead of the leader. With a minimum bid of 5 and an
  increment of 2, the leader's standing goes 5, 7, 9 …"*
- Ruleset list and auction detail show the model: **Cumulative step**, and for history
  **Single highest (legacy)**, so anybody reading an old auction can see which rule
  governed it.
- Bounds: increment ≥ 1, minimum bid ≥ 1, whole credits. No invented ceiling.
- **Required to activate.** Enforced in `ActivateRuleset`, not in `CreateAuction`.
  Production only activates rulesets through that action, while test factories set
  status directly, so existing arbitrary-amount tests keep running as legacy-mode
  tests. How many depend on that is unmeasured; step 1 will show it.

## 8. Change plan, in order

Each step is independently reviewable and leaves the system working.

1. **Pin current behaviour.** Characterisation tests for today's rules, including that
   a null increment accepts a bid at or below the leader (my reading of
   `BidValidator`, not yet proven). The regression baseline.
2. **Schema.** `bids.cumulative_credits` (nullable) with its index; rename the ruleset
   column and recreate its CHECK constraints.
3. **Snapshot v3 → v4** with `bid_model`, plus the verification command.
4. **Domain.** `BidModel` enum; `AuctionRules` v4 (`bidModel`, derived `winnerRule`,
   `catchUpBid`, `assertValid`); `AuctionRuleset::toRules()`; resolver ranks by the
   rule's column; `PlaceBid` writes `cumulative_credits`; `BidValidator` exact
   enforcement and `BidRejected::notTheCatchUpBid`; `CloseAuction` and the settlement
   path record the standing; `HighestBidResolver::verify` also reports whether a
   bidder's standing equals the sum of their bids.
5. **Wording and UI.** Room (both modes), product panel, cards, history, dashboard,
   notifications, admin.
6. **Docs.** `CLAUDE.md`, `ROADMAP.md`, a verification runbook.
7. **Deploy.** Database backup first (the snapshot rewrite is forward-only by this
   repository's own convention). Maintenance mode for the seconds between the file
   swap and `migrate`: after the migration the code refuses v3 snapshots, so a bid
   arriving in that window would fail. Then run the verification command.

## 9. Tests to add

- **Catch-up bid.** Every row of the §1 table; opening bid must equal the minimum; any
  other amount refused with no bid row, no credit movement, no extension.
- **Ranking.** Largest standing wins even when another bidder placed the largest single
  bid; the mirror of the legacy "largest total committed must not win" test.
- **Concurrency (MySQL, real locks).** Two challengers compute the same catch-up
  against the same leader: one commits, the other is refused with nothing consumed.
- **Stale confirmation** refused and closed, page shows the new amount. **Tamper**
  (amount set by a crafted component call) refused.
- **Invariant.** For every bidder, the standing on their last bid equals the sum of
  their `amount_credits` and equals what `consumedCreditsBy` returns, which is what the
  Buy Now discount uses.
- **Leader and `allow_bid_increase`,** both values. **Insufficient balance.**
- **Snapshot.** v4 round-trip; v3 refused; a migrated auction rebuilds identically;
  `winnerRule()` follows `bid_model`; an invalid combination is unrepresentable; the
  frozen-configuration trigger returns after migration and still refuses edits.
- **Legacy.** A `single_highest` auction still accepts free-form bids and still picks
  the highest single bid; the existing suite stays green as the proof.
- **Settlement.** A new-model win records the winner's standing; a legacy win records
  the winning bid's amount; settlement price is independent of both.
- **Store Wallet and Buy Now** unchanged: the existing suites are the regression.
- **UI.** Cumulative room shows catch-up and no text field; legacy room shows the field;
  history shows added credits and standing by participant number and names nobody;
  broadcast payload keeps its seven fields; admin requires the increment to activate.

## 10. Invariants that must hold

Server decides; validation inside the transaction after the auction lock; a bid never
exists without its credit consumption; a rejected bid leaves nothing; bids stay
append-only, and `cumulative_credits` is written once; credits are never refunded by a
bid; the projection is a rebuildable cache and verify reports rather than repairs; a
frozen snapshot never changes meaning; `AuctionRules` stays `readonly`; lock order is
untouched; the broadcast whitelist and polling are untouched; credits are always
rendered as a count, never as money.

## 11. Decisions still open

| ID | Decision | Recommendation |
|---|---|---|
| ~~D-1~~ | ~~Consumption model~~ | **Decided: cumulative standing, catch-up bid (variant A).** |
| D-2 | Is a minimum bid required alongside the increment? It is the only non-invented opening bid. | Require both to activate a ruleset. |
| D-3 | Staging rulesets #1 and #3 (no increment) and #2 (increment 5): leave active but flagged "legacy", or archive and re-create? | Leave, flag as legacy; an admin versions them. |
| D-4 | A leader with `allow_bid_increase = true` may add one increment to their own standing. Keep, or never let a leader bid? | Keep; it is the existing rule and is harmless. |
| D-5 | Keep the two-step confirmation behind "Bid 13 credits". | Keep. Credits are irreversible. |
| D-7 | **Wording.** "Highest Bid (Credits)" is a locked label, but the number is now the leader's *total*. Rename (e.g. "Leading total (Credits)") or keep? | Rename for new-model auctions so it cannot be read as one bid. Your call. |
| D-8 | Should a bidder see other participants' standings in the history, or only the leader's? Participant numbering already makes them derivable. | Show them; they are the game state, and identity stays hidden. |
