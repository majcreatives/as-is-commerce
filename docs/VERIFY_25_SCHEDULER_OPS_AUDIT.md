# Verify 25 — Scheduler / cron / operations audit

| | |
|---|---|
| **Stage** | 25 — Scheduler/cron/operations audit |
| **Gate** | High — Hostinger hPanel cron runs `schedule:run`; `auctions:tick`, `orders:expire-checkouts`, refund/referral reconciliation are idempotent and bounded; `ScheduleLocks` and heartbeat stamps verified |
| **Evidence base** | Local unit tests + live Hostinger staging in Paystack test mode |
| **Runbook author** | AI agent (OpenCode) |
| **Executed by** | Operator over SSH + hPanel; verdicts recorded by the agent |
| **Staging** | `https://darksalmon-swan-978886.hostingersite.com` |
| **Stage 24 baseline commit** | `83e2380` |
| **AGENTS.md anchors** | §82 (scheduled commands), §83 (scheduler locks), §84 (sweep limits), §85 (Hostinger cron), §86 (staging paths), §121 (projections), §124 (diff review), §125 (completion report) |
| **Code anchors** | `routes/console.php`, `app/Support/ScheduleLocks.php`, `app/Console/Commands/{RunAuctionClock,ExpireCheckouts,ReconcileRefunds,ReconcileReferrals}.php`, `tests/Feature/Schedule/SchedulerMutexTest.php`, `tests/Feature/Auction/AuctionClockCommandTest.php`, `tests/Feature/Orders/ExpireCheckoutsCommandTest.php`, `Order::scopeDueToExpire`, `Auction::scopeDueToStart/scopeDueToClose` |

---

## 1. Objective

Prove, on the real Hostinger deployment, that the platform does not need a
persistent daemon: a single `schedule:run` cron advances auctions, expires
abandoned checkouts, reconciles refunds and reports referral anomalies; that the
sweeps are idempotent and bounded; and that the overlap locks self-heal within
their explicit windows.

No application code is expected to change. The only intended host change is the
hPanel cron entry. If the audit finds a defect, it is fixed in source, tested,
committed and pushed before any re-verification — the same discipline as every
other stage.

---

## 2. Known implementation (the spec under test)

### 2.1 The schedule (`routes/console.php`)

| Event | Expression | Lock expiry | Business reason |
|---|---|---|---|
| `auctions:tick` | `* * * * *` | `ScheduleLocks::SWEEP_MINUTES` = 5 | start/closing/close/forfeit everything whose clock arrived |
| `orders:expire-checkouts` | `* * * * *` | `ScheduleLocks::SWEEP_MINUTES` = 5 | release unpaid holds past `payment_due_at` |
| `refunds:reconcile` | `*/15 * * * *` | `ScheduleLocks::RECONCILE_MINUTES` = 30 | verify outstanding refunds with Paystack; report anomalies |
| `referrals:reconcile` | **not scheduled** | n/a | report-only diagnostic, run on demand; intentionally **not** on the schedule |

Every event uses `withoutOverlapping(<explicit>)` + `runInBackground()`.

### 2.2 The lock rationale

`withoutOverlapping()` defaults to a 1,440-minute mutex released only via POSIX
signals behind `extension_loaded('pcntl')`. Development is native Windows
(no `pcntl`) and every sweep runs in the background, so a killed run leaves its
lock behind. Locks live in the `database` cache store and survive restarts.
Erring short is safe by construction: every step re-reads its row under
`SELECT ... FOR UPDATE` and becomes a no-op if another run got there first —
proved by `it closes an auction exactly once when two sweeps run together`.

### 2.3 The sweeps

- **`auctions:tick --limit=200`** — bounded, one failing auction is logged and
  others continue; stamps `sweeps:auctions_tick:last_run`.
- **`orders:expire-checkouts --limit=200`** — bounded; re-reads each order under
  a row lock and leaves paid/cancelled orders alone; stamps
  `sweeps:expire_checkouts:last_run`.
- **`refunds:reconcile --limit=100 [--skip-report]`** — bounded, provider-bound;
  *verifies* outstanding refunds (the only path to `Succeeded`) and *reports*
  anomalies without repairing them; **non-zero exit when anomalies exist**;
  stamps `sweeps:reconcile_refunds:last_run`.
