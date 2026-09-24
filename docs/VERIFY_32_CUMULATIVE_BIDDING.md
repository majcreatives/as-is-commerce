# Stage 32 — Cumulative bidding: deploy and verification runbook

Companion to `PLAN_32_FIXED_BID_INCREMENT.md`, which explains the design and the
decisions. This file is the sequence for putting it on staging and proving it
works. Nothing here is run by an AI agent without the operator saying so.

**What ships.** The cumulative bidding model in the engine, the room, the words,
the messages and the admin. A bidder's position is the total credits they have
consumed on the auction; the server works out the one bid that puts them exactly
one step ahead of the leader; the largest total wins.

**What is already on staging** (`stage32.0`, deployed and verified): the `bid_model`
column on rulesets, the nullable `bids.cumulative_credits` column and its index,
and snapshot version 4 with every existing auction recorded as `single_highest`.

**What this release adds:** one migration, `2026_09_20_110000_add_bid_increment_to_rulesets`
(a nullable `bid_increment_credits` column and three CHECK constraints). It is
additive; every existing ruleset is single-highest with no step, so all three
constraints already hold.

---

## 0. Read this before deploying: what changes for people using staging today

Nothing in the product offers the earlier bidding rule for a **new** auction, and
the room does not bid on an auction that follows it. Consequences on staging:

- **Auctions already open stop taking bids** the moment this deploys. On the last
  reading those were #14 (live until 24 Sep 07:17 UTC, 11 bids) and #16 (ended by
  21 Sep 04:30 UTC), plus #6, a draft. They are shown read-only ("not taking new
  bids") and are **still closed by the sweep under the rule they were made under**,
  winners resolved, settlement checkouts opened and Store Wallet issued exactly as
  before. Nothing about them is broken; they simply cannot be bid on any more.
