# Verify 26 — Security + production configuration audit

| | |
|---|---|
| **Stage** | 26 — Security + production configuration audit |
| **Gate** | Very High — `APP_DEBUG=false`; no secrets in git, logs, HTML or error pages; HTTPS/TLS verified; authorization server-side; bounded queries confirmed; OTP/email behavior explicit rather than silently disabled |
| **Evidence base** | Local repository scans + unit/feature test suite + live Hostinger staging checks |
| **Runbook author** | AI agent (OpenCode) |
| **Executed by** | Operator over SSH + hPanel/curl; verdicts recorded by the agent |
| **Staging** | `https://darksalmon-swan-978886.hostingersite.com` |
| **Stage 25 baseline commit** | `aa6bb36` |
| **AGENTS.md anchors** | §105 (security/never trust the browser), §69/§70 (authorization/permissions), §35/§36 (webhook + Paystack config), §62 (SEO/truthful pages), §73 (bounded search), §74 (phone/email/OTP), §128.34 (do not invent business rules) |
| **Code anchors** | `config/app.php` (debug), `.env.example`, `bootstrap/app.php` (CSRF except + exceptions), `routes/web.php` (guest/auth/admin + `can:*`), `app/Providers/AppServiceProvider.php` (super-admin Gate), `app/Http/Middleware/EnsurePhoneIsVerified.php`, `app/Domain/User/{Contracts/OtpChannel.php,Support/UnconfiguredOtpChannel.php,Exceptions/OtpChannelNotConfigured.php}`, `app/Livewire/Auth/Login.php` (rate limiting), `config/session.php`, `config/logging.php` |

---

## 1. Objective

Confirm the deployment does not leak configuration, credentials or internal
state anywhere a hostile browser or an attacker's probe can read it, and that
the behaviors the business relies on (authorization, bounded queries,
explicit OTP/email posture) are real on the running Hostinger box — not just
implied by source.

Expected: **no application code changes**. Findings that require a change are
reproduced locally, fixed, tested, committed, pushed and re-verified before the
gate closes — never patched ad hoc on staging.

---

## 2. Known implementation (the spec under test)

- **Debug flag:** `config/app.php` sets `'debug' => (bool) env('APP_DEBUG', false)`.
  `.env.example` ships `APP_DEBUG=true` for local; staging must run `false`
  (confirmed in Stage 22 and re-confirmed at B).
- **Secrets:** `.env` is git-ignored and has never been committed; tracked files
  reference credentials only through `env()`/`config()`. Paystack secret lives
  only in server-side env; the public JS bundle receives the **public** key. No
  provider secret literal exists in tracked source.
- **Webhook:** `validateCsrfTokens` is disabled **only** for `webhooks/paystack`;
  that endpoint is protected by HMAC SHA-512 over the raw body using
  `hash_equals` (verified in Stage 24). No other route is CSRF-exempt.
- **Authorization:** admin routes sit under
  `Route::middleware(['auth', 'role:admin|super_admin'])` plus per-route
  `can:*` middleware (Spatie Permission). `AppServiceProvider` grants
  `super_admin` every ability via `Gate::before`. Customer routes are
  ownership-scoped in components/queries. No route trusts an order number as a
  capability.
- **Rate limiting:** `Login::submit` uses `RateLimiter` (5 attempts; `throttleKey`
  is phone-identity based). No unbounded brute-force surface identified.
- **OTP/email posture (explicit):** no OTP routes ship; `EnsurePhoneIsVerified`
  middleware is defined but applied to nothing yet (noted in its own comment);
  the OTP channel contract fails **explicitly** via
  `OtpChannelNotConfigured` when asked to send. Email is secondary/optional;
  notification email is synchronous (`NOTIFICATIONS_QUEUE_MAIL=false` on
  staging). No password-reset routes ship. This is the Stage 22 recorded gap —
  Stage 26 records the posture precisely rather than silently "fixing" it.