- **`referrals:reconcile --limit=200`** — report-only, issues no credits, never
  scheduled; stamps `sweeps:reconcile_referrals:last_run`.

### 2.4 Heartbeat stamps

Command success writes `sweeps:<name>:last_run` into the database cache store.
These stamps are the audit's primary proof that the *scheduled* path (cron →
`schedule:run`) actually fires, independent of any process memory.

---

## 3. Baseline — unit/feature tests (run locally, not on staging)

Run from the repository root (Windows PowerShell):

```powershell
./vendor/bin/pint
./vendor/bin/phpstan analyse --memory-limit=512M
/opt/alt/php84/usr/bin/php -d memory_limit=768M vendor/bin/pest tests/Feature/Schedule/SchedulerMutexTest.php
/opt/alt/php84/usr/bin/php -d memory_limit=768M vendor/bin/pest tests/Feature/Auction/AuctionClockCommandTest.php
/opt/alt/php84/usr/bin/php -d memory_limit=768M vendor/bin/pest tests/Feature/Orders/ExpireCheckoutsCommandTest.php
```

Record exact counts passed. Expected:

- **SchedulerMutexTest** — every sweep has overlap protection; expiry explicit
  (5/5/30) and not the 1,440 default; every expiry ≤ 60 (`Recover within the
  hour`); a stale mutex clears itself; an interrupted process does not block
  forever; an auction closes correctly after interruption and exactly once when
  two sweeps run together; no business logic touched; exactly 3 scheduled events
  at `* * * * *`, `* * * * *`, `*/15 * * * *`.
- **AuctionClockCommandTest / ExpireCheckoutsCommandTest** — happy, failure,
  retry, concurrency, security, idempotency coverage per AGENTS §96.

> Note: the RunAuctionClock signature output line is
> `Started N, entered closing N, closed N, forfeited N.` and ExpireCheckouts is
> `Expired N checkout(s).` — these are asserted through the staging steps below,
> not just in code.

---

## 4. Staging steps

### Conventions

- SSH: `ssh -p 65002 u146516859@89.116.53.20`
- App dir: `/home/u146516859/domains/darksalmon-swan-978886.hostingersite.com/public_html/as-is-commerce-stage20`
- PHP binary: `/opt/alt/php84/usr/bin/php`
- Run every artisan command from the app dir, wrapping tinker snippets in a
  single `--execute="..."` string. Staging shell is **bash** (no PowerShell
  `if ($?)`).
- Recording rule: a step is **PASS** only when the evidence was actually
  observed on staging. Never claim a step from reasoning.

---

### Flow A — The schedule is registered and wired

**A1 — The three scheduled events exist.**
```bash
cd /home/u146516859/domains/darksalmon-swan-978886.hostingersite.com/public_html/as-is-commerce-stage20
/opt/alt/php84/usr/bin/php artisan schedule:list
```
Expect exactly three schedule lines: `auctions:tick` (`* * * * *`, overlap
expiry 5), `orders:expire-checkouts` (`* * * * *`, expiry 5),
`refunds:reconcile` (`*/15 * * * *`, expiry 30). Confirm **no**
`referrals:reconcile` line appears (deliberately unscheduled).

**A1 recorder:** `schedule:list` output shows 3 events; frequencies and lock
expiries as expected; `referrals:reconcile` absent.

- [ ] PASS  /  [ ] FAIL

---

**A2 — The commands and their bounds.**
```bash
/opt/alt/php84/usr/bin/php artisan list --raw | grep -E 'auctions:tick|orders:expire-checkouts|refunds:reconcile|referrals:reconcile'
/opt/alt/php84/usr/bin/php artisan help auctions:tick
/opt/alt/php84/usr/bin/php artisan help orders:expire-checkouts
/opt/alt/php84/usr/bin/php artisan help refunds:reconcile
/opt/alt/php84/usr/bin/php artisan help referrals:reconcile
```
Expect: 4 commands present; graphs show `--limit=200` (auctions/orders), `--limit=100` (refunds), default bounds matching the code; `refunds:reconcile --skip-report` documented; `referrals:reconcile --limit=200`.

