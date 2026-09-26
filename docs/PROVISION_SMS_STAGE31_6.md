# SMS OTP provisioning (Stage 31.6)

**Stage:** 31.6 — phone-based password recovery
**Status:** implemented and tested locally, **not deployed**
**Why it exists:** `email` is optional at registration, but recovery only
accepted an email address. A customer who signed up with a phone number and no
email had no way back into their own account.

The code is **switched off by default**. Deploying this stage does not turn SMS
on, and nothing is sent until an operator completes this document.

---

## 1. What is already true

* The one-time-code lifecycle does not change. Codes are still generated,
  hashed at rest, expired server-side, single-use, attempt-bounded, and rolled
  back if delivery fails. SMS is only a new pipe for the same code.
* `users.phone` is `NOT NULL` and unique, stored as canonical E.164. Every
  account therefore already has a destination; no migration was needed.
* `otp_codes.channel` and `otp_codes.destination` already existed and are now
  used for real, so a row records which transport actually carried the code.
* Arkesel's **hosted OTP product is deliberately not used.** It would hold the
  code on a third party's servers, which cannot be expired, consumed or revoked
  by us. The plain send API keeps the whole lifecycle here.

---

## 2. Operator steps (required before the channel is enabled)

### 2.1 Arkesel account

1. Sign up at `arkesel.com` and obtain the API key from the dashboard.
2. **Register a sender ID.** Arkesel rejects any request whose sender is not
   registered to the account, and a rejected request surfaces as a delivery
   failure — not as a silent no-op. Choose a name or number the customer will
   recognise as this platform, and note that Ghanaian handsets display it.
3. Confirm the sender is approved, not merely submitted. Approval is a
   separate approval from registration.

### 2.2 Environment

```bash
SMS_API_KEY=<arkesel key>
SMS_SENDER_ID=<registered sender>
SMS_BASE_URL=https://sms.arkesel.com
SMS_TIMEOUT=10
```

Never commit these. `.env.example` documents the block; `.env` is not in Git.

### 2.3 Enable the channel

The switch is a **setting**, not an environment variable, so it is auditable
and reversible by an administrator without a deploy:

```php
app(SettingsRepository::class)->set('sms_enabled', true);
```

It is seeded `false`. Leaving it false is a fully supported state: recovery
works by email for every account that has one, and a phone-only account gets
the same neutral "if an account exists" message it would get for an unknown
address — no error, no leak.

### 2.4 Clear config cache

```bash
php artisan config:clear && php artisan config:cache
```

A stale `config:cache` is what made Paystack read as "not configured" in 20.5B.
Do not skip this.

---

## 3. Verifying a real message

Do this against **sandbox / test keys first**, then against the live key, and
never with a customer's real number while the flow is unverified.

1. Create a throwaway account with a phone number and **no** email.
2. Open `/forgot-password` and enter the number in a different format than it
   is stored in (`0244123456` for a stored `+233244123456`) to prove
   normalisation on the way in.
3. Confirm the handset receives the message and that it reads:
   *"Your Reset your password code is NNNNNN. It expires in 10 minutes. If you
   did not ask for this, ignore this message."*
4. Enter the code and confirm the password changes and the old one stops working.
5. Enter a wrong code and confirm the password is untouched and the attempt is
   counted.
6. Check `otp_codes` for the row: `channel = sms`, `destination` the E.164
   number, `code_hash` a bcrypt hash (never the plaintext), `consumed_at` set
   after a successful reset.

### 3.1 Failure modes to prove deliberately

These are the paths that must **not** look like success:

| What you do | What must happen |
|---|---|
| Blank the `SMS_API_KEY`, request a code | Neutral message, **no** `otp_codes` row, exception logged |
| Use an unregistered `SMS_SENDER_ID` | Same as above — a rejected send is a failure |
| Point `SMS_BASE_URL` at an unreachable host | Same as above, and no code left behind |
| Request twice within 60s | One message only, neutral answer to the second |

A code that survives a failed send is the one defect that matters: it is a
usable credential for a message nobody received, and it would leave the
customer locked out while the screen said a code was on its way.

---

## 4. Deliberate scope limits

* **Password reset only.** Phone *verification* is not wired to SMS. Sending a
  code to a number the platform has never confirmed would assert an ownership
  it has not established. `phone_verified_at` is still null for every account
  and `phone.verified` is still applied to no route; that is a separate
  decision, not an oversight here.
* **Email still wins when the customer supplies an email.** SMS is a fallback
  for accounts that have no other way in, not a preference. It costs money;
  email does not.
* **The customer's supplied value chooses the channel, never the
  destination.** The code only ever goes to the value stored on the account. A
  preference the account cannot satisfy is a failure, not a silent fallback to
  another channel — quietly switching to email would hide the fact that SMS is
  not working.
* **Not reversible by deleting the number.** Removing `SMS_API_KEY` stops
  delivery loudly rather than making failures look like successes.

---

## 5. Cost

Roughly ₵0.02–0.031 per message depending on Arkesel's tier at the time of
purchase — confirm current pricing directly with them. Only password-recovery
sends are charged, so the exposure is bounded by how often locked-out customers
retry, and the 60-second cooldown plus the 3-per-identifier rate limit caps how
fast that can be spent. No subscription or per-message billing beyond the
credit balance is involved.
