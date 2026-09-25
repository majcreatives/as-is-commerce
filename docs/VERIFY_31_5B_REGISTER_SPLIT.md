# 31.5b — Register split-screen redesign (deploy and verification runbook)

Follows `VERIFY_31_5_REGISTRATION_REDESIGN.md`. This release supersedes the
31.5 two-column register page with a **split-screen**: a dark marketing hero on
the desktop left and a minimalist white registration form on the right, single
white column on phones. It stays presentational: no schema, no economics, and
`Register.php`'s validation, phone canonicalisation, referral attribution, OTP
wiring and redirect are untouched. The only component behaviour change is the
name input split (below).

## What ships

1. **Split-screen register page** (`resources/views/livewire/auth/register.blade.php`):
   `lg:grid lg:grid-cols-2`. Left column (`hidden lg:flex`, `bg-slate-900`) is a
   marketing hero: original abstract geometric SVG + gradient artwork (no image
   assets, no copied brand art), headline *"Shop in cedis. Win with credits."*,
   truthful supporting copy, three platform-fact points and the
   `route('how-it-works')` link. Right column is white with a centered `max-w-md`
   form.
2. **First/Last name inputs** — two fields on a row (stack on phones), both
   optional, composed server-side into the existing single `users.name` column as
   `"First Last"`; validation is 60/60 chars per field (preserves the previous
   120-char total bound). `Register.php` gains `$first_name`/`$last_name` and
   drops the old `$name` property; a name over 120 chars total errors on
   `last_name`.
3. **Phone sign-in ID kept required** (locked model), optional email, password +
   confirm with the 31.5 show/hide toggle (`x-password-input`), primary *Create
   account* CTA with loading state, *Sign in* link.
4. **Social login (Google/Apple) omitted** — the product has no OAuth
   infrastructure, so no faux/disabled buttons are rendered.
5. **Terms checkbox and consent line omitted** — 31.3's legal pages remain
   deferred ("a link to a page that does not exist is worse than none").
6. **Dedicated layout** `resources/views/components/layouts/register-split.blade.php`
   used only by register — `/login`, `/forgot-password` and `/reset-password`
   keep the standard guest layout (`max-w-md`), untouched.

## Gates (local, before the tag)

- `./vendor/bin/pint` — pass (component, both test files).
- `./vendor/bin/phpstan analyse` — 0 errors.
- Focused: `tests/Feature/Auth/RegistrationTest.php` — 13 pass (44 assertions).
- Auth suite: `tests/Feature/Auth` — 47 pass (153 assertions).
- Full MySQL-backed suite: **2,422 tests, 2,421 passed, 1 error** — the error was
  `referrals`' `ReferralAttributionTest` still setting the removed `$name`
  property. Fixed in that test (first/last fields), and
  `ReferralAttributionTest` + `RegistrationTest` re-run green (33 pass, 92
  assertions). No code change was needed beyond the test; the component had
  already been re-run.

## Release

- Tag `stage31.5b` pushed; GitHub Actions `build-deploy.yml` produced
  `as-is-commerce-stage31.5b.zip` (13,922,437 bytes, SHA-256
  `52ff3603657fe13361e6554a1606789fbb2e718daa203d944ca4e3ae16706fb2`).
- The zip matched that digest on staging before extraction; the three changed
  app view/component files were byte-identical to the local commit (SHA-256), and
  every Tailwind utility the new hero uses is present in the runner-built CSS
  (including arbitrary `size-[28rem]` and `brand-*`/50 opacities).

## Deploy (staging, 2026-09-25)

Directory swap as before (`DEPLOYMENT.md` §2a):

- Live dir → `as-is-commerce-stage20.bak-31.5b` (earlier `bak-31.5` retained).
- `.env` preserved (APP_ENV=staging, APP_KEY present); `storage/app` carried over
  (12 files under `storage/app/public`).
- `storage:link`, `config:cache`, `route:cache`, `view:cache` rebuilt.
- `migrate --force` — "Nothing to migrate" (no schema change in this release).

## Verified on staging

| Check | Result |
|---|---|
| `GET /health` | 200 |
| `GET /up` | 200 |
| `GET /register` | 200, 17,510 B |
| Register HTML has `lg:grid-cols-2`, `size-[28rem]` (split-screen art) | yes |
| Register HTML has *Shop in cedis.* / *Win with credits.* | yes |
| Old two-column `md:col-span-3/2`/`What you get` panel gone from register | yes |
| Password toggles present (`x-data`, *Show password*) | yes |
| **Desktop geometry (1440×1000)**: hero column 720px wide, `bg-slate-900` (dark, `oklch(0.208…)`), display `flex`, forms right half 720px, form card `max-w-md` centered, zero horizontal overflow | pass |
| **Phone geometry (390×844)**: hero `display: none` (width 0), single white column full width, form `max-w-md` 358px, zero horizontal overflow | pass |
| Password toggle flips both inputs `password→text→password` with `aria-pressed=true` | pass |
| Sign-in link `/login` present; no consent/social copy in page text | pass |
| `/login`, `/forgot-password`, `/reset-password` still `max-w-md`, no split hero, no overflow (both widths) | pass |

Geometry was measured through headless Chrome CDP (element bounding boxes,
`scrollWidth` vs `innerWidth`, input `type` before/after toggle click) — no
screenshots.

## Left for the human (not automatic)

- Visual rendering at real device widths and the toggle click, which measurement
  cannot fully judge — open `/register` on staging and confirm the hero artwork
  and typography look right before relying on the redesign.
- Social login and the terms checkbox stay omitted until OAuth infra (unplanned)
  and the 31.3 legal pages exist.

## Rollback

Swap `as-is-commerce-stage20.bak-31.5b` back and re-run the three caches
(`DEPLOYMENT.md` §2a). No migration to reverse.