**A2 recorder:** command list + limits as expected.

- [ ] PASS  /  [ ] FAIL

---

**A3 — Health surface.**
```bash
curl -s -o /dev/null -w '%{http_code}' https://darksalmon-swan-978886.hostingersite.com/up
```
Expect `200`.

**A3 recorder:**

- [ ] PASS  /  [ ] FAIL

---

### Flow B — Cron → `schedule:run` fires on Hostinger (heartbeat proof)

**B1 — The hPanel cron entry.**
Operator reads (or creates) the cron entry in hPanel. Expected form:

```
* * * * * cd /home/u146516859/domains/darksalmon-swan-978886.hostingersite.com/public_html/as-is-commerce-stage20 && /opt/alt/php84/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Confirm:
- frequency every minute (or record the real minimum the plan allows — README's
  deployment verification item);
- the working directory is the app dir, not a parent;
- the PHP binary path is the `/opt/alt/php84/...` path in use, not `/usr/bin/php`;
- the cron's execution timezone is UTC, matching `APP_TIMEZONE=UTC`.

Record exactly what is configured.

**B1 recorder** (paste the cron line + interval observed):

- [ ] PASS  /  [ ] FAIL

---

**B2 — Seed the sweep stamps manually (cold confirmation of the stamp names).**
```bash
/opt/alt/php84/usr/bin/php artisan auctions:tick --limit=200
/opt/alt/php84/usr/bin/php artisan orders:expire-checkouts --limit=200
/opt/alt/php84/usr/bin/php artisan refunds:reconcile --skip-report --limit=100
/opt/alt/php84/usr/bin/php artisan referrals:reconcile --limit=200
```
Expect each exits `0` and writes its stamp. Verify stamps now exist:
```bash
/opt/alt/php84/usr/bin/php artisan tinker --execute="
foreach (['sweeps:auctions_tick:last_run','sweeps:expire_checkouts:last_run','sweeps:reconcile_refunds:last_run','sweeps:reconcile_referrals:last_run'] as \$k) {
  echo \$k.' => '.(Cache::get(\$k) ?? 'MISSING').PHP_EOL;
}"
```
**B2 recorder:** exit codes all `0`; four stamps populated with ISO timestamps.

- [ ] PASS  /  [ ] FAIL

---

**B3 — The scheduled path produces the stamps (proof the cron fires).**
Immediately after B2, `Cache::forget` each stamp, then **do NOT run the commands
manually** — wait up to ~2-3 minutes for hPanel cron to invoke
`schedule:run`, and confirm the stamps repopulate on their own:

```bash
/opt/alt/php84/usr/bin/php artisan tinker --execute="
foreach (['sweeps:auctions_tick:last_run','sweeps:expire_checkouts:last_run','sweeps:reconcile_referrals:last_run'] as \$k) { Cache::forget(\$k); }
echo 'cleared for cron probe'.PHP_EOL;"
sleep 180
/opt/alt/php84/usr/bin/php artisan tinker --execute="
foreach (['sweeps:auctions_tick:last_run','sweeps:expire_checkouts:last_run','sweeps:reconcile_refunds:last_run','sweeps:reconcile_referrals:last_run'] as \$k) {
  echo \$k.' => '.(Cache::get(\$k) ?? 'MISSING').PHP_EOL;
}"
```
Expect: `auctions_tick` and `expire_checkouts` return (they run every minute);
`reconcile_refunds` may or may not within 3 minutes depending on the quarter-hour
boundary — a second probe at the next `*/15` boundary confirms it. (`referrals:reconcile` stays `MISSING` — it is intentionally not scheduled, so its stamp only ever comes from B2's manual run; that is expected, not a failure.)

Also confirm the overlap-lock keys reach the database store with the right
expiries (proving `withoutOverlapping(5/30)` is what Hostinger actually uses):
```bash
/opt/alt/php84/usr/bin/php artisan tinker --execute="
\$rows = DB::table('cache')->where('key','like','laravel-schedule%')->get(['key','expiration']);
foreach (\$rows as \$r) { echo \$r->key.' expires '.date('Y-m-d H:i:s', \$r->expiration).PHP_EOL; }"
```
Expect per-sweep `laravel-schedule-<fingerprint>` keys whose `expiration` is
**+300 s** for `auctions:tick`/`orders:expire-checkouts` and **+1800 s** for
`refunds:reconcile` relative to when the sweep started.

**B3 recorder:** which stamps repopulated by cron alone; observed inter-run gap
(estimate of real minimum cron interval); mutex key expiries 300/1800 observed.

- [ ] PASS  /  [ ] FAIL

---

### Flow C — `auctions:tick` advances a real auction end-to-end via cron

**C1 — A scheduled auction progresses with no manual intervention.**
Using the fixture approach from earlier stages:

1. Take a product with available stock (use the catalogue product list to pick
   one, recording name/id and `on_hand`/`reserved`).
2. Publish an auction on it scheduled to go live ~2 minutes ahead, with a short
   duration so it closes ~3-4 minutes later (settlement checkout deadline far
   enough out to keep it at `PENDING_SETTLEMENT`).
3. Have a controlled test bidder place at least one valid bid while it is LIVE.
4. Without running `auctions:tick` manually, wait for cron to advance it through
   `LIVE → CLOSING → PENDING_SETTLEMENT`.
5. Verify:
```bash
/opt/alt/php84/usr/bin/php artisan tinker --execute="
\$a = App\Models\Auction::where('id', <id>)->firstOrFail();
echo 'status='.\$a->status->value.' winner='.(\$a->winner_user_id ?? 'null').' winning_bid='.(\$a->winning_bid_id ?? 'null').' checkout='.(\$a->status->needsSettlement() ? (\$a->settlementOrder()->count()==1?'one':'none|MANY') : 'n/a').PHP_EOL;" 
```
Expect `status=pending_settlement`, one settlement checkout, exactly one winning
bid, and `highest_bid_credits`/`bid_count` projections consistent with the bid
records (`HighestBidResolver` is authoritative; projections are read alongside,
not trusted alone).

**C1 recorder:** auction id/product snapshot; the timestamps when it entered LIVE,
CLOSING and PENDING_SETTLEMENT (from the activity log) show cron did the work;
winner == highest valid credit bid.

- [ ] PASS  /  [ ] FAIL

---

**C2 — Idempotency and boundedness of the sweep.**
```bash
/opt/alt/php84/usr/bin/php artisan auctions:tick --limit=200
/opt/alt/php84/usr/bin/php artisan auctions:tick --limit=200
/opt/alt/php84/usr/bin/php artisan auctions:tick --limit=1
```
Expect the first line nonzero, the second a no-op (0 advanced), the `--limit=1`
run safe and well-formed, all exit `0`. Then confirm exactly one settlement order
exists for the C1 auction and no double winner (`settlementOrder()->count() == 1`).

**C2 recorder:** outputs and exit codes; settlement order count still `1`.

- [ ] PASS  /  [ ] FAIL

---

**C3 — (Optional) Stale-lock self-healing, demonstrated on staging.**
Replicate `SchedulerMutexTest`'s "interrupted process" scenario without waiting
five minutes: take the running `auctions:tick` event's mutex, create it, confirm
`schedule:run` skips the sweep, then delete the key to emulate expiry and confirm
the very next `schedule:run` sweeps again.

```bash
cd .../as-is-commerce-stage20
/opt/alt/php84/usr/bin/php artisan tinker --execute="
\$sched = app(Illuminate\Console\Scheduling\Schedule::class);
\$ev = collect(\$sched->events())->first(fn(\$e) => str_contains((string) \$e->command, 'auctions:tick'));
\$ev->mutex->create(\$ev);
echo 'lock held: '.( \$ev->mutex->exists(\$ev) ? 'yes' : 'no').PHP_EOL;"
/opt/alt/php84/usr/bin/php artisan schedule:run   # sweep skipped: no auctions:tick line
/opt/alt/php84/usr/bin/php artisan tinker --execute="
\$sched = app(Illuminate\Console\Scheduling\Schedule::class);
\$ev = collect(\$sched->events())->first(fn(\$e) => str_contains((string) \$e->command, 'auctions:tick'));
Cache::forget(\$ev->mutex->key(\$ev));
echo 'lock cleared (emulated expiry)'.PHP_EOL;"
/opt/alt/php84/usr/bin/php artisan schedule:run   # sweep runs again
```
**C3 recorder:** skip observed while held; sweep ran again once cleared.

- [ ] PASS  /  [ ] FAIL  /  [ ] SKIPPED (optional)

---

### Flow D — `orders:expire-checkouts` releases a real abandoned hold

**D1 — A stale hold expires and releases its stock.**
1. Open a catalogue checkout for a product (record `on_hand`/`reserved` before).
2. Backdate the payment window in the database to the past — a test fixture, not
   a business operation:
```bash
/opt/alt/php84/usr/bin/php artisan tinker --execute="
\$o = App\Models\Order::whereIn('status',['pending_payment'])->orderByDesc('id')->first();
\$o->update(['payment_due_at' => now()->subMinutes(30)]);
echo \$o->status->value.' due '.\$o->payment_due_at.PHP_EOL;"
```
3. Run the sweep manually:
```bash
/opt/alt/php84/usr/bin/php artisan orders:expire-checkouts --limit=200
/opt/alt/php84/usr/bin/php artisan orders:expire-checkouts --limit=200   # idempotent second run
```
4. Verify: order no longer accepts payment, its reservation released
   (`products.stock_reserved` back to before, verified through
   `InventoryService`-produced movements: a `Release` movement for that order,
   reason `'Expired...'` per `OrderLifecycle`), Store Wallet applied amount (if
   any) released via the standard release keys, product availability restored
   through `ListingAvailability`.

Expect the two runs to expire it exactly once (second run: 0), all exit `0`.

**D1 recorder:**

- [ ] PASS  /  [ ] FAIL

---

**D2 — An in-window checkout is untouched.**
Confirm a `pending_payment` order whose `payment_due_at` is still in the future
is **not** expired by the sweep (`expire()` not invoked). Record the order number
and re-read it after the sweep.

**D2 recorder:**

- [ ] PASS  /  [ ] FAIL

---

### Flow E — Refund + referral reconciliation (report-only, no invention)

**E1 — `refunds:reconcile` with nothing outstanding.**
```bash
/opt/alt/php84/usr/bin/php artisan refunds:reconcile --skip-report --limit=100
```
Expect `Checked 0 outstanding refund(s); 0 settled.`, exit `0`, stamp updated.
With the report enabled and no refunds eligible for reconciliation:
```bash
/opt/alt/php84/usr/bin/php artisan refunds:reconcile --limit=100
```
Expect `No reconciliation anomalies.` and exit `0` (anomalies would print a
table and exit non-zero — that is the alert, and it must not be "repaired" by
the command). Record that no refund row moved and none was invented.

> Deeper refund-settlement behavior (a `Processing` refund becoming
> `Succeeded` only after the provider confirms) is covered by the unit/feature
> suite; staging proves the command runs bounded and reports honestly with no
> outstanding work.

**E1 recorder:**

- [ ] PASS  /  [ ] FAIL

---

**E2 — `referrals:reconcile` reports and issues nothing.**
```bash
/opt/alt/php84/usr/bin/php artisan referrals:reconcile --limit=200
```
Expect the summary table (`Attributed / Qualified / Rewarded / Not eligible /
Credits issued`) and either `No referral anomalies.` (exit 0) or an anomaly
table + the explicit line `Nothing has been changed. Reconciliation reports; a
person decides.` (exit non-zero). Record exit code and that **no credit
transactions were created** (check `credit_transactions` count before/after).

**E2 recorder:**

- [ ] PASS  /  [ ] FAIL

---

### Flow F — Operations profile / no-daemon posture

**F1 — No worker/daemon dependence.**
Confirm from the staging `.env` (values only, never print secrets):
- `QUEUE_CONNECTION=database`, `NOTIFICATIONS_QUEUE_MAIL=false` (mail is sent
  synchronously — no worker needed);
- `BROADCAST_CONNECTION=null` (polling is authoritative; broadcasting optional);
- `CACHE_STORE=database` (locks + heartbeat stamps survive restarts).

And confirm `/up` (A3) is the monitorable health surface (`/health` API route
also `200` where applicable).

**F1 recorder:** observed env keys + `/up` 200.

- [ ] PASS  /  [ ] FAIL

---

**F2 — No silent drift introduced.**
Confirm the applicated schedule still matches the spec:
```bash
git -C <repo-path-on-this-machine> status
git -C <repo-path-on-this-machine> log --oneline -3
```
No application code changed during Stage 25 unless a defect was found and fixed.
If a defect was found: reproduce locally, fix, run the AGENTS §123 dev commands,
commit, push, re-run the affected staging flow, then record.

**F2 recorder:** git state; list of any code changes (expected: none).

- [ ] PASS  /  [ ] FAIL

---

### Flow G — Decision point: `referrals:reconcile` scheduling

ROADMAP Stage 25 says "refund/**referral** reconciliation are idempotent and
bounded". The implementation deliberately schedules only `refunds:reconcile`;
`referrals:reconcile` is a report-only diagnostic run on demand (its own header
comment states it issues no credits ever and is not on the schedule).

This is a documentation-vs-implementation nuance, not a defect: nothing in
AGENTS §82 requires referral reconciliation to be scheduled, and AGENTS §115
forbids any command from granting rewards on its own. **No code change is made
by this audit.** Record the operator's confirmation that running
`referrals:reconcile` on demand (and by the ops runbook) is the accepted model.

**G recorder:** operator confirms on-demand referrals model accepted.

- [ ] PASS  /  [ ] FAIL

---

## 5. Results

| Flow | Check | Result |
|---|---|---|
| A1 | `schedule:list` → 3 events at `* * * * *`, `* * * * *`, `*/15 * * * *`; no referrals line | |
| A2 | Commands + `--limit` bounds (200/200/100/200); `--skip-report` present | |
| A3 | `/up` → 200 | |
| B1 | hPanel cron entry correct dir + `/opt/alt/php84/...` binary + UTC + interval | |
| B2 | Manual runs exit 0; four `sweeps:*` stamps populated | |
| B3 | Stamps repopulate by cron alone; mutex keys @ +300s/+1800s | |
| C1 | Scheduled auction reached PENDING_SETTLEMENT via cron; winner == highest valid bid; one settlement checkout | |
| C2 | Double `auctions:tick` idempotent; `--limit=1` safe; exit codes 0 | |
| C3 | Stale-lock skip + self-heal (optional) | |
| D1 | Abandoned checkout expired once; stock released via movements; wallet released on the standard keys | |
| D2 | In-window checkout untouched | |
| E1 | `refunds:reconcile` runs bounded; no invention; clean exit when clean | |
| E2 | `referrals:reconcile` reports; zero credit transactions created | |
| F1 | `QUEUE=database`, `NOTIFICATIONS_QUEUE_MAIL=false`, `BROADCAST_CONNECTION=null`, `CACHE_STORE=database` | |
| F2 | No code drift; git state clean | |
| G | On-demand referrals model confirmed | |

---

## 6. Gate close

**Verdict: <PASS / FAIL>**

Blocker rule: any **FAIL** blocks the gate. A **FAIL** means: reproduce locally,
fix in source, test, commit, push, re-verify the failing flow on staging, then
re-open the gate block below.

**Gate closed:** `VERIFY_25_SCHEDULER_OPS_AUDIT` — `main` at
`<commit>` on `<date>` — all flows PASS. Next: **Stage 26** per `docs/ROADMAP.md`.

---

## 7. Completion report template

When the flow finishes, the final commit records:

- **Changed:** files/commits touched.
- **Why:** the business/technical reason.
- **Tests:** exact Pest/PHPStan/Pint commands and counts run.
- **Verification:** which staging flows were observed and how.
- **Database:** whether migrations/schema changed (expected: none).
- **Deployment:** whether a tag/deploy was produced (expected: none — docs only,
  unless a code defect was fixed).
- **Remaining:** any limitation or unverified behavior (e.g. real minimum cron
  interval if the plan could not be confirmed under a minute; refund
  settlement-while-live not exercised on staging because no refund was in
  flight).