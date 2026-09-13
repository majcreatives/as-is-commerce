# Stage 21 — MariaDB 11.8.9 schema audit (operator runbook)

This is the Stage 21 gate (`docs/ROADMAP.md`): prove the real schema against
the actual staging database `u146516859_asiscomm` on Hostinger MariaDB 11.8.9.

Scope: read-only verification. Nothing here changes or repairs data. Missing or
mismatched objects are reported and triaged — never silently fixed.

**Who runs this:** a human operator over SSH (steps from `AGENTS.md` §86).
The application, its migrations, and `git status` stay untouched on Hostinger.

## Setup

```bash
ssh -p 65002 u146516859@89.116.53.20
cd /home/u146516859/domains/darksalmon-swan-978886.hostingersite.com/public_html/as-is-commerce-stage20
```

Run SQL through the app's own database REPL so no credentials are needed in
this document and the queries hit the connection already in `.env`:

```bash
/opt/alt/php84/usr/bin/php artisan db
```

Paste each SQL block below into that REPL. Copy each output block into the
Results section (end of this file) as evidence.

Record the MariaDB version for the record:

```sql
SELECT VERSION() AS mariadb_version;
```

Expect `11.8.x`.

---

## 0. Migration state

```bash
/opt/alt/php84/usr/bin/php artisan migrate:status --force
```

| Expectation |
|---|
| All 28 migrations listed as **Ran** |
| None listed as **Pending** |
| Order matches: 0001_01_01 framework → … → 2026_09_10_100500 |

If any migration is Pending or the batch order differs, **stop**; do not run
`migrate` to "catch up" while auditing. Record and report.

---

## 1. Tables, engine, collation

```sql
SELECT table_name, engine, table_collation
FROM information_schema.tables
WHERE table_schema = DATABASE()
ORDER BY table_name;
```

| Expectation |
|---|
| **47** tables |
| Every `engine` = `InnoDB` |
| Every `table_collation` = `utf8mb4_unicode_ci` (or a compatible unicode collation) |

Expected tables (from the committed migrations):

users, password_reset_tokens, sessions, cache, cache_locks, jobs, job_batches,
failed_jobs, permissions, roles, model_has_permissions, model_has_roles,
role_has_permissions, activity_log, settings, auction_rulesets,
idempotency_keys, credit_wallets, credit_transactions, credit_lots,
credit_lot_consumptions, cash_wallets, cash_transactions, credit_packages,
credit_purchases, credit_purchase_transitions, payment_webhook_events,
categories, brands, products, inventory_transactions, auctions, bids,
auction_transitions, orders, order_items, order_payments, order_transitions,
notifications, refunds, addresses, deliveries, delivery_transitions, referrals,
store_wallets, store_wallet_transactions, store_wallet_credit_sources.

---

## 2. Money is integer minor units (no decimals)

```sql
SELECT table_name, column_name, column_type
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND (column_type LIKE 'decimal%' OR column_type IN ('float','double'));
```

| Expectation |
|---|
| **Empty result set.** No decimal/float money anywhere. |

Spot-check the core money/credit columns are `bigint unsigned`:

```sql
SELECT table_name, column_name, column_type, is_nullable
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND column_name IN (
    'balance','balance_minor','balance_after','balance_after_minor',
    'amount','amount_minor','original_amount','remaining_amount',
    'credit_amount','price_minor','buy_now_price_minor','settlement_amount_minor',
    'highest_bid_credits','amount_credits','total_minor','payable_minor',
    'subtotal_minor','store_wallet_applied_minor','line_total_minor',
    'unit_price_minor','acquisition_amount_minor','reward_credits'
  )
ORDER BY table_name, column_name;
```

---

## 3. JSON columns and round-trip

### 3a. List JSON columns (MariaDB stores `json` as `longtext` alias)

```sql
SELECT table_name, column_name, column_type
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND column_type IN ('json','longtext')
ORDER BY table_name, column_name;
```

