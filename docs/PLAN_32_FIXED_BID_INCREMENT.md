# Stage 32 (revised) — Fixed bid increment: architecture impact and change plan

Status: **proposal for review. Nothing in this plan is implemented.** The only
change made alongside it is the admin field *label*, renamed from "Minimum
increment" to "Bid increment" as requested; its column, validation and behaviour
are untouched.

This **supersedes** the "maximum bid increment" design in
`PLAN_31_UX_BIDCAP_SHARING.md` (Stage 32). An exact step makes a jump cap
redundant: if the next valid bid is one specific number, no larger one can exist.

---

## 1. What the rule is today (read from the code)

```
minimum_bid_credits            floor on every bid           amount >= minimum
minimum_bid_increment_credits  lower bound over the leader  amount >= highest + increment
                               (only when a standing bid exists)
allow_bid_increase             may a leader raise their own bid
minimum_bid_interval_ms        per-bidder time throttle (unrelated to amounts)
```

- **Increment set (say 1) and highest 20:** 21, 22, 30 and 500 are all valid. This
  is the lower-bound behaviour you identified.
- **Increment unset (null):** `BidValidator::assertAmountSatisfiesRules` applies the
  comparison only inside `if ($rules->hasMinimumIncrement())`. With it null, as I
  read the code, a bid at or below the standing highest is accepted and its credits
  are consumed. That is not yet pinned by a test; pinning it is step 1 below.
- **The bidder types the amount.** `AuctionRoom` holds a free-text `amount`;
  `review()` checks it is a whole number; `bid()` passes it to `PlaceBid`; the
  domain validates it against the locked auction row.
- **A bid consumes its full amount.** A bid of 21 consumes 21 credits, permanently.
  There is no per-bid fee (`bid_cost_credits` was removed with the last-bidder model).

## 2. Blast radius: where the value is and is not used

**Reads the increment (small, contained):**

| Area | File(s) |
|---|---|
| Rules value object: field, `smallestValidBid()`, `assertValid`, serialise | `AuctionRules.php` |
| Enforcement | `BidValidator.php` (`assertAmountSatisfiesRules`, `smallestValidBid`) |
| Refusal wording | `BidRejected::belowIncrement` |
| Ruleset config | `AuctionRuleset` model (fillable, cast, `toRules()`), `RulesetForm`, ruleset index |
| Display | `AuctionRoom` (`smallestValidBid`), `auction-room.blade.php`, admin `auction-detail.blade.php` |
| Fixtures | `AuctionRulesetFactory`, `AuctionRulesetSeeder` |
| Tests | 8 files, about 54 lines mention it |

**Does not read it (verified by search, nothing to change):** credit consumption
(`CreditLedgerService`, lots), the bid record and its append-only triggers, the
highest-bid projection, settlement pricing, `BuyNowPricer`, Store Wallet issuance
and spending, orders, refunds, delivery, notifications and the broadcast payload.

So the *mechanical* change is contained. The risks are in §3 (history) and §4
(economics), not in the number of files.

## 3. Historical integrity: why the meaning cannot simply be changed in place

An auction copies its rules into `rules_snapshot` when created, and the snapshot
rule is the most important invariant here: an administrator editing configuration
must never change how a past auction behaved, or a disputed result becomes
unexplainable.

**Option A, reinterpret the existing key as "exact" (rejected).** The key
`minimum_bid_increment_credits` in existing snapshots means *lower bound*. Redefining
it changes what those frozen snapshots say. On staging that is not hypothetical:

| Auction | Status | Frozen rule | Bids | Highest |
|---|---|---|---|---|
| #8 | relisted | min bid 1, increment 5 | 2 | 26 |
| #11 | forfeited | min bid 1, increment 5 | 1 | 50 |
| #13 | forfeited | min bid 1, increment 5 | 6 | 205 |

Their recorded bids were placed under "at least 5 above the leader". Re-reading the
same snapshots as "exactly 5" would make them look like rule violations, and "was
this bid valid under this auction's rules?" would stop having an answer.

**Option B, a new snapshot version that says which rule governed (recommended).**
Snapshot version 3 → 4 adds an explicit `bid_progression`, exactly as every
snapshot already records `winner_rule` rather than making the engine infer it:

```
free_form      no increment configured: any amount within the other rules   (today's null)
minimum_step   legacy lower bound: amount >= highest + minimum increment    (today's set)
exact_step     new: amount == next valid bid, computed by the server
```

- A migration rewrites every v3 snapshot to v4 with `bid_progression` set from what
  the snapshot already says (null increment → `free_form`; set → `minimum_step`, key
  retained). **Every existing auction behaves identically after migration.** This
  follows the precedent of `2026_09_10_100200_replace_flat_buy_now_credit_discount_with_lot_valuation`:
  drop `auctions_frozen_configuration`, `JSON_SET`, recreate it, in one migration.
