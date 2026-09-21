# As-Is-Commerce — Remaining Roadmap

This file is the agreed plan for the work after Stage 20. It is the single
source of truth for what comes next, and it is deliberately a **roadmap**, not
a design document: each stage below names its purpose, its risk, and the gate
that must be proven before the next stage begins.

The repository and current implementation remain authoritative for how things
work today. Nothing in this roadmap relaxes any rule in `AGENTS.md`.

## Standing rules

1. **GitHub is in the loop.** Every change is committed to `main` and pushed to
   `origin/main` before anything is deployed. Deployables are produced by the
   GitHub Actions release workflow from a pushed tag — never built ad hoc on a
   developer machine and never edited on the server.
2. **Staging is for verification.** Staging never becomes the source of
   application logic. A manual change made on staging for investigation is
   reproduced in local source before it counts as part of the product.
3. **No golden rule is relaxed.** Server-authority, ledger authority, append-only
   financial history, integer minor units, credit/cash separation, the winner
   rule frozen into each auction's snapshot (the largest total under the
   cumulative model; the highest single bid under the earlier one), frozen
   auction snapshots, idempotency, and no-silent-financial-repair all continue
   to bind every stage below.
4. **Production is a separate controlled operation.** Nothing here authorizes a
   production migration or financial change by inference. Each production stage
   requires explicit approval when reached.

## The four phases

- **Phase A — Hostinger staging.** Deploy the real application to the Hostinger
  staging area from a GitHub Actions release.
- **Phase B — MariaDB migration verification.** Prove the actual schema migrates
  cleanly on MariaDB 11.8.9.
- **Phase C — Staging application verification.** Prove the actual application
  works on Hostinger, including payments in Paystack test mode.
- **Phase D — Production launch.** A controlled, separate move to production.

## Stage table

| Stage | Purpose | Risk | Gate / exit criteria |
|---|---|---|---|
| **20.5B** | GitHub-native Hostinger staging deployment | Low | Release zip built by GitHub Actions and downloaded on Hostinger; `.env` set (APP_ENV=staging, APP_DEBUG=false, Paystack **test** keys); app boots; `/health` and `/up` respond; no secrets visible anywhere |
| **21** | Real Laravel migration on MariaDB 11.8.9 | Medium | **VERIFIED on staging (2026-09-13)** — full product migration ran clean against `u146516859_asiscomm` (28/28 Ran); schema audit passed via `docs/VERIFY_21_SCHEMA_AUDIT.md`: tables, indexes (incl. `bids_highest_bid_index` `DESC`), foreign keys, constraints (73 named `chk_*` + 18 `json_valid`; legacy 3 removed), BIGINT definitions, timestamps, collations, unique constraints (idempotency scoped to user), JSON columns, enum-like fields, transaction/`FOR UPDATE` behavior, freeze/append-only triggers (28) |
| **22** | Hostinger application/bootstrap verification | Medium | **VERIFIED on staging (2026-09-13)** — health endpoints (`/health` 200 `{"status":"ok","database":"ok"}`, `/up` 200), auth (register/login/logout/profile password change), admin login (`/admin` via `super_admin`), catalogue reads all pass; `APP_DEBUG=false` confirmed (initial `.env` had `production`/`true` — corrected to `staging`/`false` and re-cached); Paystack **test** keys confirmed set, value never printed (**gap logged**: no OTP/email-verify/password-reset routes — deferred, decision required) |
| **23** | Full staging functional audit | High | Buy Now; credit purchase; credit wallet ledger + consumption; auctions (create, schedule, activate, bid, cooldown, closing, winner resolution, settlement handoff, cancel, forfeit, relist); Store Wallet (loss compensation, lot valuation, balance, checkout application, release, paid-order freeze) — every flow inspected |
| **24** | Payment/webhook staging audit | Very High | Paystack **test mode** — initialization, callback, webhook, HMAC signature verification, duplicate-event idempotency, payment conflict (Paid + fulfilment blocked), failed payment, late payment. No real money moves |
| **25** | Scheduler/cron/operations audit | High | Hostinger hPanel cron runs `schedule:run`; `auctions:tick`, `orders:expire-checkouts`, refund/referral reconciliation are idempotent and bounded; `ScheduleLocks` and heartbeat stamps verified |
| **26** | Security + production configuration audit | Very High | `APP_DEBUG=false`; no secrets in git, logs, HTML or error pages; HTTPS/TLS verified; authorization server-side; bounded queries confirmed; OTP/email behavior explicit rather than silently disabled |
| **27** | Production database/domain preparation | Very High | Real domain + SSL; production `.env` and production database created/confirmed; production migration run; administrative accounts created; Paystack production keys only when explicitly ready; cron set; backups verified; `/health` and `/up` monitored |
| **28** | Controlled production smoke test | Very High | Tiny seeded inventory; exactly one Buy Now product and one auction; a few controlled accounts; controlled credit purchases and bids; the resulting financial records inspected |
| **29** | Soft launch | Very High | Go visible without advertising; observe behaviour; exception centre stays clean; verified payment/refund paths behave |
| **30** | Post-launch monitoring/reconciliation | Very High | Wallets, credits, refunds and referrals reconciled; exceptions actioned; financial invariants re-verified; discrepancies reported, not silently repaired |
| **31+** | Growth, UX, gamification and optimization | Variable | Each feature separately approved before implementation. Ideas already deferred here: admin left-side navigation, product image gallery (multiple images, first as featured), and editable auction end dates (a business decision, not an implementation choice) |

