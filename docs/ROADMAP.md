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
   financial history, integer minor units, credit/cash separation, highest valid
   credit bid, frozen auction snapshots, idempotency, and no-silent-financial-
   repair all continue to bind every stage below.
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
| **21** | Real Laravel migration on MariaDB 11.8.9 | Medium | The full product migration runs clean against `u146516859_asiscomm`; schema audit passes — tables, indexes, foreign keys, constraints, BIGINT definitions, timestamps, collations, unique constraints, JSON columns, enum-like fields, transaction/`FOR UPDATE` behavior |
| **22** | Hostinger application/bootstrap verification | Medium | Auth (register/login/logout/verify/password), catalogue reads, admin login, health endpoints all pass on staging; `APP_DEBUG=false` confirmed; `config:show paystack` reports the key is set (value never printed) |
| **23** | Full staging functional audit | High | Buy Now; credit purchase; credit wallet ledger + consumption; auctions (create, schedule, activate, bid, cooldown, closing, winner resolution, settlement handoff, cancel, forfeit, relist); Store Wallet (loss compensation, lot valuation, balance, checkout application, release, paid-order freeze) — every flow inspected |
| **24** | Payment/webhook staging audit | Very High | Paystack **test mode** — initialization, callback, webhook, HMAC signature verification, duplicate-event idempotency, payment conflict (Paid + fulfilment blocked), failed payment, late payment. No real money moves |
| **25** | Scheduler/cron/operations audit | High | Hostinger hPanel cron runs `schedule:run`; `auctions:tick`, `orders:expire-checkouts`, refund/referral reconciliation are idempotent and bounded; `ScheduleLocks` and heartbeat stamps verified |
| **26** | Security + production configuration audit | Very High | `APP_DEBUG=false`; no secrets in git, logs, HTML or error pages; HTTPS/TLS verified; authorization server-side; bounded queries confirmed; OTP/email behavior explicit rather than silently disabled |
| **27** | Production database/domain preparation | Very High | Real domain + SSL; production `.env` and production database created/confirmed; production migration run; administrative accounts created; Paystack production keys only when explicitly ready; cron set; backups verified; `/health` and `/up` monitored |
| **28** | Controlled production smoke test | Very High | Tiny seeded inventory; exactly one Buy Now product and one auction; a few controlled accounts; controlled credit purchases and bids; the resulting financial records inspected |
| **29** | Soft launch | Very High | Go visible without advertising; observe behaviour; exception centre stays clean; verified payment/refund paths behave |
| **30** | Post-launch monitoring/reconciliation | Very High | Wallets, credits, refunds and referrals reconciled; exceptions actioned; financial invariants re-verified; discrepancies reported, not silently repaired |
| **31+** | Growth, UX, gamification and optimization | Variable | Each feature separately approved before implementation. Ideas already deferred here: admin left-side navigation, product image gallery (multiple images, first as featured), and editable auction end dates (a business decision, not an implementation choice) |

## Context this roadmap does not change

The four deferred items above were raised and deliberately set aside so the
staging gate stays small:

- **Paystack "not configured"** is fixed in **20.5B** as an operator step (a
  stale `config:cache` after a `.env` change), not a code change.
- **Admin sidebar, product image gallery and editable auction end dates** wait
  for **31+**. In particular, auction end dates touch the frozen-snapshot and
  server-clock model and require an explicit business decision when they are
  picked up.