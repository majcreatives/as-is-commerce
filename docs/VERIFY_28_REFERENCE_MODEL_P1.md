# Verify 28 — Reference-model positioning (P1)

| | |
|---|---|
| **Work item** | **P1 — Reference-model positioning** (see `docs/REFERENCE_MODEL.md`; ROADMAP "Reference-model reframe") |
| **Model** | Full e-commerce store with a gamified credit auction channel; shop primary, auctions a controlled channel |
| **Risk** | Low |
| **Baseline commit (start)** | `25091df` |
| **Runbook author** | AI agent (OpenCode) |
| **Approved by** | Operator (business owner) — wireframe for the folded homepage approved before implementation |
| **AGENTS.md anchors** | §2.5 (GitHub in the loop), §10.3/§113 (Highest Bid (Credits), never "Auction Price"), §107 (UI preservation), §108 (visual verification), §124 (diff review), §125 (completion report) |

---

## 1. Purpose

Make the store the visible identity and primary commerce of the site, and the
auction channel a controlled experience layered over it — without touching a
single financial, credit, payment, inventory or auction engine rule. P1 is
**copy, labels and layout only**: no migrations, no business-logic changes, no
new fields.

## 2. Scope (changed)

| # | File | Change |
|---|---|---|
| 1 | `docs/REFERENCE_MODEL.md` | New — locks the store-first model and the roles of Shop / Auction / Store Wallet / Credits / Paystack / Admin |
| 2 | `resources/views/livewire/marketplace/home.blade.php` | Hero rewritten shop-first; **standalone "Closing soonest" auction band and the two-path cards removed**; "In the shop" grid promoted to the primary section; a live auction is shown on its product's own card |
| 3 | `app/Livewire/Marketplace/Home.php` | Drops the now-unused auction band data (`endingSoonest`, `openCount`, `remaining`); view passes only `featured` + `availability` |
| 4 | `AGENTS.md` | Product label → "Gamified E-Commerce Marketplace with a Credit Auction Channel" |
| 5 | `README.md` | Intro rewritten store-first |
| 6 | `docs/ROADMAP.md` | Added the "Reference-model reframe" (P1/P2) record |
| 7 | `AGENTS-READ.md`, `CLAUDE.md` | Label mirrors updated |
| 8 | `resources/views/partials/footer.blade.php` | Tagline store-first |
| 9 | `app/Domain/Auction/Actions/CompleteBuyNow.php` | Stale "NOTHING CALLS THIS YET" comment corrected (it is called via `FulfillOrderPayment` for auction Buy Now orders) |
| 10 | `app/Domain/Catalog/Services/InventoryService.php` | Stale "nothing calls them yet" comment corrected (Reservation/Release/Sale are the shop + auction acquisition paths) |
| 11 | `tests/Feature/Marketplace/MarketplaceDiscoveryTest.php` | Homepage tests updated to the folded structure |

## 3. Explicitly NOT in scope (verified unchanged)

- Auction state machine, rules snapshots, settlement, reservation lifecycle,
  Store Wallet scope, order lifecycle, payment verification, idempotency.
- Product/shop/auction engine code except the two comment corrections in #9/#10
  (non-behavioral).
- **P2 (auction eligibility gate) and P3 (cart)** are documented and gated
  separately (`docs/REFERENCE_MODEL.md` §6); nothing here implements them.

## 4. Verification

### Local

1. `vendor/bin/pint` — clean.
2. `php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G`
   — 0 errors.
3. Focused Pest: `tests/Feature/Marketplace/MarketplaceDiscoveryTest.php`
   (homepage, cards, availability, SEO, auth pages).
4. Broader Pest suite where time allows (no engine code changed, so regression
   surface is small).

### Staging (per AGENTS §108 — visual, not inferred)

1. Deploy the pushed `stage*` tag via the GitHub Actions release zip (operator).
2. Homepage `/`: hero states the shop first; "In the shop" grid is the primary
   section; **no** "Closing soonest" band; a live-auctioned product shows the
   auction inline on its card; CTA labels "Shop now" / "Explore auctions".
3. Product detail `/products/{slug}`: unchanged behavior (auction-held product
   still offers "View the auction").
4. Shop `/products`: unchanged (auction badges inline, already shipped).
5. Auction room `/auctions/{auction}`: unchanged.
6. Footer tagline store-first; nav still Home · Shop · Auctions · How It Works.

## 5. Results

| # | Check | Result |
|---|---|---|
| 1 | Homepage: hero shop-first, shop grid primary, no auction band | PENDING |
| 2 | Auction surfaces inline on its product's card | PENDING |
| 3 | Product detail / shop / auction room unchanged | PENDING |
| 4 | Labels: AGENTS.md, README, ROADMAP, mirrors, footer | PENDING |
| 5 | Stale-comment corrections non-behavioral | PENDING |
| 6 | Pint + PHPStan + focused Pest pass | PENDING |

## 6. Gate close

**Verdict: PENDING**

Blocker rule: any **FAIL** blocks the gate (fix in source, retest, redeploy,
re-verify). **P1 doesn't close by itself** — it proceeds to **P2 (auction
eligibility) only after the production-prep gate is met**, per the operator's
sequencing decision.

**Gate closed:** `P1_REFERENCE_MODEL` — `main` at `<commit>` on `<date>` —
tests + staging visual checks PASS, production prep gate met.

## 7. Completion report

- **Changed:** the files in §2.
- **Why:** the reference-model reframe (`docs/REFERENCE_MODEL.md`): store first,
  auction as a controlled channel.
- **Tests:** list the exact suites run.
- **Verification:** local + staging visual checks, per flow.
- **Database:** none.
- **Deployment:** `stage*` tag → release zip → staging.
- **Remaining:** P2 eligibility gate and P3 cart are separate, gated, later
  work items, not part of this stage.