## What has actually been built under the 31+ row

The stage numbers above were planned as a production launch sequence. In
practice the work since Stage 28 went into the shop and the auction channel, and
the labels below are what the commits and tags say. Each is independently
reviewable and none changed a financial, inventory or payment rule except where
stated.

| Label | What | State |
|---|---|---|
| 30.0–30.3 | Shop cart: multi-item basket, atomic placement, one badge for the basket plus an owed checkout | deployed |
| 30.4 | Product image galleries | deployed |
| 30.5 | OTP email verification and password reset | deployed |
| 31.1 | Auction-first product page, linked brand/category/condition, image viewer | deployed |
| 31.2 | Bid history numbered by participant, not by bid | deployed |
| 31.3 (part) | About, Contact, FAQs, grouped footer, How It Works as the one home for the explanation | deployed |
| 31.6 | Notification badge on the mobile menu button | deployed |
| — | Header menu dead on pages that are not Livewire components (Alpine ships inside Livewire's bundle) | fixed, deployed |
| 31.4, 31.5, 31.7, 31.2b | Homepage discovery, registration redesign, newsletter popup, conditions as data | not started; see `PLAN_31_UX_BIDCAP_SHARING.md` |
| **32** | **Cumulative bidding model** — a bidder's position is their total credits consumed; the server works out the one bid that lands them a step ahead; the largest total wins. `PLAN_32_FIXED_BID_INCREMENT.md` | steps 1–3 deployed; steps 4–6 committed; **deploy pending**, see `VERIFY_32_CUMULATIVE_BIDDING.md` |
| 33 | Auction sharing and referral qualification | planned, not started; needs decisions in `PLAN_31_UX_BIDCAP_SHARING.md` |

## Reference-model reframe (recorded, not a stage change)

The business model is a **full e-commerce store with a gamified auction
channel**, not an auction marketplace with a Buy Now fallback. Two work items
live under this frame and are not stages in the table above; each is verified
separately before the next:

- **P1 — Reference-model positioning.** Copy, labels and homepage structure
  state the store first; live auctions surface inline on their product cards
  (no standalone homepage auction band); admin navigation frames catalogue
  before commerce. No schema, no business-logic change. Baseline:
  `docs/REFERENCE_MODEL.md`; runbook: `docs/VERIFY_28_REFERENCE_MODEL_P1.md`.
- **P2 — Auction-channel eligibility gate.** A product-level opt-in flag
  (`products.auction_eligible`, default false) decides which inventory admin
  may move into the auction channel; auction creation enforces it. Distinct,
  separately approved after production prep so it never reshapes Stage 27's
  launch surface. **Closed 2026-09-16** on `stage29.1` (runbook
  `docs/VERIFY_29_AUCTION_ELIGIBILITY_P2.md`).
- **P3 — Shop cart (multi-item, multi-quantity).** The shop behaves as a real
  e-commerce channel: cart ≠ order, no stock held in the cart, and an atomic
  all-or-nothing placement that validates and reserves every line, computes the
  total server-side, honours the one-pending-order rule, and always keeps
  auction settlement/credit/auction-Buy-Now out of the cart. Planned in
  `docs/CART_SCOPE_AND_IMPACT.md`; implemented in its own verified step.

## Context this roadmap does not change

The four deferred items above were raised and deliberately set aside so the
staging gate stays small:

- **Paystack "not configured"** is fixed in **20.5B** as an operator step (a
  stale `config:cache` after a `.env` change), not a code change.
- **OTP / email verification / password reset** was investigated in **22** and
  recorded as a gap: the current implementation ships register/login/logout
  plus a profile password change, with `phone_verified_at` / `email_verified_at`
  stored but no verification or password-reset routes. Building them needs an
  explicit business decision (OTP provider/transport) and is deferred — it is
  not silently added.
- **Admin sidebar, product image gallery and editable auction end dates** wait
  for **31+**. In particular, auction end dates touch the frozen-snapshot and
  server-clock model and require an explicit business decision when they are
  picked up.