- **Sessions/cookies:** `database` driver, lifetime 120, `http_only=true`,
  `same_site=lax`; `secure` comes from `SESSION_SECURE_COOKIE` (must be `true`
  over TLS on staging).
- **Error surface:** `shouldRenderJsonWhen(api/*|health|expectsJson)`; web
  errors render with `APP_DEBUG=false` (no stack traces, paths or env dumps).
  Log channel is the default `stack`.

---

## 3. Baseline — local verification (run on this machine, not staging)

From the repository root (Windows PowerShell; PHP 8.5 at `C:\php\php.exe`):

```powershell
php -d memory_limit=1G vendor/bin/pint --test
php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G
php -d memory_limit=1G vendor/bin/pest tests/Feature/Auth/LoginTest.php tests/Feature/AuthorizationTest.php tests/Feature/Payments/PaystackWebhookTest.php tests/Feature/Orders/OrderWebhookTest.php
```

Record exact counts. Expected: Pint clean; PHPStan 0 errors; the security/auth
tests all pass (they assert throttle behavior, 403 on unauthorized admin access,
HMAC verification and duplicate-event idempotency).

---

## 4. Repository hygiene facts (local scans, record counts)

The following are run **locally** and their outputs recorded verbatim in the
results table:

1. `git log --all --oneline -- .env` — must produce no rows (no committed `.env`).
2. `git grep -n -I -i -E "sk_(test|live)_[a-z0-9]{20,}"` across tracked files —
   zero matches.
3. `git grep -n -I -E "=> (pk_|sk_test_|sk_live_)"` — zero matches (secrets are
   never factory defaults).
4. `.env.example` inspection — every credential key is an empty placeholder;
   the file carries explicit "NEVER commit" warnings.

---

## 5. Staging steps

### Conventions

- SSH: `ssh -p 65002 u146516859@89.116.53.20`
- App dir: `/home/u146516859/domains/darksalmon-swan-978886.hostingersite.com/public_html/as-is-commerce-stage20`
- PHP binary: `/opt/alt/php84/usr/bin/php`
- Run artisan from the app dir; wrap tinker in a **single-line** `--execute='...'`.
- **Never print secret values.** Commands below read only non-secret
  configuration; any command that would touch a secret key prints a boolean/flag
  instead.
- Recording rule: **PASS only when observed on staging.** Never claim a step
  from reasoning.

---

### Flow B — Staging configuration surface (debug + runtime posture)

**B1 — Effective debug flag and environment.**
```bash
cd /home/u146516859/domains/darksalmon-swan-978886.hostingersite.com/public_html/as-is-commerce-stage20
/opt/alt/php84/usr/bin/php artisan tinker --execute='echo "APP_ENV=".config("app.env").PHP_EOL; echo "APP_DEBUG=".var_export(config("app.debug"), true).PHP_EOL;'
```
Expect `APP_ENV=staging` and `APP_DEBUG=false`.

**B2 — Runtime drivers that matter for security posture.**
```bash
/opt/alt/php84/usr/bin/php artisan tinker --execute='echo "queue=".config("queue.default").PHP_EOL; echo "cache=".config("cache.default").PHP_EOL; echo "session=".config("session.driver").PHP_EOL; echo "cookies_secure=".var_export(config("session.secure_cookie"), true).PHP_EOL; echo "mail=".config("mail.default").PHP_EOL; echo "log=".config("logging.default").PHP_EOL;'
```
Expect: `database` / `database` / `database` / `true` (or the staging value
recorded — if `false` over TLS that is a finding to record) / a mail driver
value (`log`, `smtp`, ... — record the driver, never `MAIL_USERNAME/PASSWORD`)
/ `stack`.

**B3 — Config is cached** (so `.env` cannot be reloaded by bad requests):
```bash
/opt/alt/php84/usr/bin/php artisan about --only=environment 2>&1 | head -20
```
Expect the Laravel "environment" section showing `APP_ENV=staging`,
`APP_DEBUG=false`, and `/up` health intact (A3 worked in Stage 25).

**B1–B3 recorder:**

