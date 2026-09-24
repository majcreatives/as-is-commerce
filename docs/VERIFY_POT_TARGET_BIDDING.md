# Pot-target bidding — deploy and verification runbook

Companion to `PLAN_POT_TARGET_BIDDING.md`, which explains the design and the
ten decisions (D-1 through D-10). This file is the sequence for putting it on
staging and proving it works. **Nothing here is run by an AI agent without
the operator saying so** — the same rule `VERIFY_32_CUMULATIVE_BIDDING.md`
states, and for the same reason: this touches real credit figures on a real
server, and the SSH-side steps require operator access this session does not
have.

**Hard prerequisite: `stage32.1` is deployed to staging first, and confirmed
working, before this runbook is started.** Every credit-bearing table this
release rewrites — `bids`, `auction_rulesets`, `credit_lots`, and the rest —
is exactly as true of a single-highest auction as a cumulative one, so this
release does not technically *require* cumulative bidding to be live. It is
sequenced after it anyway because the two are separate, independently-reviewed
pieces of work (owner's own decision, 2026-09-22), and bundling them into one
deployment would mean a problem found during verification cannot be
attributed to one or the other. If `stage32.1` is not yet live, stop here and
run its own runbook first.

**What ships.** Four things, in the order §7 of the plan built them:

1. `CreditAmount` — a display layer for Credits, the same pattern `Money`
   already provides for cash: a raw integer count stored in the ledger
   ("subcredits"), and a divisor that turns it into the decimal figure a
   customer reads.
2. **The re-denomination.** Every credit-bearing column, multiplied by
   10,000, in one migration — `2026_09_22_100000_redenominate_credits_to_subcredits`.
   The divisor is set to 10,000 in the same release, so the two cancel and a
   customer sees the same numbers before and after: **"100 Credits" stays
   "100 Credits."**
3. **The pot target.** A new, optional, per-auction column,
   `pot_target_credits` — `2026_09_22_110000_add_pot_target_to_auctions` — and
   the engine logic that closes an auction early once the sum of every
   accepted bid reaches it, alongside the existing clock.
4. **The surface.** The room shows nothing about the pot target (customers do
   not see the aggregate or the target — removed 2026-09-24; the admin
   auction detail keeps the figure), notification wording for an early
   close, and the admin auction-creation field (optional, no ratio or
   guideline text — the owner confirmed this explicitly, 2026-09-22).

A real, pre-existing bug was found and fixed along the way: every
notification involving credits (bid confirmations, outbid alerts, wins,
losses, referral rewards) was showing the raw, unconverted subcredit count —
10,000× too large — because `NotificationSubscriber` had never been routed
through `CreditAmount`. This deploys with the fix already in place, so
staging never actually shows the wrong figure in a notification; only this
runbook's history records that the bug briefly existed in committed code.

**What is already on staging, once `stage32.1` is confirmed:** the cumulative
bidding engine, room, wording and admin, with every credit figure still at
today's scale (no re-denomination yet) — `bid_increment_credits` on
rulesets, and every existing bid, wallet balance and lot at their current,
un-scaled counts.

---

## 0. Read this before deploying: what changes for people using staging today

**Every credit figure on staging changes its underlying stored value.** This
is the one migration in this entire project explicitly designed to touch
real, accumulated data rather than add an empty column. Read `PLAN_POT_TARGET_BIDDING.md`
§5, D-9 in full before running this — in particular the governing principle
(a standalone credit count converts by the factor; a count stored alongside
its own frozen denominator is left alone) and the complete convert / leave-alone
table. Do not deploy this from memory of that table; re-read it.

**Nothing a customer sees may change, and that is the literal test.** The
divisor moves in the same release as the multiplication, so "100 Credits"
reads as "100 Credits" before and after, on every wallet, package label and
history row. If any figure on staging reads ten thousand times larger (or
smaller) immediately after this deploys, **stop — that is the one failure
mode this whole design exists to prevent**, and the rollback in §7 applies.

**The migration verifies itself before it commits.** `up()` computes a
checksum of every convertible column before touching anything, drops the six
triggers that would otherwise block the write, scales every column inside one
transaction, and inside that same transaction asserts: every checksum scaled
by exactly 10,000×; no lot's remaining amount exceeds its own original
amount; every wallet's balance still equals the sum of its transactions. Any
one of those assertions failing throws, which rolls back the entire
transaction — the triggers are restored in a `finally` regardless. A `migrate`
run that reports `DONE` for this migration is therefore already strong
evidence the ledger survived intact; §3 re-confirms it from the outside as
well.

**The pot target is invisible until an administrator sets one.**
`pot_target_credits` is nullable, and nothing on staging sets it until §4.
Every existing auction, and every auction created before an administrator
first uses the new field, behaves exactly as it does today — closes on its
clock alone. There is no behavioural change to verify for any auction that
does not carry a target.

**Choose before deploying**, and record the choice, exactly as `stage32.1`'s
own runbook required:

| Question | What to check |
|---|---|
| Any auction live right now? | It keeps running under whatever model it was created with. The redenomination does not pause the clock or refuse a bid mid-deploy — deploy during a quiet window regardless, per §2's maintenance-mode step, so no bid is mid-flight when the triggers are dropped. |
| Any credit purchase mid-payment? | Check `credit_purchases` for a `PENDING`/`INITIALIZED` row. The migration converts `credit_amount` regardless of status; a purchase that fulfils *after* this deploys will grant the post-migration (larger, same-value) subcredit count against its snapshot, which is unaffected because `credit_amount` on the purchase row itself is what gets scaled. |

---

## 1. Before anything is touched

```bash
# From the release checkout: confirm main is what you expect, and the suite is green.
git status && git log --oneline -6
php artisan test              # the memory limit is in phpunit.xml; expect a pass count, not a memory error
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=512M

# The tag. Choose the next number in this lineage — check `git tag --sort=-creatordate`
# first; do not assume it is stage33, which the roadmap already reserves for
# something else. A plain, unnumbered tag ("pot-target-bidding.1") is also fine;
# the working title in the plan document was deliberately left unnumbered.
git tag <chosen-tag> -m "Pot-target bidding: CreditAmount, redenomination, pot target, room, admin"
git push origin <chosen-tag>
```

Confirm a non-draft Release named `<chosen-tag>` exists with its zip before
going on.

**Back up every table this migration touches.** This list is longer than any
previous release's because the redenomination is the widest-reaching
migration in the project. On the server, credentials from `.env` without
printing them, password through the environment and never the command line:

```bash
cd <app dir>
get() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
export MYSQL_PWD="$(get DB_PASSWORD)"
mkdir -p ~/backups && chmod 700 ~/backups
OUT=~/backups/pre-pot-target-bidding-$(date -u +%Y%m%dT%H%M%SZ).sql
mysqldump -h"$(get DB_HOST)" -P"$(get DB_PORT)" -u"$(get DB_USERNAME)" \
  --single-transaction --skip-comments "$(get DB_DATABASE)" \
  credit_wallets credit_transactions credit_lots credit_lot_consumptions \
  credit_packages credit_purchases bids auctions auction_rulesets \
  orders referrals settings \
  store_wallet_credit_sources store_wallet_transactions store_wallets \
  > "$OUT" && chmod 600 "$OUT" && ls -l "$OUT"
```

The last three tables are backed up defensively even though the migration
leaves them alone (`store_wallet_credit_sources`'s stored pair is one of the
two "left alone" exceptions in D-9) — if verification ever needs to prove
they did *not* move, the backup is the only way to show what they were before.

**Record the pre-migration checksums**, so §3 has something exact to compare
against rather than a plausibility check:

```bash
php artisan tinker --execute="
\$m = require database_path('migrations/2026_09_22_100000_redenominate_credits_to_subcredits.php');
dump((new ReflectionMethod(\$m, 'checksums'))->invoke(\$m));
"
```

Save that output. After the migration runs, the same command's numbers must
be exactly 10,000× the pre-migration figures for every convertible key, and
identical for the two left-alone ones.

## 2. Deploy

The usual swap (`DEPLOYMENT.md` §2a, and `DEPLOY_STAGE30_0.md` for the exact
sequence). Maintenance mode goes on **before** the swap, so nothing can place
a bid or open a credit purchase while the triggers are down.

```bash
# In the new directory, before the swap:
php artisan down --refresh=15
# ... swap directories, copy .env and storage/app/{public,private}, storage:link ...
php artisan config:clear && php artisan config:cache
php artisan route:cache && php artisan view:cache
php artisan migrate --force
```

Expect exactly these two migrations to run, in this order, and nothing else:

```
2026_09_22_100000_redenominate_credits_to_subcredits ... DONE
2026_09_22_110000_add_pot_target_to_auctions .......... DONE
```

If either fails, **stop and leave the site in maintenance.** The
redenomination rolled back its own transaction on any assertion failure, so
the database is unchanged; do not attempt to run it again without reading the
failure message in full — it names exactly which assertion failed and, for a
checksum mismatch, the table and column.

```bash
php artisan auctions:verify-snapshots   # expect: every snapshot loads and agrees
php artisan up
```

Then confirm from outside: `/health`, `/up`, home, shop, `/auctions`, an
auction page, an existing customer's wallet page, `/how-it-works`, `/about`,
`/faqs`.

## 3. Confirm the numbers moved and the customer sees nothing different

This is the release's own core promise, checked directly against real data
rather than inferred from the migration having reported success.

1. **Pick one real wallet** with a non-zero balance (from the pre-migration
   backup, or the admin Wallets screen). Compare what the admin **Wallet
   detail** screen showed *before* the deploy against what it shows *now*.
   The displayed balance — "Your credits", the packages page, the dashboard —
   must read **identically**. The only place a difference is expected to be
   visible is `storage/logs` or a direct database query, never a screen.
2. **Reconcile that same wallet** from its detail screen (**Run reconciliation**,
   backed by `CreditLedgerReconciler`). It must report "Consistent," never a
   discrepancy.
3. **Re-run the checksum comparison** from §1 and confirm every convertible
   figure is exactly 10,000× its pre-migration value, and the two left-alone
   figures (`store_wallet_credit_sources`, `pricing_snapshot.valuation`) are
   byte-for-byte unchanged.
4. **Open one auction that already had bids** before this deploy. Its bid
   history, its leading figure, and its label (`Highest Bid (Credits)` or
   `Highest Total (Credits)`, whichever it was created under) must read
   exactly as they did before — same numbers, same words.
5. **Open one credit package** on the packages page. Its advertised Credits
   figure must be unchanged.

If any of 1, 4 or 5 reads differently, stop and treat it as the failure this
whole plan exists to prevent, not a cosmetic issue — see §7.

## 4. Give staging a pot-target auction

There is none yet — every existing cumulative ruleset and auction predates
this release. As an administrator:

1. **Auctions** → **New auction**. Choose a product and an active cumulative
   ruleset, exactly as before.
2. Fill in the **Pot target (credits)** field. It is optional and carries no
   hint text or ratio on the form by design (D-4, D-10, confirmed with the
   owner 2026-09-22) — choose a figure by your own judgement, small enough to
   reach with two or three test accounts' worth of bidding, e.g. `3` for a
   ruleset with minimum 1 and step 1.
3. Save, then publish it the ordinary way.

## 5. Verify it works

Use two or three signed-in accounts with credits and one product with stock.
The figures below assume minimum 1, step 1, target 3 credits — the smallest
version of the owner's own worked example, and also an automated test
(`PotTargetBiddingTest.php`): if this differs, that is a defect.

| # | Who | What they see | What happens | Pot after |
|---|---|---|---|---|
| 1 | A | Be the first: the opening bid; nothing about a pot target (the room no longer shows one) | bids 1 (the opening bid) | 1 |
| 2 | B | "To take the lead, add 2 credits"; still nothing about a pot target | bids 2 | 3 — **the target** |

Check, in order:

- **It closes immediately**, within B's own request — not on the next
  `auctions:tick` sweep, and not after a page refresh. B's bid response
  itself shows the auction has moved to awaiting settlement.
- **B wins**, not A: B's total (2) exceeds A's (1) under the cumulative
  model's own rule. The pot target decided *when* it closed, never *who*
  wins.
- **The closure reason** on the auction's badge reads distinctly from an
  ordinary clock-driven close — "Won when the pot target was reached" — and
  the admin detail page's history note says "Closed early: the pot target
  was reached," never "Closed on the clock."
- **B's notification** says the auction "closed early because enough bidders
  joined in to reach its target," and does **not** state the target figure
  itself.
- **A's notification** (the loser) says the same thing, and still says their
  credits remain consumed.
- **The room, live, before it closes**: reopen the auction from a fresh
  browser session partway through (or use a second test account mid-sequence)
  and confirm the customer room shows **nothing** about a pot target or an
  aggregate "committed so far" figure — not even for an auction that carries
  one. The aggregate and the target remain visible only to administrators, on
  the auction detail screen.
- **An ordinary auction, alongside this one**: open any auction with no pot
  target set and confirm it shows nothing about a target, closes only on its
  clock, and its notifications say nothing about "closing early."
- **The sweep backstop**: confirm `auctions:tick` still runs cleanly
  (`php artisan schedule:list`, then watch the next scheduled run) and does
  not error on an auction carrying a target.

Then run, on the server:

```bash
php artisan auctions:verify-snapshots
```

It must report every snapshot loading, every projection agreeing, and no
standing problems, for the new auction as well as every existing one. It
repairs nothing.

## 6. Also read

- The general pages (home, About, FAQs, How It Works, the auction index)
  describe the cumulative rule; none of them mention the pot target, because
  it is a per-auction feature, not a platform-wide rule, and the plan does
  not ask them to.
- Nothing here touches payments, orders, delivery, refunds or referrals
  beyond the one column each already had scaled (`orders.discount_credits`,
  `referrals.reward_credits`) — verify a credit purchase and a referral
  reward once each, the same way §3 verified a wallet: identical displayed
  figures before and after.

## 7. Rollback

**Read this in full before deploying, not after something goes wrong.** The
redenomination's rollback is genuinely riskier than any previous release's,
because it divides real values back down, and division only round-trips
exactly for a value that is an exact multiple of 10,000.

- **If nothing has been created or paid for since the deploy**: `php artisan
  migrate:rollback --step=2` runs both migrations' `down()` methods. The pot
  target migration's `down()` is a plain schema reversal, safe unconditionally.
  The redenomination's `down()` first asserts every value it is about to
  divide is an exact multiple of 10,000, for exactly this reason — it refuses
  to run rather than silently truncate a value that was **not** produced by
  its own `up()`. If it refuses, stop; do not force it.
- **If anything financial has happened since the deploy** (a bid, a credit
  purchase fulfilled, a referral reward issued) — **do not roll back the
  schema.** Those new rows were written at the new, 10,000× scale and belong
  there; dividing the *whole* table back down would also divide figures that
  were never multiplied in the first place, corrupting them. The rollback
  boundary at that point is the pre-deploy backup from §1, not schema
  surgery, exactly as `DEPLOYMENT.md` §8 already states as the general rule
  for this project.
- **Code:** rename the previous release directory back into place, re-cache
  (`config:cache`, `route:cache`, `view:cache`). This alone does not undo the
  database changes — see above.
- **The pot target column, specifically**: once any auction has
  `pot_target_credits` set and has left Draft, that column is frozen on that
  row by `auctions_frozen_configuration` — dropping the column at that point
  is destructive to a real auction's configuration and needs the same
  business decision any other frozen-data removal would.

---

## 8. Deployment record

| Release | Content | Deployed (staging) | Result |
|---|---|---|---|
| `stage32.1` | cumulative bidding engine/room/wording/admin | 2026-09-21 | PASS, the hard prerequisite of this release |
| `stage32.2` | `CreditAmount` + redenomination + pot target | 2026-09-22 | pot-target auction + redenomination verified on staging |
| `stage32.3` | raw-subcredit leak fix | 2026-09-24 | see `VERIFY_32_CUMULATIVE_BIDDING.md` §7 |

**Stage 32.2 (2026-09-22).** Deployed per §2: the migration
`2026_09_22_100000_redenominate_credits_to_subcredits` self-verified (checksums
×10,000, no lot over its original, wallet balances equal transaction sums) and
`2026_09_22_110000_add_pot_target_to_auctions` ran; `auctions:verify-snapshots`
reported every snapshot loading and agreeing; a pot-target auction was created
(min 1, step 1, target 3) and closed on its target within the bidder's request,
B (total 2) winning over A (total 1). Pre-migration backups recorded in
`~/backups` (`pre-stage32.2-*.sql`, `pre-stage32.2-checksums.txt`).

**Stage 32.3 (2026-09-24) — raw-subcredit leak fix.** Ship of the fix for a
re-denomination defect: `CreditAmount` covered display, but a set of message
writers still read raw subcredit values (10,000× too large). Fixed commit
`3720982`, released as `stage32.3`; the full evidence block (test totals,
staging integrity hashes, byte-identical file check, runtime/health checks,
message-render tinker output, 19-auction snapshot verification, reconciliation
no-anomalies) is recorded in `VERIFY_32_CUMULATIVE_BIDDING.md` §7.