- New auctions get `exact_step` when their ruleset has a bid increment.
- Legacy branches stay in `BidValidator` for as long as a live auction can carry one.
  On staging that is **auction #14, live with 11 bids and no increment**: it must
  keep accepting free-form bids under its frozen snapshot. It cannot be flipped
  mid-flight, or its 11 recorded bids would be judged by a rule they were not
  placed under.

**The ruleset column** (mutable configuration, not history) is renamed
`minimum_bid_increment_credits` → `bid_increment_credits`, so it does not keep a
name that says "minimum" while meaning "exact". Existing values carry over. The
CHECK constraints that reference it are dropped and recreated (MariaDB
`DROP CONSTRAINT`). One staging ruleset ("Forfeit Test", increment 5) would begin
producing exact steps of 5; it is test data and should be reviewed by an admin.

## 4. Economics: the decision that matters most

**Please read this before anything else in the plan.**

A bid consumes its *whole amount*, and that is deliberate and unchanged. Combined
with a forced +1 step, the credits consumed climb steeply. Two bidders alternating,
increment 1, minimum 1:

| Auction reaches | Bids placed | Credits consumed in total | If each bid cost 1 credit |
|---|---|---|---|
| 20 | 20 | 210 (A: 100, B: 110) | 20 |
| 100 | 100 | 5,050 (A: 2,500, B: 2,550) | 100 |

The total is `N(N+1)/2`: cost grows with the *square* of where the price ends up,
because raising the price by 1 credit costs the bidder the entire new figure.

Where this lands in existing, unchanged machinery:

- **Store Wallet.** The losing bidder is issued the cash value of *all* their
  consumed purchased credits. In the 100 example, at the doc's illustrative 9
  pesewas per credit, the loser is owed about GH₵229.50 of Store Wallet for an item
  whose auction ended at 100 credits. The platform's exposure follows consumption,
  so it grows quadratically too.
- **Buy Now discount.** Each participant's consumed credits reduce their Buy Now
  price, so a long ladder produces large discounts for anyone who climbed it.

None of that is a defect in this change. It is the existing consumption rule meeting
a new progression. But it may or may not be what you intend. Many step-auction models
charge a **fixed cost per bid** and raise the price by the increment, which is not
what this platform does. The platform deliberately removed a fixed per-bid cost
(`bid_cost_credits`) when it moved to committed amounts.

> **D-1 (needs your answer before build).** With fixed steps, should a bid still
> consume its full amount (current rule; costs escalate as above), or should the cost
> per bid be something else? Changing consumption reverses a deliberate removal, and
> touches the Buy Now discount and Store Wallet valuation. This plan does **not**
> change consumption; it only makes the consequence visible.

## 5. The new rule, precisely

```
next valid bid =
    no standing bid  →  minimum_bid_credits            (the opening bid)
    standing bid H   →  H + bid_increment_credits
```

Examples: min 1 / step 1 → 1, 2, 3 …; min 5 / step 2 → 5, 7, 9, 11 …. With highest 20
and step 1 the only valid bid is 21; 20, 22, 30, 100 and 2 are refused.

- **Server authority.** `AuctionRules::nextValidBid(?int $highest)` is the single
  definition. `BidValidator` enforces `amount === nextValidBid(...)` against
  `highestBidForUpdate` **under the auction row lock**, the same place the current
  increment is checked. The browser is never asked to compute it.
- **Ties become impossible after the opening bid.** Two people bidding 21 together:
  the first commits; the second, under the lock, sees a highest of 21, needs 22, and
  is refused with nothing consumed. The earliest-sequence tie-break stays for legacy
  auctions.
- **Opening bid.** If a ruleset has an increment but no minimum bid, there is no
  non-invented opening figure. See D-2.

## 6. Bidding UI and the stale-click problem

The bidder no longer types an amount. The page shows the server's answer:

```
Current highest bid: 20 credits
Next bid: 21 credits
[ Bid 21 credits ]
```

- **Still two deliberate actions.** Clicking "Bid 21 credits" opens the existing
  confirmation ("You are about to bid 21 credits; they are consumed immediately and
  not returned"); a second click places it. This is a locked rule (credits are
  irreversible), and the button in your mock-up is the first step, not a replacement.
- **The confirmed amount travels with the request, and the server enforces it.**
  If somebody bids 21 while you are looking at 21, your click arrives when the next
  valid bid is 22. The domain refuses it ("The next bid is now 22 credits"), the
  confirmation closes, the page re-reads, and **nothing is consumed**. The system
  must never quietly substitute 22: that would spend more credits than the bidder
  agreed to.
- **Tampering.** A crafted request for 30 is refused by the same equality check.
  Frontend display is presentation only.
- **Legacy auctions keep the current form.** Live auction #14 renders the existing
  amount field. The room therefore has two modes for a while, chosen by the frozen
  snapshot's `bid_progression`, never by anything the browser sends.
- **Real-time.** The public broadcast payload is unchanged (seven-field whitelist).
  A broadcast already triggers an authoritative re-read, which recomputes the next
  bid, and polling stays.

## 7. Admin

- Label renamed **Bid increment (credits)** in the ruleset form and on the auction
  detail screen (done).
