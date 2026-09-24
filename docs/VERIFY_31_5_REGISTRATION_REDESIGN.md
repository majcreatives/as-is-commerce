# 31.5 — Registration redesign (deploy and verification runbook)

Companion to `PLAN_31_UX_BIDCAP_SHARING.md` §31.5. This release is
presentational only: no schema, no economics, and `Register.php` behaviour
unchanged except `render()` opting the page into the widened guest layout. The
registration model's validation, phone canonicalisation, referral attribution
and OTP wiring are untouched (the 11 behavioral tests in
`tests/Feature/Auth/RegistrationTest.php` pass as they were).

## What ships

1. **Two-column register page on desktop, single column on phones**
   (`resources/views/livewire/auth/register.blade.php`): the form in an `x-card`
   on one side, a truthful "what you get" panel (shop in cedis, auctions bid
   with credits, built for Ghana) on the other. No copy promises earnings or a
   reward, and a `?ref` referrer is never named.
2. **Show/hide password toggle** on both password fields — a new
   `resources/views/components/password-input.blade.php` component. Pure Alpine
   (`x-data`/`x-bind:type`); no JS added; the value stays bound to the Livewire
   property exactly as before.
3. **Clearer phone helper** copy under the phone field.
4. **Widened guest layout for this page only** — `layouts/guest.blade.php`
   reads `($wide ?? false)` and the other three auth pages (Login, Forgot,
   Reset) keep `max-w-md`.
5. **The consent line is deliberately absent** — it links to the 31.3 legal
   pages (`/privacy`, `/terms`, `/cookies`), which are not built; the roadmap's
   rule is "a link to a page that does not exist is worse than none." The line
   ships together with those pages.

## Gates (local, before the tag)

- `./vendor/bin/pint` — pass.
- `./vendor/bin/phpstan analyse --memory-limit=512M` — 0 errors.
- `php artisan test` — full MySQL-backed suite: **2420 tests, 0 failures
  (7482 assertions)**.

## Release

- Tag `stage31.5` pushed; GitHub Actions `build-deploy.yml` produced
  `as-is-commerce-stage31.5.zip`
  (13,919,068 bytes, SHA-256
  `d3ebf7a2ed30d3985c15020437accff4bcbcccee6a4da7679dcad06af6c08227`).
- The uploaded zip matched that digest on staging before extraction.

## Deploy (staging, 2026-09-24)

Followed `DEPLOY_STAGE30_0.md`'s directory swap + `DEPLOYMENT.md` §2a:

- Live dir → `as-is-commerce-stage20.bak-31.5` (kept until next deploy).
- `.env` preserved (`.env.bak-stage31.5`); `storage/app/public` and `private`
  carried over (public had 12 files).
- `storage:link` recreated, `storage` + `bootstrap/cache` permissions applied.
- `config:cache`, `route:cache`, `view:cache` rebuilt.
- `migrate --force` — "Nothing to migrate" (no schema change in this release).

## Verified on staging

| Check | Result |
|---|---|
| `GET /health` | 200 |
| `GET /up` | 200 |
| `GET /register` | 200, 17,359 B |
| Register HTML has `What you get` panel heading | yes |
| Register HTML has `md:grid-cols-5`, `md:col-span-3`, `md:col-span-2` (two-column) | yes |
| Register HTML has `max-w-3xl` (widened) | yes |
| Password toggle markup on both fields (`x-data="{ show: false }"`, `x-bind:type="show ? 'text'...`, 2 occurrences) | yes |
| Sign-in link still present on register page | yes |
| `login` / `forgot-password` still narrow (`max-w-md`, no `max-w-3xl`) | yes |
| Built CSS contains `md:grid-cols-5`, `md:col-span-3/2`, `max-w-3xl`, `pr-11`, `size-5` | yes |
| Deployed files byte-identical to local commit (SHA-256, 4 app files) | yes |
| `storage/logs/laravel.log` no `ERROR` lines since deploy | yes |
| `credits:expire-unused` still registered + all 4 schedule entries present | yes |

## Left for the human (not automatic)

- Visual rendering at real device widths (desktop two-column, phone
  single-column) and the browser toggle click, which source inspection alone
  cannot prove — open `/register` on staging and confirm before relying on the
  redesign.
- The consent line stays deferred with 31.3's legal pages.

## Rollback

Swap `as-is-commerce-stage20.bak-31.5` back and re-run the three caches
(`DEPLOYMENT.md` §2a). No migration to reverse.