On MariaDB 11.x, `$table->json()` manifests as `longtext` (the `JSON` type is
an alias for `longtext` with a `json_valid` check constraint appended). The
listing above shows all candidates. Confirm the expected columns are present:
`rules_snapshot` (auctions), `pricing_snapshot` (orders), `payload`
(payment_webhook_events), `attribute_changes`/`properties` (activity_log),
`result` (idempotency_keys), `metadata` on ledger/package/purchase/auction/
order/delivery/referral/store-wallet tables, `notification_preferences`
(users), `notifications.data`.

**`notifications.data`** is intentionally declared `TEXT`, not `JSON`. On
MariaDB this manifests as `longtext` too — confirm via the column type listing
that it is NOT `json`; the presence or absence of a `json_valid` check on it
should differ from the JSON-declared columns (see §4).

### 3b. json_valid check constraints (MariaDB's JSON enforcement)

```sql
SELECT table_name, constraint_name, check_clause
FROM information_schema.check_constraints
WHERE constraint_schema = DATABASE()
  AND check_clause LIKE '%json_valid%';
```

| Expectation |
|---|
| One `json_valid(...)` check constraint per JSON-declared column (roughly 19) |
| `notifications.data` should **not** appear (it is TEXT, not JSON) |

### 3c. Engine-level round-trip (TEMPORARY table, no FK dependency)

```sql
DROP TEMPORARY TABLE IF EXISTS audit_json_probe;
CREATE TEMPORARY TABLE audit_json_probe (payload JSON);
INSERT INTO audit_json_probe VALUES (JSON_OBJECT('version', 3));
SELECT payload->>'$.version' AS round_trip_result FROM audit_json_probe;
DROP TEMPORARY TABLE audit_json_probe;
```

| Expectation |
|---|
| `round_trip_result` = `3` |
| This proves the MariaDB JSON engine accepts JSON writes and reads back correctly |
| TEMPORARY table is dropped automatically when the session ends — no residual data |

---

## 4. Named CHECK constraints

List all check constraints:

```sql
SELECT tc.table_name, tc.constraint_name
FROM information_schema.table_constraints tc
WHERE tc.table_schema = DATABASE()
  AND tc.constraint_type = 'CHECK'
ORDER BY tc.table_name, tc.constraint_name;
```

Expect roughly **60** `chk_*` constraints, including:

- orders: `chk_orders_status`, `chk_orders_payable_positive`,
  `chk_orders_payable_consistent`, `chk_orders_store_wallet_within_total`,
  `chk_orders_store_wallet_buy_now_only`, `chk_orders_total_consistent`,
  `chk_orders_discount_within_subtotal`, `chk_orders_auction_win_pairing`,
  `chk_orders_no_settlement_discount`
- auction_rulesets: `chk_rulesets_base_duration`, `chk_rulesets_checkout_deadline`,
  `chk_rulesets_tax_bps`, `chk_rulesets_closing_window`, `chk_rulesets_status`,
  `chk_rulesets_minimum_bid`, `chk_rulesets_minimum_increment`
- auctions: `chk_auctions_status`, `chk_auctions_closure_reason`,
  `chk_auctions_settlement_positive`, `chk_auctions_highest_bid_positive`,
  `chk_auctions_ends_after_start`, `chk_auctions_winner_pairing`,
  `chk_auctions_buy_now_pairing`, `chk_auctions_buy_now_has_no_bid_winner`
- bids: `chk_bids_amount_positive`, `chk_bids_status`
- credit/cash/inventory/store wallets, credit lots, credit purchases, packages,
  products, categories, brands, order_items, order_payments, refunds,
  deliveries, delivery_transitions, referrals, store_wallet_transactions,
  store_wallet_credit_sources (full list from previous audit)

### Critical negative test — dropped constraints must be gone

