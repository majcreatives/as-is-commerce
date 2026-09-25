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
  produced `as-is-commerce-stage31.4.zip` on the `stage31.4` Release.
- The zip digest was checked on staging before extraction.

## Deploy (staging)

Directory swap as before (`DEPLOYMENT.md` §2a): live dir renamed to
`.bak-31.4` (earlier backups retained), `.env` and `storage/app` carried across,
`storage:link` recreated, the three caches rebuilt, `migrate --force` a no-op,
then `db:seed --class=SettingsSeeder --force` so the admin settings screen shows
the new group.

## Verified on staging

_(filled in after the deploy — see the results table below)_

## Left for the human (not automatic)

- Partners, Success Stories and the newsletter CTA are the rest of 31.4 and are
  still not started.
- "Trending" wording is deliberately plain. If it is ever reworded, it must stay
  a statement about recorded activity — never a claim about popularity,
  interest, or what other people are doing right now.

## Rollback

Swap `as-is-commerce-stage20.bak-31.4` back and re-run the three caches
(`DEPLOYMENT.md` §2a). No migration to reverse. The seeded setting row may be
left in place; it is inert once the code that reads it is gone.