- Help text stays as it is until the semantics change. Then: *"Every bid must be
  exactly this many credits above the current highest. With a minimum bid of 5 and
  an increment of 2, bids go 5, 7, 9, 11 …"*
- Ruleset list and auction detail show the progression: **Exact step**, and for
  history **Minimum step (legacy)** / **Free-form (legacy)**, so anybody reading an
  old auction can see which rule governed it.
- Bounds: increment ≥ 1, minimum bid ≥ 1, whole credits. No invented ceiling.
- **Requiring the fields.** Enforced when a ruleset is **activated**
  (`ActivateRuleset`), not in `CreateAuction`. Production only creates active
  rulesets through that action, while test factories set status directly, so
  existing auction tests that place arbitrary amounts keep running as legacy-mode
  tests instead of all being rewritten. How many depend on that is unmeasured; the
  step 1 baseline will show it. See D-2/D-3.

## 8. Change plan, in order

Each step is independently reviewable and leaves the system working.

1. **Pin current behaviour.** Characterisation tests: increment null accepts a bid at
   or below the highest; increment set enforces the lower bound. This is the
   regression baseline and confirms §1's null-increment reading.
2. **Schema.** Rename the ruleset column to `bid_increment_credits`; recreate its
   CHECK constraints (`>= 1`, and non-null minimum bid where required).
3. **Snapshot v3 → v4.** Migration rewrites all snapshots with `bid_progression`
   per the precedent. A **verification command** recomputes, for every auction,
   `smallestValidBid` at each recorded bid from the old and the new snapshot and
   reports any difference. Reports only; it repairs nothing.
4. **Domain.** `BidProgression` enum; `AuctionRules` v4 (`nextValidBid`,
   `assertValid`); `BidValidator` gains the `exact_step` branch and keeps the two
   legacy branches; `BidRejected::notTheNextBid`; `AuctionRuleset::toRules()`.
5. **UI.** `AuctionRoom` two modes; confirmation carries the confirmed amount; admin
   form, index and detail; help text.
6. **Docs.** `CLAUDE.md` (*Undecided rules stay null* is now decided for this rule;
   the snapshot version; the exact-step rule), `ROADMAP.md`, a verification runbook.
7. **Deploy** with the site in maintenance mode for the seconds between the file
   swap and `migrate`, because after the migration the code refuses v3 snapshots and
   a bid arriving in that window would fail. Take a database backup first: the
   snapshot rewrite is forward-only by this repository's own convention.

## 9. Tests to add

- Exact step: highest 20, step 1 → only 21 accepted; 20, 22, 30, 100, 2 refused with
  no bid row, no credit movement and no extension.
- Opening bid: min 5 / step 2 → 5, 7, 9; a first bid of 6 or 7 is refused.
- Concurrency (MySQL, real locks): two bidders submit the same next amount; exactly
  one commits, the other is refused with nothing consumed.
- Stale confirmation: the confirmed amount is no longer the next valid bid → refused,
  confirmation closed, page shows the new next bid.
- Tamper: a component request setting the amount to 30 is refused.
- Leader with `allow_bid_increase = false` sees no bid button; with `true` may raise.
- Snapshot: v4 round-trip; v3 refused; a converted `minimum_step` auction still
  enforces its lower bound; a converted `free_form` auction still accepts free bids.
- Migration: a v3 snapshot with a null increment and one with increment 5 both convert
  to identical behaviour; the frozen-configuration trigger is back after the
  migration and still refuses edits.
- UI: exact-step room shows the next bid and no text field; a legacy room still
  shows the field; the admin form requires the increment to activate; labels.
- Regression: bid history participant numbering, projection, credit consumption,
  Buy Now discount, Store Wallet issuance, notifications and the broadcast payload
  are unchanged.

## 10. Invariants that must hold

Server decides; validation inside the transaction after the auction lock; a bid never
exists without its credit consumption; a rejected bid leaves nothing; bids stay
append-only; a frozen snapshot never changes meaning; `AuctionRules` stays `readonly`;
lock order auction → product → wallet → lots is untouched; credits are never refunded
by this change; broadcast payload whitelist untouched; polling stays.

## 11. Decisions needed

| ID | Decision | Recommendation |
|---|---|---|
| **D-1** | Does a bid still consume its full amount under fixed steps (§4), or something else? | Decide first. This plan keeps today's rule and shows its consequence. |
| D-2 | Must a ruleset have a minimum bid as well as an increment? If not, what is the opening bid? | Require both to activate, so nothing is invented. Alternative: opening bid = the increment. |
| D-3 | Existing rulesets #1 and #3 (no increment) and #2 (increment 5) on staging: leave active but flagged "legacy", or archive and re-create? | Leave, flag as legacy, let an admin version them. |
| D-4 | A leader with `allow_bid_increase = true` may raise their own bid to highest + step. Keep? | Keep; it is the existing rule. |
| D-5 | Keep the two-step confirmation behind "Bid 21 credits". | Keep. Credits are irreversible. |
| D-6 | Confirm the maximum-increment design is dropped. | Yes; the exact step makes it redundant. |