```sql
SELECT tc.table_name, tc.constraint_name
FROM information_schema.table_constraints tc
WHERE tc.table_schema = DATABASE()
  AND tc.constraint_name IN (
    'chk_rulesets_bid_cost',
    'chk_rulesets_checkout_price',
    'chk_rulesets_discount_rate'
  );
```

| Expectation |
|---|
| **Empty.** These three were migrated out (legacy auction model). Any of them present means the schema is in a rolled-back or partially-applied state — **stop and report; do not proceed to Stage 22.** |

---

## 5. Unique / idempotency constraints

```sql
SHOW CREATE TABLE idempotency_keys \G
```

Find the **unique** index. Expect:

| Idempotency key shape | Status |
|---|---|
| `UNIQUE (operation, user_id, idempotency_key)` | **Correct (live)** |
| `UNIQUE (operation, idempotency_key)` | **Wrong/old** — report |

Sanity-check the other idempotency/protection uniques:

```sql
SELECT table_name, index_name, non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND non_unique = 0
  AND (index_name LIKE '%unique%' OR column_name IN (
    'provider_event_id','idempotency_key','reference','order_number',
    'sku','slug','referred_user_id','event_key','sequence',
    'credit_transaction_id','referral_code'
  ))
GROUP BY table_name, index_name, non_unique
ORDER BY table_name, index_name;
```

Expect (each with unique=0):

- `payment_webhook_events`: unique on `(provider, provider_event_id)`
- `credit_transactions` / `cash_transactions` / `store_wallet_transactions`:
  unique `idempotency_key` (nullable — multiple NULLs allowed by MySQL/MariaDB)
- `credit_purchases`: unique `provider_reference`, unique `idempotency_key`
- `order_payments`: unique `provider_reference`, unique `provider_transaction_id`,
  unique `idempotency_key`
- `bids`: unique `(auction_id, sequence)`, unique `idempotency_key`,
  unique `credit_transaction_id`
- `referrals`: unique `referred_user_id`, unique `credit_transaction_id`
- `deliveries`: unique `order_id`, unique `reference`
- `orders`: unique `order_number`
- `users`: unique `phone`, unique `email`, unique `referral_code`
- `products`: unique `sku`, unique `slug`
- `categories` / `brands`: unique slugs/name
- `notifications`: unique `event_key`
- `permission` / `role` tables: `(name, guard_name)`
- `settings`: unique `key`
- `credit_packages`: unique `slug`
- wallet `user_id` 1:1 uniques

---

## 6. Foreign keys

```sql
SELECT kcu.table_name, kcu.column_name, kcu.constraint_name,
       kcu.referenced_table_name, kcu.referenced_column_name,
       rc.update_rule, rc.delete_rule
FROM information_schema.key_column_usage kcu
JOIN information_schema.referential_constraints rc
  ON rc.constraint_schema = kcu.constraint_schema
 AND rc.constraint_name   = kcu.constraint_name
WHERE kcu.constraint_schema = DATABASE()
  AND kcu.referenced_table_name IS NOT NULL
ORDER BY kcu.table_name, kcu.column_name;
```

Spot-check these high-value rules (from the committed migrations):

| FK | Expected behavior |
|---|---|
| `credit_wallets.user_id → users.id` | CASCADE |
| `credit_lots.credit_transaction_id → credit_transactions.id` | RESTRICT |
| `credit_lot_consumptions.credit_lot_id / credit_transaction_id` | RESTRICT |
| `bids.credit_transaction_id → credit_transactions.id` | RESTRICT |
| `auctions.product_id → products.id` | RESTRICT |
| `auctions.winner_user_id / buy_now_user_id → users.id` | RESTRICT |
| `orders.user_id / winning_bid_id / auction_id` | RESTRICT |
| `order_payments.order_id → orders.id` | RESTRICT |
| `refunds.order_id / order_payment_id → orders / order_payments` | RESTRICT |
| `referrals.referrer_user_id / referred_user_id → users.id` | RESTRICT |
| `deliveries.order_id → orders.id` | RESTRICT (and unique — §5) |
| `inventory_transactions.product_id → products.id` | RESTRICT |
| auction / order / refund lifecycle `caused_by / requested_by → users.id` | NULL ON DELETE |
| permission pivot tables | CASCADE |

