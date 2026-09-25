# Stage 30.5 — Transactional email provisioning (operator checklist)

Companion to `DEPLOYMENT.md` §4 and §5. That runbook owns the policy; this file
is the exact sequence for making email actually leave the machine on the
Hostinger staging app introduced at Stage 30.5.

**Status: done.** Staging now runs `MAIL_MAILER=smtp` against Gmail
(`smtp.gmail.com:587`, from `majcreatives@gmail.com`), so real messages are
delivered. This file is kept as the operator checklist for provisioning that
transport, and for the fallback below.

It began as `MAIL_MAILER=log`, which was not a bug: nothing in the application
depends on email for correctness, and `log` is how the OTP work was verified
(codes are written into `storage/logs/laravel.log`). It did mean **no message
reached anybody's inbox**, which mattered the moment a real customer needed a
code. Verified 2026-09-25: `otp_enabled` is true, three OTP codes have been
issued on staging (one consumed), and `PlatformNotificationMail` messages have
been sent.

## 0. What this affects (and what it does not)

Two email paths share the single configured mailer:

| Path | What it carries | Mode | Gating |
| --- | --- | --- | --- |
| `OtpMail` | Email-verification codes, password-reset codes | Synchronous | `otp_enabled`, user has an email. Not affected by notification preferences. |
| `PlatformNotificationMail` | Transactional/engagement notices (auction won, settlement, refund completed, order paid/fulfilled/blocked, delivery dispatched/delivered/failed, referral rewarded) | Synchronous by default | `NotificationType::warrantsEmail()` + per-user preferences. Always written as an in-app notification regardless. |

Fails loud vs. recorded:

- **OTP send fails loud** — an unreachable SMTP host makes the OTP request fail
  visibly (nothing is silently "sent"). That is deliberate (AGENTS.md §74).
- **Notification email failures are recorded, never raised** — the in-app
  notification row already exists; the mail attempt is written as
  `mail_status = failed` with a reason and the business event is unaffected.

## 1. Decision — transport

Choose one. The Laravel mail config already ships `smtp`, `resend`, `postmark`
and `ses` mailers; only `.env` changes between them.

- **Option A (recommended for staging): an SMTP mailbox.** Free mailbox on the
  Hostinger account; standard `smtp` mailer in `config/mail.php`.
- **Option B: a transactional API provider** (Resend / Postmark / SES) using
  the corresponding named mailer. Clean for staging because a dedicated
  transactional mailbox avoids traffic on a human mailbox.

The `log` mailer stays the baseline for offline staging work; `array` is test
infrastructure and has no place in a deployed `.env`.

## 2. Create the mailbox (Option A)

In Hostinger hPanel:

1. **Emails → New email account** on the account's real domain (recipe:
   `no-reply@<domain>` or a support address such as `support@<domain>`).
2. Record the credentials **only into the server's `.env`** — never into git,
   this file, a ticket, a screenshot or a log.
3. The sender domain must be real; a mailbox cannot exist on the ephemeral
   staging subdomain. `MAIL_FROM_ADDRESS` must belong to the mailbox domain so
   the receiving side sees a legitimate envelope.

## 3. Edit `.env` on the server (SSH, staging only)

```bash
ssh -p 65002 u146516859@89.116.53.20
cd /home/u146516859/domains/darksalmon-swan-978886.hostingersite.com/public_html/as-is-commerce-stage20
nano .env
```

```dotenv
# Option A — Hostinger SMTP (typical: smtp.hostinger.com, 465 SSL / 587 STARTTLS)
MAIL_MAILER=smtp
MAIL_HOST=smtp.<account-provider-host>
MAIL_PORT=465
MAIL_USERNAME=no-reply@<domain>
MAIL_PASSWORD=<secret, never column this>
MAIL_FROM_ADDRESS="no-reply@<domain>"
MAIL_FROM_NAME="As-Is-Commerce"

# Option B — provider API (fill in the provider's key; same secret rules)
# MAIL_MAILER=resend
# MAIL_FROM_ADDRESS="As-Is-Commerce <no-reply@<domain>>"
```

Leave unchanged, and keep off because no worker exists on the staging tier:

- `NOTIFICATIONS_QUEUE_MAIL=false` — queued notification mail needs a running
  worker; a deployment without one would go silent rather than inline-deliver.
- `QUEUE_CONNECTION=database` — fine; nothing financial depends on the queue.

## 4. Rebuild the mail-aware caches

A stale `config.php` is the classic reason a changed `.env` "does nothing".

```bash
/opt/alt/php84/usr/bin/php artisan config:clear
/opt/alt/php84/usr/bin/php artisan config:cache
```

## 5. Verify delivery

### 5.1 Transport is live

```bash
/opt/alt/php84/usr/bin/php artisan config:show mail
```

Confirm `default` is `smtp` (or the chosen provider) and the `from` address is
real. Never print `MAIL_PASSWORD`; `config:show` does not.

### 5.2 OTP email (use a real test account)

1. Open `/forgot-password`, submit the test account's email.
2. The mailbox (or provider dashboard) receives the 6-digit code.
3. Complete `/reset-password` with the code.
4. Confirm the row: `otp_codes.destination` equals the account email and
   `consumed_at` is set after successful verification.

A failed transport shows as a visible OTP error on the submitted form —
expected, not a regression.

### 5.3 Notification email (optional but recommended)

Trigger one of the types where `NotificationType::warrantsEmail()` is true
(e.g. an order that reaches paid, or a settlement created). Then in the admin
or in `notifications` confirm:

- the in-app row exists with `event_key` set;
- `mail_status = sent` and `mail_sent_at` populated (or `failed` plus
  `mail_failure_reason` — investigate that, the app will never raise it for
  you);
- the recipient inbox has the message.

### 5.4 Irrespective of transport

```bash
grep -c "production.ERROR\|laravel.ERROR" storage/logs/laravel.log
```

Confirms no new application errors during the sends, and — re-check after the
sends — that no rendered email body or credential leaked into the log. The log
mailer is gone once `MAIL_MAILER` is a real transport, so an OTP code must no
longer appear in `laravel.log` at all.

## 6. Returning to offline/staging work

To suspend real delivery without an app change, switch `MAIL_MAILER=log` and
repeat §4. Codes then land in `storage/logs/laravel.log` again and the rest of
the flows behave exactly as in the Stage 30.5 verification.

## 7. Explicitly not covered here

- Production `MAIL_*` values (Stage 27+; never inferred from staging).
- Setting up the scheduler cron (`schedule:run`) — Stage 25.
- Enabling `NOTIFICATIONS_QUEUE_MAIL` — only once a worker cron exists.

Nothing in this file claims any of the above has been performed. Until §5
passes with a real inbox, staging email remains `log`-only by construction.