- **Nobody can create an auction until a cumulative ruleset is active.** The three
  existing rulesets (#1 Standard Auction, #2 Forfeit Test, #3 Test 1 Bid Ruleset)
  follow the earlier rule and are not offered. Section 3 walks through moving one.

**Choose before deploying**, and record the choice:

| Option | Effect |
|---|---|
| **Wait** until #14 has closed (24 Sep, 07:17 UTC) | Nothing is interrupted. Simplest. |
| **Cancel** #14 and #6 first | Faster. `CancelAuction` compensates nobody, so #14's 11 bids' credits are consumed with nothing issued. Test data, but still a financial act on staging: do it deliberately. |
| **Deploy now** and let them run out read-only | Acceptable if nobody minds them being un-biddable for a few days. |

---

## 1. Before anything is touched

```bash
# From the release checkout: confirm main is what you expect, and the suite is green.
git status && git log --oneline -6
php artisan test              # the memory limit is in phpunit.xml; expect a pass count, not a memory error

# The tag. The workflow builds as-is-commerce-<tag>.zip and attaches it to a Release.
git tag stage32.1 -m "Stage 32.1 — cumulative bidding: engine, room, wording, admin"
git push origin stage32.1
```

Confirm a non-draft Release named `stage32.1` exists with its zip before going on.

**Back up the tables this release touches** (rulesets gain columns and constraints;
snapshots are already v4). On the server, credentials from `.env` without printing
them, password through the environment and never the command line:

```bash
cd <app dir>
get() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
export MYSQL_PWD="$(get DB_PASSWORD)"
mkdir -p ~/backups && chmod 700 ~/backups
OUT=~/backups/pre-stage32.1-$(date -u +%Y%m%dT%H%M%SZ).sql
mysqldump -h"$(get DB_HOST)" -P"$(get DB_PORT)" -u"$(get DB_USERNAME)" \
  --single-transaction --skip-comments "$(get DB_DATABASE)" \
  auctions auction_rulesets bids > "$OUT" && chmod 600 "$OUT" && ls -l "$OUT"
```

## 2. Deploy

The usual swap (see `DEPLOY_STAGE30_0.md`, and `DEPLOYMENT.md` §2a for carrying
uploads across it). The new release goes into maintenance mode **before** it goes
live, so no bid can arrive between the new code starting and `migrate` finishing.

```bash
# In the new directory, before the swap:
php artisan down --refresh=15
# ... swap directories, copy .env and storage/app/{public,private}, storage:link ...
php artisan config:clear && php artisan config:cache
php artisan route:cache && php artisan view:cache
php artisan migrate --force          # expect: 2026_09_20_110000_add_bid_increment_to_rulesets DONE
php artisan auctions:verify-snapshots   # expect: every snapshot loads and agrees
php artisan up
```

If `migrate` or the verification fails, **stop and leave the site in maintenance**.
Rollback is renaming the previous directory back; the migration has a `down()`.

Then confirm from outside: `/health`, `/up`, home, shop, `/auctions`, an auction
page, `/how-it-works`, `/about`, `/faqs`.

## 3. Give staging a cumulative ruleset

There is none yet, so no auction can be created. As an administrator:

1. **Rulesets** → pick an existing lineage (e.g. *Standard Auction*) → **New version**.
   The draft opens in the form already on the cumulative rule. The opening bid
   carries over if the old ruleset had one; the **bid increment is empty on
   purpose** — nothing invents it.
2. Set the **minimum bid** and **bid increment** (for the owner's example: 1 and 1),
   save, and **activate** it. Activation is refused, with the reason, while either
   is missing.
3. **Auctions** → **New auction**. The ruleset list offers only cumulative
   rulesets. The older ones are not listed, and a forged id is refused.

## 4. Verify it works

Use three signed-in accounts with credits (call them A, B and C) and one
product with stock. All figures below are the owner's worked example, minimum 1
and step 1, and they are also automated tests: if any differs, that is a defect.

| # | Who | What they see | What happens | Total after |
|---|---|---|---|---|
| 1 | A | "Be the first: the opening bid on this auction is 1 credit" and **Bid 1 credit** | opens | A = 1 |
| 2 | A | "You hold the lead"; **nothing to press** | — | — |
| 3 | B | "To take the lead, add 2 credits" | bids 2 | B = 2 |
| 4 | A | "You have been outbid"; "add 2 credits" | bids 2 | A = 3 |
| 5 | C | "add 4 credits" (a newcomer pays the leader's total plus one step) | bids 4 | C = 4 |
| 6 | B | "add 3 credits" (B already holds 2) | bids 3 | B = 5 |

Check, in order:

- **Two clicks.** Pressing the button opens a confirmation (what it costs, your
  total after, your balance afterwards); nothing is consumed until *Confirm bid*.
  *Cancel* consumes nothing.
- **Credits.** After the sequence A has consumed 3, B 5, C 4 (12 in all), and each
  wallet dropped by exactly that.
- **The label.** The leading figure reads **Highest Total (Credits)** and shows 5,
  not 3 or 4 (the largest single bid was C's 4; B leads on a total of 5).
- **A stale page.** Open the room as B in two tabs. Let C take the lead in one, then
  press the old button in the other. It must be **refused, with the new amount
  named, and nothing consumed** — never quietly bid at the new figure.
- **Affordability.** An account with fewer credits than the button shows sees the
  shortfall and a *Buy credits* link, and no button.
- **A visitor** (signed out) sees what joining costs, and nothing to press.
- **History** shows *Credits added* and *Total after* by participant number, and no
  name, phone or email of any bidder.
- **Close it** (let it run out, or *Close now* as an admin). The winner is B, the
  result reads "Won with the highest total: 5 credits committed", B's settlement
  checkout describes the win the same way, and A and C each get **Store Wallet**
  value for the credits they consumed **if those credits were bought** (free
  credits are worth nothing). B, the winner, gets none.
- **Notifications.** A "bid placed" message says where the bid left the bidder
  ("You now lead with …"). An outbid message says somebody **took the lead** and
  gives the new total, never that they "bid higher". None offers credits back.
- **The earlier rule.** Open an old auction: it shows *Bidding model: Single highest
  bid*, its own labels ("Highest Bid (Credits)") and no bid button. Its history is
  intact.
- **Admin.** The ruleset list shows each ruleset's model and, for older ones, that
  it follows the earlier rule. The auction detail shows *Opening bid* and *Bid
  increment* for a new auction and *Minimum increment (earlier rule)* for an old one.

Then run, on the server:

```bash
php artisan auctions:verify-snapshots
```

It must report every snapshot loading, every projection agreeing, and — for the
new auction — no standing problems. It repairs nothing.

## 5. Also read

- The general pages (home, About, FAQs, How It Works) now state the cumulative rule.
  They were written to be true of the platform as it now behaves; read them once as a
  customer would.
- **Contact** still says "not published yet" until `support_email` /
  `support_phone` are set in settings.

## 6. Rollback

- **Code:** rename `as-is-commerce-stage20.bak-32.1` back into place, re-cache.
- **Schema:** `php artisan migrate:rollback --step=1` drops the three constraints and
  the column. It is safe **only while no cumulative ruleset exists**, because those
  rows use the column. Once one does, rolling back the schema would need that ruleset
  removed first, which is a decision for a person, not a script.
- **Auctions made under the cumulative model** cannot be read by code that predates
  it (it refuses their snapshots). Rolling code back after one exists therefore means
  cancelling it first.
- **Data:** the pre-deploy dump in `~/backups`.

---

## 7. Deployment record

| Release | Commit | Deployed (staging) | Result |
|---|---|---|---|
| `stage32.0` | cumulative schema baseline | 2026-09-20 | migration + snapshots v4 verified |
| `stage32.1` | cumulative engine/room/wording/admin | 2026-09-21 | engine + room checks PASS (`VERIFY_POT_TARGET_BIDDING.md` hard prerequisite satisfied) |
| `stage32.2` | `CreditAmount` + redenomination + pot target | 2026-09-22 | pot-target auction verified on staging |
| `stage32.3` | raw-subcredit leak fix | 2026-09-24 | this record |

**Stage 32.3 (2026-09-24) — raw-subcredit leak fix.** The re-denomination
introduced in `stage32.2` stored Credits ×10,000 ("subcredits") while
`CreditAmount` was only used for display. A real defect shipped in that release:
messages written from raw values (bid ledger descriptions, credit-purchase
ledger descriptions, rejected-bid figures, room messages, referral
reconciliation messages) were not routed through `CreditAmount`, so they stated
10,000× the real figure. Fix commit `3720982`, tag `stage32.3`, on `main`:

- `PlaceBid`: bid ledger description rendered at credit scale.
- `FulfillCreditPurchase`: purchase ledger descriptions at credit scale (3 sites).
- `BidRejected`: every figure message at credit scale; one-credit wording uses
  the singular "1 credit".
- `AuctionRoom`: leader/step affordability figures at credit scale.
- `BidValidator` / credit ledger: insufficient/below-minimum wording at credit scale.
- `ReferralReconciler`: reconciliation descriptions at credit scale.
- `credit-packages` blade: singular/plural "1 credit" vs "N credits".
- New and existing ledger rows are append-only: already-written rows keep their
  historical text; all new writes are clean.

Verification evidence:

- **Local suite:** full suite green in per-directory chunks (admin 365, auth 43,
  auction suite incl. CumulativeRoom 18 / CumulativeBid 23 / BidRuleBaseline 8 /
  BidPlacement 56 / AuctionUi 58 and the auction engine/model/operations files,
  catalog 188, concurrency 105, credit 83, delivery+marketplace+notifications 301,
  orders 190, payments 115, refunds 87, referrals+realtime 114, schedule+settings 49,
  store-wallet+wallet 60, unit 172, feature root/misc 29). Pint clean. PHPStan 0 errors.
- **Staging build integrity:** `as-is-commerce-stage32.3.zip` (13,910,841 bytes,
  SHA-256 `87540dd3d72aba011199bff5cc0bffd10c8dcd080e3c9014cff31165694e3528`)
  downloaded from the GitHub Release; extracted over the live dir (backup kept at
  `as-is-commerce-stage20.bak-32.3`); scratch scripts from previous sessions removed.
- **Byte-identical check:** SHA-256 of `CreditAmount.php`, `PlaceBid.php`,
  `BidRejected.php`, `AuctionRoom.php` on staging match `stage32.3` locally.
- **Runtime:** `/health` `{"status":"ok","database":"ok"}`; `/up` 200; home,
  `/auctions`, `/credits`, both test auction rooms 200; no exception markers;
  auction rooms render credit-scale totals ("9 credits", "3 credits"); no 10,000×
  raw figure or leakage in any rendered page or `laravel.log`.
- **Message render check (tinker on staging):** `1 credit`, `3 credits`,
  `20 credits`, `150 credits`; stale-page "add exactly 3 credits (the leader has
  2 credits and you have 0 credits)"; "You already hold the lead with 1 credit";
  "A bid of 19 credits is below this auction's minimum of 20 credits"; "Insufficient
  credits: 4 credits requested, 3 credits available".
- **Reconciliation on staging:** `auctions:verify-snapshots` — all 19 auctions load
  and agree; `refunds:reconcile` and `referrals:reconcile` report no anomalies.
- **No migrations** in `stage32.3` (`migrate --force` → nothing to run).