`sessions.user_id` is intentionally index-only (no FK) — that is expected.

---

## 7. `FOR UPDATE` (concurrency path) smoke check

Two checks. First, a rolled-back transaction on a real `settings` row:

```sql
START TRANSACTION;
SELECT id FROM settings WHERE id = 1 FOR UPDATE;
ROLLBACK;
```

Second, the same through the application's locking path (rolled back — writes
nothing, throws to prove rollback):

```bash
/opt/alt/php84/usr/bin/php artisan tinker --execute="
DB::transaction(function () {
    \$row = DB::table('settings')->lockForUpdate()->first();
    dump(['locked_row' => \$row->key ?? 'none']);
    throw new \RuntimeException('rollback-only audit probe');
});
" 2>&1 | tail -5
```

| Expectation |
|---|
| The tinker probe shows `locked_row` with the first setting's key |
| It ends in `RuntimeException: rollback-only audit probe` (intentional) — proves `SELECT … FOR UPDATE` ran and the transaction rolled back cleanly |

If either check hangs, errors, or blocks, the locking path is broken on
MariaDB — **stop and report.**

---

## 8. Direction-critical index

```sql
SHOW CREATE TABLE bids \G
```

Inspect `bids_highest_bid_index`. Expect the **mixed-direction** form:

```text
KEY `bids_highest_bid_index` (`auction_id`,`amount_credits` DESC,`sequence`)
```

| Expectation |
|---|
| `amount_credits` is `DESC`; `sequence` is ASC |
| If the index is plain ASC (or missing), the highest-bid resolver loses its single-scan guarantee — report |

---

## 9. Append-only / freeze triggers

```sql
SELECT trigger_name, event_object_table, event_manipulation
FROM information_schema.triggers
WHERE trigger_schema = DATABASE()
ORDER BY event_object_table, trigger_name;
```

Expect the append-only (no-update / no-delete) protections and the freeze
guards, including but not limited to:

- No-update / no-delete guards on the audit/ledger append-only tables
  (`credit_transactions`, `credit_lot_consumptions`, `cash_transactions`,
  `inventory_transactions`, `bids`, `auction_transitions`, `order_transitions`,
  `delivery_transitions`, `store_wallet_transactions`)
- `auctions_frozen_configuration` (rules / settlement frozen after draft)
- `orders_frozen_after_payment`
- `order_payments_frozen_request`
- `deliveries_frozen_address`
- `refunds_frozen_request`
- `referrals_frozen_relationship`, `referrals_rewarded_no_delete`
- `credit_lots_acquisition_frozen`

| Expectation |
|---|
| All confirmed present on MariaDB; the freeze / append-only invariants are enforced by the database, not only by application code |

---

## 10. Gate close

- Compile every output block into the **Results** section below.
- Mark each audit point **PASS** or **FAIL**.
- **PASS all** → Stage 21 gate closes; proceed to Stage 22 (Hostinger
  application / bootstrap verification: auth, admin login, `/health`, `/up`,
  `APP_DEBUG=false`, `config:show paystack`).
- **Any FAIL or blocked item** → do not proceed. Record the evidence, triage
  the discrepancy (never silently repair schema / data), and return.

---

## Results

> Paste each query's output here with the audit-point label and a PASS/FAIL
> verdict, e.g.:
>
> - 0. Migrations — **PASS** (28/28 Ran)
> - 1. Tables / engine / collation — **PASS** (47 InnoDB, utf8mb4_unicode_ci)
> - 2. Integer minor units — **PASS** (0 decimal columns)
> - 3. JSON columns and round-trip — **PASS** (longtext/json_valid present; temp-table round-trip = 3)
> - …
>
> Verdict: **PASS / FAIL / NEEDS REVIEW**