- [x] PASS — `APP_ENV=staging`, `APP_DEBUG=false` (config `app.debug` boolean
  `false`). Drivers: `queue=database`, `cache=database`, `session=database`,
  `mail=log`, `log=stack`. `session.secure=true` after the env fix below.
  `config:cache` in use (/up 200; `.env` not re-read per request).

---

### Flow C — Error pages and log hygiene (no leaks)

**C1 — Public 404 page.** Request a definitely-missing URL and confirm Laravel's
generic 404 HTML — no stack trace, no source path, no env dumps:
```bash
curl -s -o /dev/null -w '%{http_code}\n' https://darksalmon-swan-978886.hostingersite.com/definitely-not-a-route-xyz
curl -s https://darksalmon-swan-978886.hostingersite.com/definitely-not-a-route-xyz | grep -c -i -E 'stack trace|app/Providers|/home/u146516859|PAYSTACK|DATABASE|APPDIR'
```
First line `404`; the grep must return `0` (no leak markers in the body).

**C2 — Forced server error page (temporarily, then revert).** Use tinker `abort(500)`
through... (a safe, reversible route probe) — or simpler: request an operation
that the app legitimately errors on, e.g. `/health` with cache cleared, and
confirm the web error is generic and the **JSON** path (`/auth/...` nonexistent
under `api/*` expectation) returns the JSON error envelope without internals:
```bash
curl -s -H 'Accept: application/json' https://darksalmon-swan-978886.hostingersite.com/api/definitely-not-an-api-route
```
Expect a small JSON `{"message":"Not Found"}`-style body containing **no**
exception class, path or stack. No `debug` HTML block.

**C3 — Log scan for secrets.** Grep the runtime log for the secret-shaped
strings the app must never write (do **not** print matched values — only a
count):
```bash
grep -c -E 'sk_(test|live)_[a-z0-9]{20,}|PAYSTACK_SECRET_KEY\s*=|DB_PASSWORD\s*=' storage/logs/*.log 2>/dev/null
```
Expect `0`/no such line counts. (Webhook processing writes events/references
only — Stage 24 audited the payload handling.)

**C1–C3 recorder:**

- [x] PASS — C1: `/definitely-not-a-route-xyz` → `404`, body grep for
  `stack trace|app/Providers|/home/u146516859|PAYSTACK|DATABASE|APPDIR` = **0**.
  C2: JSON error envelope `{"message":"The route api/... could not be found."}`
  — no exception class/path/stack. C3: `grep -c` over `storage/logs/*.log` for
  `sk_(test|live)_[a-z0-9]{20,}|PAYSTACK_SECRET_KEY=|DB_PASSWORD=` = **0**.

---

### Flow D — HTTPS / TLS and cookie hygiene

**D1 — TLS handshake is clean and served over HTTPS.**
```bash
curl -sI --max-time 20 https://darksalmon-swan-978886.hostingersite.com/up | head -5
curl -sI --max-time 20 https://darksalmon-swan-978886.hostingersite.com/ | head -8
```
Expect `HTTP/2 200`, `Strict-Transport-Security` (if present) recorded, and a
valid certificate (no `curl` TLS error). Record headers **without** any cookie
values if they appear (redact `set-cookie` values; only the flags matter in D2).

**D2 — HTTP redirects to HTTPS and session cookie flags.**
```bash
curl -sI --max-time 20 http://darksalmon-swan-978886.hostingersite.com/ | head -6
curl -s -v --max-time 20 https://darksalmon-swan-978886.hostingersite.com/ -o NUL -e 2>&1 | grep -i -E '[Ss]et-?[Cc]ookie' | grep -o -E 'Secure|HttpOnly|SameSite=[A-Za-z]+' | sort -u
```
Expect: HTTP request answers `301/302` with `Location: https:...` (redirect, not
a plain 200); the session cookie carries `Secure`, `HttpOnly` and a
`SameSite` value. Record the flags observed. Regenerate the cookie only as
needed — e.g., hit `/login` first to obtain a fresh one, then re-read its flags.

