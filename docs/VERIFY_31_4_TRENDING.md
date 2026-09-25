# 31.4 — Homepage Trending (deploy and verification runbook)

Stage 31.4 of `PLAN_31_UX_BIDCAP_SHARING.md`. Presentational and read-only: no
schema, no economics, and nothing on any purchase, bid, payment, inventory or
auction path changes. The homepage gains a second product section and the
existing one is renamed.

## What ships

1. **Trending section** (`resources/views/livewire/marketplace/home.blade.php`),
   directly under the shop grid: eight products that people have actually
   engaged with lately, through `x-product-card` like every other card. With no
   activity in the window it shows the honest empty state
   (*Not enough activity yet*) rather than being hidden or padded.
2. **`ProductDiscoveryQuery::trending()`** — a new read method, and nothing
   else in the class changed. It scores each product from two record sources and
   returns at most `$limit` of them:
   - units on `order_items` whose order is in a paid status
     (`OrderStatus::isPaid()`, read from the enum rather than re-declared),
     dated by `orders.paid_at`;
   - accepted `bids` on auctions in `Live`, `Closing` or `Scheduled` — the same
     status list `availabilityFor()` uses, so a finished auction leaves no echo.

   Both are filtered to the trailing window, summed per product, and ranked by
   total desc then product id desc so the same activity always produces the same
   list (a total order, so the sort does not lean on PHP's sort stability).
   Ranking happens in PHP, the final `ORDER BY FIELD(id, …)` keeps that order
   through MySQL/MariaDB, and every query starts from `publiclyVisible()` so a
   product that stopped being publicly visible simply drops out.
3. **"In the shop" → "Newly added"** — the existing `featured()` section,
   renamed per the plan. Same query, same behaviour, copy only.
4. **One batched availability pass** — `Home::render()` now calls
   `availabilityFor($featured->concat($trending))` once for the whole page, so
   the extra section adds no per-card query. Availability is still decided by
   `ListingAvailability`; old activity never makes a product look available.
5. **`homepage_trending_window_days` setting** (default `30`, group
   `homepage`, not public), read through `settings()->getInt()`. The window is
   floored at 1 day, so the list is never read as "right now" and a
   misconfigured value cannot produce an unbounded query.

### What it deliberately is not

No analytics, no view/behaviour tracking, no seeded or placeholder rows, no
scarcity or "X people are looking" claims. Every product shown is there because
an order was paid or a bid was accepted — the section is a reading of the
ledger, not a marketing figure.

## Gates (local, run on this working tree)

- `./vendor/bin/pint --test` — pass.
- `./vendor/bin/phpstan analyse --memory-limit=1G` — 0 errors.
- `php artisan test tests/Feature/Marketplace/MarketplaceDiscoveryTest.php`
  — 32 pass (90 assertions), including four new tests: the empty case, ranking
  by real paid units and real accepted bids, the window actually bounding
  results (30-day default excludes a 45-day-old purchase; 60 includes it), and
  a recently bought product surfacing in the rendered section.
- `php artisan test tests/Feature/Marketplace/CompanyPagesTest.php`
  — 41 pass (182 assertions).
- `php artisan test tests/Feature/Marketplace/MarketplaceDiscoveryTest.php
  tests/Feature/Admin` — 398 pass (1378 assertions).
- **Full MySQL-backed suite: 2,426 tests, 2,426 passed, 7,500 assertions.**

## Database

**No migration.** `orders.paid_at`, `bids.status` and `order_items.product_id`
all already exist. The new setting arrives through the idempotent, additive
`SettingsSeeder` (`firstOrNew` on `key`, structural fields refreshed, value
seeded once), the established path for settings — the same one
`notification_badge_window_days` used. Re-running the seeder on staging is what
adds the row; until then the read falls back to 30 and the page behaves
correctly either way.

## Release

- Commit `4cfa2d8`, tag `stage31.4`; GitHub Actions `build-deploy.yml`
  produced `as-is-commerce-stage31.4.zip` on the `stage31.4` Release
  (13,931,960 bytes, SHA-256
  `26848a385c82a4e3b034a3d24fefe3f9c80d26a2c375e0a8c6cd61aa3b5d05f8`).
- The zip digest was checked on the server *before* extraction and matched.

## Deploy (staging, 2026-09-25)

Directory swap as before (`DEPLOYMENT.md` §2a):

- Live dir → `as-is-commerce-stage20.bak-31.4` (earlier backups retained);
  `.env` preserved (`APP_ENV=staging`, `APP_DEBUG=false`, `APP_KEY` present).
- `storage/app` carried across — 22 files before, 22 after.
- `storage:link` recreated, `config:cache` / `route:cache` / `view:cache`
  rebuilt, `migrate --force` — "Nothing to migrate" (no schema change).
- `db:seed --class=SettingsSeeder --force` — added
  `homepage_trending_window_days` (`30`, integer, group `homepage`); settings
  rows 14 → 15. Re-running the seeder refreshed the structure of the existing
  rows and left every administrator-set value alone.
- `about` — Laravel 13.30.1, PHP 8.4.21, `staging`, debug off.

## Verified on staging

| Check | Result |
|---|---|
| `GET /` | 200, 40,225 B |
| Homepage `h2` order | *Newly added* → *Trending* (8 cards, then 6) |
| *In the shop* gone from the homepage | yes (0 occurrences) |
| Trending cards match the record-derived ranking exactly | yes — ids 10, 6, 7, 1, 4, 3, in that order |
| Tie-break behaves as documented (score desc, then id desc) | yes — 4/4 → 10 before 6; 2/2 → 7 before 1; 1/1 → 4 before 3 |
| Every trending product publicly visible | 6 of 6 |
| Trending ids reproduced independently from the ledger (units + accepted bids) | yes, scores 4, 4, 2, 2, 1, 1 |
| Empty state present in markup but not shown (real activity exists) | correct — *Not enough activity yet* absent, as it should be with 6 products |
| Window read from settings, not hard-coded | `homepage_trending_window_days` = 30 |
| Admin settings screen picks up the new group | yes — groups now include **Homepage (1): homepage_trending_window_days** |
| `GET /health`, `/up`, `/products`, `/login`, `/how-it-works`, `/auctions/20`, a product page | all 200 |
| No stack trace, `APP_DEBUG`, Paystack key, `DB_PASSWORD` or `APP_KEY` in rendered HTML | 0 occurrences |
| `storage/logs/laravel.log` | empty — no errors during or after the swap |

### What staging data actually showed

The section is not decorative: 47 orders / 54 order lines / 77 bids / 20
auctions are live on staging, and the six products shown are exactly the ones
those records point at —

| # | Product | Paid units (30d) | Accepted bids (30d) | Score |
|---|---|---|---|---|
| 10 | Lenovo W540 WorkStation Laptop | 0 | 4 | 4 |
| 6 | Kwaku Studio Over-Ear Headphones | 4 | 0 | 4 |
| 7 | Fast Charger 65W USB-C | 2 | 0 | 2 |
| 1 | Northline N7 Smartphone 128GB | 2 | 0 | 2 |
| 4 | Northline Work 14 Ultrabook | 1 | 0 | 1 |
| 3 | Ashanti Forge 15 Gaming Laptop | 1 | 0 | 1 |

Both sources are visible in real use: the top row is an auction that has taken
bids, the second is a product that has sold units. Neither a product with no
activity nor a finished auction appears.

## Left for the human (not automatic)

- Partners, Success Stories and the newsletter CTA are the rest of 31.4 and are
  still not started.
- "Trending" wording is deliberately plain. If it is ever reworded, it must stay
  a statement about recorded activity — never a claim about popularity,
  interest, or what other people are doing right now.
- A product that has sold drops off the list as soon as the payment falls
  outside the window. That is intended, but it means the section can empty out
  on a quiet store; the empty state is written for exactly that.
- No click, view or search tracking was added, so "trending" measures completed
  purchases and accepted bids only — not browsing interest. Adding analytics
  would be a separate, explicit decision.

## Rollback

Swap `as-is-commerce-stage20.bak-31.4` back and re-run the three caches
(`DEPLOYMENT.md` §2a). No migration to reverse. The seeded setting row may be
left in place; it is inert once the code that reads it is gone.
