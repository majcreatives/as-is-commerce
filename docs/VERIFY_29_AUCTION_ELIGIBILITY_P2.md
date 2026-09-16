# Verify 29 — Auction-channel eligibility gate (P2)

| | |
|---|---|
| **Work item** | **P2 — Auction-channel eligibility gate** (see `docs/REFERENCE_MODEL.md` §4.3; ROADMAP "Reference-model reframe") |
| **Model** | Full e-commerce store with a gamified credit auction channel; **admin explicitly opts inventory into the auction channel** |
| **Risk** | Low–Medium (new schema column, enforced by `CreateAuction`; no financial-engine rule touched) |
| **Baseline commit (start)** | `fe6c58f` (P2 implementation) |
| **Runbook author** | AI agent (OpenCode) |
| **Approved by** | Operator (business owner) — P2 proceeds per the recorded sequencing decision in `docs/VERIFY_28_REFERENCE_MODEL_P1.md` §6 |
| **AGENTS.md anchors** | §2.5 (GitHub in the loop), §30 (product states), §67 (admin actions use domain services), §101 (domain logic in `app/Domain`), §107 (UI preservation), §108 (visual verification), §117 (DB constraints), §124 (diff review), §125 (completion report) |

---

## 1. Purpose

The auction channel is a **separate distribution channel with its own gate**.
A catalogue listing (and a Buy Now price) is not entitlement to be auctioned:
publication into the auction channel is a deliberate, recorded act by an
administrator. `products.auction_eligible` (default `false`) is that opt-in,
and auction creation refuses any product that was never marked eligible.

## 2. Scope (changed, commit `fe6c58f`)

| # | File | Change |
|---|---|---|
| 1 | `database/migrations/2026_09_16_100000_add_auction_eligibility_to_products.php` | New — `auction_eligible` boolean, default `false`, `after('status')`, MariaDB `CHECK (auction_eligible IN (0, 1))` |
| 2 | `app/Models/Product.php` | Fillable + boolean cast for `auction_eligible` |
| 3 | `app/Domain/Auction/Actions/CreateAuction.php` | Gate: refuses any product where `auction_eligible` is false (after the archive check) |
| 4 | `app/Livewire/Admin/Auctions/AuctionManager.php` | Product picker offers only `auction_eligible = true` products |
| 5 | `app/Livewire/Admin/Catalog/ProductManager.php` | `$auction_eligible` property + `boolean` validation + persisted on create/save |
| 6 | `resources/views/livewire/admin/catalog/product-manager.blade.php` | New "Opt in to the auction channel" checkbox |
| 7 | `database/factories/ProductFactory.php` | Factory default `auction_eligible => true` (test funnel) + explicit `auctionIneligible()` state |
| 8 | `tests/Feature/Auction/AuctionCreationTest.php` | 2 new tests: refuses never-opted-in product; archive gate still wins over eligibility |
| 9 | `tests/Feature/Auction/AuctionEngineRegressionTest.php` | Guard amended: `auction_eligible` is the one deliberate "auction" column on `products`; `credit`/`bid`/`wallet` still forbidden |

## 3. Explicitly NOT in scope (verified unchanged)

- Auction state machine, rules snapshots, settlement, reservation lifecycle,
  Store Wallet scope, order lifecycle, payment verification, idempotency.
- P1 copy/labels/positioning; P3 cart.
- Fixing or repricing any financial rule.

## 4. Verification

### Local (done, 2026-09-16)

1. `vendor/bin/pint` — clean.
2. `vendor/bin/phpstan analyse --memory-limit=512M` — 0 errors.
3. Focused Pest: `tests/Feature/Auction/AuctionCreationTest.php`,
   `tests/Feature/Auction/AuctionEngineRegressionTest.php`.
4. Broader Pest: full `tests/Feature/Auction` (354 tests) + Catalog/UI
   (`ProductTest`, `CatalogUiTest`, `AuctionUiTest`) — all PASS.

### Staging (per AGENTS §108 — visual, not inferred; operator)

> **Deploy target — read before touching the shell (operator).** Serve the
> `stage*` release zip into the application **subdirectory**
> (`as-is-commerce-stage20/public/`) per the §88 rewrite and §87 paths — see
> `docs/DEPLOY_STAGE23_2.md` for the exact steps and zip naming.

1. Deploy the pushed `stage*` tag via the GitHub Actions release zip (operator).
2. Run the new migration on staging (`php artisan migrate` during deploy).
3. Admin → Catalog → create a product: the "Opt in to the auction channel"
   checkbox is present and **unchecked by default**; saving leaves the product
   auctionable to the shop only.
4. Admin → Catalog → edit an existing product: checkbox reflects the saved
   value and toggles persist.
5. Admin → Auctions → create: the product picker lists only opted-in products;
   a shop-only product is not offered.
6. Backend: attempting auction creation for a non-opted-in product is refused
   by `CreateAuction` (the picker already hides it; the gate is authoritative).

## 5. Results

| # | Check | Result |
|---|---|---|
| 1 | Migration `products.auction_eligible` default `false` + CHECK | PASS (staging 2026-09-16 — `SHOW COLUMNS`: `auction_eligible` `tinyint(1)` default `'0'`; migration ran 30ms) |
| 2 | Pint + PHPStan clean | PASS (local, 2026-09-16) |
| 3 | Focused + full Auction suite + Catalog/UI 354 + 166 tests | PASS (local, 2026-09-16) |
| 4 | Staging: checkbox present, default unchecked (admin browser) | PASS (operator visual confirmation, 2026-09-16) |
| 5 | Staging: picker lists only opted-in products (admin browser) | PASS (operator visual confirmation, 2026-09-16) |
| 6 | Staging: backend refusal for non-opted-in product | PASS (staging 2026-09-16 — draft, non-opted-in product refused by `CreateAuction`: "This product is not marked as eligible for the auction channel.") |

## 6. Gate close

**Verdict: PASS.**

All six checks are green: the new column + migration, the local suites, and on
staging both the authoritative `CreateAuction` refusal and the two operator
visual confirmations (admin Product form "Opt in to the auction channel"
checkbox present and unchecked by default; the auction-creation picker offers
only opted-in products). Deployed tree confirmed (code markers present; zip
SHA-256 matched the GitHub Release digest). No FAIL.

**Gate closed:** `P2_AUCTION_ELIGIBILITY` — deployed `stage29.1` (build from
`d33aab1`) on `2026-09-16`; docs recorded at `main` `01b0e5b`. **Verdict
recorded: PASS.**

Blocker rule: any **FAIL** blocks the gate (fix in source, retest, redeploy,
re-verify). P2 closes here; it proceeds to the separately-gated P3 (cart) only
once that work item is approved.

## 7. Completion report

- **Changed:** the files in §2.
- **Why:** the auction channel is opt-in (`docs/REFERENCE_MODEL.md` §4.3);
  cataloging a product grants nothing about the auction channel on its own.
- **Tests:** exact suites in §4.1.
- **Verification:** local PASS; staging — migration/column, engine refusal and
  the two operator visual confirmations all PASS (2026-09-16).
- **Database:** new additive migration (default `false`), MariaDB-friendly;
  applied on staging.
- **Deployment:** `stage29.1` tag → GitHub Actions release zip (SHA-256
  verified) → extracted into the served app subdir; caches rebuilt; smoke
  checks green.
- **Remaining:** none for P2. Optional hygiene: remove staging backups
  (`as-is-commerce-stage20.bak-29.1`, `.env.bak-stage29.1`, `temp-stage29.1`)
  after the next deploy. P3 cart is a separate, later work item.