**D3 — `APP_URL` is the https origin (no mixed-content base).**
```bash
/opt/alt/php84/usr/bin/php artisan tinker --execute='echo "APP_URL=".config("app.url").PHP_EOL;'
```
Expect `https://darksalmon-swan-978886.hostingersite.com` (scheme `https`).

**D1–D3 recorder:**

- [x] PASS — D1: `GET /up` and `/` over HTTPS → `HTTP/2 200`, no TLS error
  (`cache-control: no-cache, private`; no `Strict-Transport-Security` header
  present — **recorded observation**; `x-powered-by: PHP/8.4.21` present —
  standard host header, recorded as observation). D2: `http://... /` →
  `301 Moved Permanently` → `Location: https://...` (Hostinger `platform:
  hostinger` header). Session cookie flags after the fix (verified from inside
  the app): `as-is-commerce-session` `secure=true httponly=true samesite=lax`;
  `XSRF-TOKEN` `secure=true samesite=lax`. **Fix applied on staging:** staging
  `.env` gained `SESSION_SECURE_COOKIE=true` (was unset → cookies not `Secure`
  over TLS), then `config:clear && config:cache`, `/up` 200 re-confirmed.
  D3: `APP_URL=https://darksalmon-swan-978886.hostingersite.com`.

---

### Flow E — Authorization is server-side (not UI trust)

**E1 — Unauthenticated access redirects.**
```bash
curl -s -o /dev/null -w '%{http_code} -> %{redirect_url}\n' https://darksalmon-swan-978886.hostingersite.com/admin
curl -s -o /dev/null -w '%{http_code} -> %{redirect_url}\n' https://darksalmon-swan-978886.hostingersite.com/dashboard
```
Expect `302` redirecting to `/login` (Laravel's `auth` middleware).

**E2 — Authenticated-but-not-permitted access returns 403.** Using an existing
non-staff customer (e.g. user 3) session is not trivially curl-able with CSRF;
instead record the code-level proof and one URL-level proof:
- code: admin routes all carry `role:admin|super_admin` + `can:*` (routes/web.php
  lines 182+); every permission granted via Spatie middleware is checked
  server-side (AGENTS §69).
- on staging: after logging in as a **customer** in a private browser, hitting
  `/admin` returns `403` (record the observed status; the login itself can be
  done in-browser by the operator).

**E3 — Webhook is CSRF-exempt but HMAC-protected.**
- Confirm `validateCsrfTokens(except: ['webhooks/paystack'])` is the *only*
  exemption (bootstrap/app.php) — source proof.
- Confirm a payload with a bad signature is rejected with `403` **and not
  processed** (re-run of the Stage 24 evidence, or rely on
  `PaystackWebhookTest` at baseline + the Stage 24 record). Record which was
  observed this round.

**E1–E3 recorder:**

- [x] PASS — E1: unauth `/admin` → `302 /login`; unauth `/dashboard` → `302
  /login`. E2: authenticated **customer** session to `/admin` →
  `403 User does not have the right roles` (Spatie `role:admin|super_admin`
  middleware; per-route `can:*` also enforced server-side in
  `routes/web.php`). E3: POST `/webhooks/paystack` with bogus HMAC →
  `401`, payload not processed; only CSRF exemption remains `webhooks/paystack`
  (`bootstrap/app.php`); HMAC enforcement proven earlier by
  `PaystackWebhookTest` + Stage 24 record.

---

### Flow F — Bounded queries / no N+1 (spot-check on staging)

**F1 — Homepage catalogue query budget.** Using tinker with the query log on a
listing-equivalent read, confirm the bounding used by `ProductDiscoveryQuery`/
`ListingAvailability` (the page-level batched reads from Stage 23): run
```bash
/opt/alt/php84/usr/bin/php artisan tinker --execute='DB::enableQueryLog(); $items = app(App\Domain\Marketplace\Queries\ProductDiscoveryQuery::class)->all(); $q = DB::getQueryLog(); echo "queries=".count($q).PHP_EOL; echo "rows=".$items->count().PHP_EOL;'
```
Expect a small constant-ish query count (no per-card N+1 — the query is
page-level). Record the count and rows.

**F2 — Admin search remains bounded.** Confirm `GlobalSearch` caps results and
normalizes phone input (source anchor + existing Stage 23 evidence), and that
scheduler sweeps carry the Stage 25 `--limit` caps. Record which anchors were
inspected (no re-run needed — evidence already staged in 23/25).

**F1–F2 recorder:**

- [x] PASS — F1 (staging, query log): `ProductDiscoveryQuery::paginate([], 20)`
  → **4 queries / 7 rows** — page-level batched read, no N+1.
  F2 (source): `OperationsSearch` bounded in every direction —
  `MAX_LENGTH=64`, min 2 chars, `PER_TYPE=10` `->limit()` per group, phone
  normalized to E.164 before matching; scheduler sweeps carry Stage 25
  `--limit` caps (200/200/100/200).

---

### Flow G — OTP / email / password-reset posture (explicit, not silent)

**G1 — Record the explicit state (source + staging).**
- Source proof (record filenames/lines): no OTP routes in `routes/`; no
  password-reset routes; `EnsurePhoneIsVerified` not applied to any route group;
  `UnconfiguredOtpChannel::send()` throws `OtpChannelNotConfigured`;
  `phone_verified_at`/`email_verified_at` stored on `users` but no verification
  flow ships.
- Staging proof: query the setting that governs verification (if `settings()` has
  a `verify_phone_required`-style key, record its value; otherwise record that
  no such governance setting exists because the feature is not wired):
```bash
/opt/alt/php84/usr/bin/php artisan tinker --execute='echo "mail_default=".config("mail.default").PHP_EOL; echo "users_with_verified_phone=".App\Models\User::whereNotNull("phone_verified_at")->count().PHP_EOL;'
```
- Confirm **mail credentials are never printed** (we only read the driver).

**G2 — Staging mail is configured to flow somewhere real or explicitly inert.**
Record `config("mail.default")` and whether `MAIL_FROM_ADDRESS` resolves (no
value printed for username/password). If `log`, confirm outbound email is
written to `storage/logs` (a single probe is enough — the audit needs to know
email does not silently vanish to `/dev/null`).

> This is the **recorded decision point from Stage 22**: the product currently
> ships register/login/logout + profile password change, with phone as the
> primary identity channel. No OTP provider or transport is configured, no
> verification/password-reset routes exist. Stage 26 does **not** invent a
> channel; it records the posture explicitly. The business must decide (in a
> later stage) whether to add OTP transport (e.g. an SMS provider) before any
> gated customer action (credits/bids) that requires a verified phone.

**G1–G2 recorder:**

- [x] PASS — posture recorded explicitly, no channel invented. Staging:
  `mail=log` (emails land in `storage/logs` — synchronous, not silent),
  `users_with_verified_phone=0`. Source: no OTP routes in `routes/`; no
  password-reset routes; `EnsurePhoneIsVerified` middleware is defined but
  applied to **nothing**; `UnconfiguredOtpChannel::send()` throws
  `OtpChannelNotConfigured` (fail-explicit); `phone_verified_at` /
  `email_verified_at` stored but no verification flow ships. This is the
  recorded Stage 22 gap — the business decides later (an OTP transport/
  provider) before any gated action requiring a verified phone; **not silently
  "fixed" by this audit**.

---

### Flow H — CSRF, session and operational hardening (spot evidence)

**H1 — CSRF is enforced on real forms.** `/login` and any POST form render the
`_token` field (visual/in-page check by the operator) and the app only exempts
the Paystack webhook (source). Record the two observations.

**H2 — Session cookie hardening (re-read of B2/D2 flags):** database-backed
sessions (B2), `http_only=true`, `same_site=lax`, `Secure` over TLS (D2),
lifetime 120. Record the aggregate.

**H3 — App/ops hygiene:** no debug tooling enabled in production-style runs
(no Debugbar package installed — confirmed via composer/lock contents),
maintenance file absent, `/up` and `/health` both healthy. Record.

**H1–H3 recorder:**

- [x] PASS — H1: `<meta name="csrf-token" content="...">` present on `/login`
  (grep = 1); classic POST forms carry `@csrf` (`navigation.blade.php`); only
  `webhooks/paystack` is CSRF-exempt. H2: database-backed sessions;
  `http_only=true`, `same_site=lax`, `secure=true` (now) verified off the real
  emitted cookies; lifetime 120. H3: no Debugbar/Telescope/Clockwork in
  `composer.lock` (0 matches); `/health` → `{"status":"ok","database":"ok"}`;
  `/up` → 200.

---

## 6. Results

| Flow | Check | Result |
|---|---|---|
| A | Repository hygiene: no `.env` in history; no `sk_*` literals; `.env.example` placeholders | **PASS** — 0 `.env` commits; 0 `sk_(test/live)_` matches; `.env.example` all placeholders + warnings |
| B1 | `APP_ENV=staging`, `APP_DEBUG=false` effective on staging | **PASS** — `staging` / `false` |
| B2 | Drivers: queue/cache/session `database`; cookie secure over TLS; mail/log channels | **PASS** (after fix) — `database` ×3, `mail=log`, `log=stack`, `session.secure=true` |
| B3 | Config cached; `/up` healthy | **PASS** — config cached, `/up` 200 |
| C1 | Public 404 generic, no leak markers in HTML | **PASS** — 404; body leak grep = 0 |
| C2 | JSON error envelope without internals; no `debug` HTML block | **PASS** — `{"message":"The route ... could not be found."}` |
| C3 | Log scan: zero `sk_*` / secret-key lines | **PASS** — count = 0 |
| D1 | HTTPS 200 over valid TLS; redirect & HSTS headers recorded | **PASS** (obs.) — HTTP/2 200, valid TLS; **no HSTS** (recorded observation) |
| D2 | HTTP → HTTPS redirect; cookie `Secure`+`HttpOnly`+`SameSite` | **PASS** (after fix) — 301 to https; cookie `secure=true httponly=true samesite=lax` |
| D3 | `APP_URL` https origin | **PASS** — `https://darksalmon-swan-978886.hostingersite.com` |
| E1 | Unauthenticated `/admin`+`/dashboard` redirect to login | **PASS** — both → 302 `/login` |
| E2 | Customer session to `/admin` → 403; role/can middleware server-side | **PASS** — observed `403 User does not have the right roles` |
| E3 | Only webhook CSRF-exempt; HMAC enforced (403 on bad signature) | **PASS** — bad sig → `401`, unprocessed; single CSRF exemption |
| F1 | Homepage query bounded (query-log spot-check) | **PASS** — 4 queries / 7 rows |
| F2 | Admin search + sweep limits bounded (Stage 23/25 anchors) | **PASS** — `OperationsSearch` caps 64/10/2 + E.164; sweep limits 200/200/100/200 |
| G | OTP/email/password-reset posture explicit; mail flows somewhere real | **PASS** — posture recorded; `mail=log`, 0 verified phones, gap deferred |
| H | CSRF on forms; cookie flags; no debug tooling; health ok | **PASS** — csrf meta + `@csrf`; flags verified; no Debugbar; `/health`+`/up` ok |

**One environment fix (no code change):** staging `.env` set
`SESSION_SECURE_COOKIE=true` (was unset → cookies not flagged `Secure` over
TLS) and re-cached; verified `secure=true` on the emitted session cookie.

---

## 7. Gate close

**Verdict: PASS**

Blocker rule: any **FAIL** blocks the gate. A **FAIL** means: reproduce locally,
fix in source, test, commit, push, re-verify the failing flow on staging, then
re-open the gate block below.

**Gate closed:** `VERIFY_26_SECURITY_CONFIG_AUDIT` — `main` at `<commit>` on
`<date>` — all flows PASS. Next: **Stage 27** per `docs/ROADMAP.md`.

---

## 8. Completion report template

When the flow finishes, the final commit records:

- **Changed:** files/commits touched.
- **Why:** the business/technical reason.
- **Tests:** exact Pest/PHPStan/Pint commands and counts run.
- **Verification:** which staging flows were observed and how.
- **Database:** whether migrations/schema changed (expected: none).
- **Deployment:** whether a tag/deploy was produced (expected: none — docs
  only, unless a code defect was found and fixed).
- **Remaining:** any limitation or unverified behavior, and any explicitly
  recorded decision (e.g. the OTP/email gap's owner and deferred stage).

---

## 9. Completion report (final record)

- **Changed:** `docs/VERIFY_26_SECURITY_CONFIG_AUDIT.md` — runbook results filled
  for Flows A–H, the one staging environment fix recorded
  (`SESSION_SECURE_COOKIE=true`), results table + gate-close + completion report
  completed. Commits: runbook `ad6255f`, this record `<commit>`. **No
  application code changed** — docs only. Staging change: `.env`
  `SESSION_SECURE_COOKIE=true` followed by `config:clear && config:cache`
  (an environment step, exactly the kind Stage 22 already performed).
- **Why:** prove the running Hostinger deployment does not leak configuration,
  credentials or internals and that the relied-upon security behaviors
  (authorization, bounded queries, explicit OTP/email posture) are real: the
  Stage 26 Very High gate per `docs/ROADMAP.md`.
- **Tests (local):** `php -d memory_limit=1G vendor/bin/pint --test` — PASS;
  `php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G` — **0
  errors**; `php -d memory_limit=1G vendor/bin/pest tests/Feature/Auth/LoginTest.php
  tests/Feature/AuthorizationTest.php tests/Feature/Payments/PaystackWebhookTest.php
  tests/Feature/Orders/OrderWebhookTest.php` — **57 tests / 185 assertions
  passed**.
- **Verification (staging+local):** repo hygiene scans (0 `.env` commits, 0
  `sk_*` literals, `.env.example` placeholders); B staging config (`staging`,
  `debug=false`, drivers `database`/`log`/`stack`); C error/log hygiene (generic
  404, clean JSON envelope, zero secret-shaped log lines); D TLS/HTTPS (HTTP/2
  200, http→https 301, cookie `Secure+HttpOnly+SameSite=Lax` after the env fix,
  `APP_URL` https); E authorization (302 to login unauth, **403** for customer
  on `/admin`, webhook bad-sig 401 unprocessed); F bounded queries (4 queries / 7
  rows on the catalogue read; `OperationsSearch` caps + E.164); G OTP/email
  posture recorded explicitly (`mail=log`, 0 verified phones, gap deferred);
  H CSRF meta + `@csrf`, cookie flags, no debug tooling in `composer.lock`,
  `/health` `{"status":"ok","database":"ok"}` + `/up` 200.
- **Database:** no migrations; no schema change.
- **Deployment:** none. Docs-only stage — no tag, no release zip. The running
  app code is unchanged from the Stage 24/25 deploy; staging received only the
  `.env` cookie-secure setting + config re-cache.
- **Remaining / recorded decisions:**
  - **OTP/email/password-reset gap (Stage 22, re-recorded):** no OTP route or
    transport ships; `EnsurePhoneIsVerified` applied to nothing;
    `OtpChannelNotConfigured` fails explicitly; email is `log` and synchronous.
    A verify-phone decision (provider/transport) is required by the business
    **before any gated customer action needs a verified phone** (e.g. credits/
    bids). Not fixed silently here.
  - **No `Strict-Transport-Security` header** on staging responses (Hostinger-
    level setting; not in this stage's gate list) — recorded as an observation
    for Stage 27 (production domain prep).
  - **`x-powered-by: PHP/8.4.21`** header present (default host behavior) —
    recorded observation; can be suppressed at Stage 27 if desired.
  - Login/register GET routes are unthrottled (only the `Login::submit`
    action is rate-limited to 5 attempts) — recorded observation, acceptable at
    this stage.