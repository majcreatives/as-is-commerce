# Legal pages — draft notes

**Status: DRAFT. Do not publish until a lawyer has reviewed these pages, and do
not publish them at all until the two placeholders below are filled in.**

The three pages exist and are linked from the footer:

| Page | Route | View |
|---|---|---|
| Privacy notice | `/privacy` | `resources/views/pages/privacy.blade.php` |
| Terms of use | `/terms` | `resources/views/pages/terms.blade.php` |
| Cookies | `/cookies` | `resources/views/pages/cookie.blade.php` |

## Why these are written the way they are

Every factual claim was checked against the schema, the migrations and the
services before it was written — the migrations for column nullability and
referential actions, the Paystack and SMS gateways for what is actually
transmitted, and a full-text scan of `resources/` for third-party scripts,
pixels, iframes and embeds.

That is the whole difference between these pages and the rest of the
site's copy, and it is the standard the rest of the site already holds itself
to: the About page states only what the platform demonstrably is and does,
because "an About page is the one place a made-up one would read as the most
authoritative thing on the site". A privacy notice is worse, not better: it
makes promises a person will rely on.

Where the implementation cannot honour a right, the page says so. See the
deletion section below — that is the most important sentence in the set.

## Blockers before publication

1. **The registered legal entity is not recorded anywhere in the system.**
   All three pages carry a visible `[REGISTERED LEGAL ENTITY — TO BE SUPPLIED]`
   and `[REGISTERED ADDRESS — TO BE SUPPLIED]` placeholder. Naming a data
   controller is a requirement under Act 843, so this is not cosmetic.
2. **`support_email` is unset**, so the privacy page falls back to pointing at
   the contact page. A published privacy notice with no way to contact the
   controller is not acceptable — set this setting before launch.
3. **A lawyer must review all three.** The structure and every factual claim
   are ours; the wording, the limitation of liability, the governing-law clause
   and the enforceability of the whole thing are not ours to settle. D4 in
   `PLAN_31_UX_BIDCAP_SHARING.md` — "who owns legal review" — is still open and
   is the decision blocking this.

## Findings the audit turned up, which the pages had to reflect

These are gaps in the product, not in the writing. Each is described honestly
in the pages; none is fixed by this change.

### 1. There is no account deletion path, and one would be refused

No route, component, command, policy or `SoftDeletes` trait removes a
`User`. If one were written today it would fail at the database for any
customer with order, bid, delivery, cart, refund or referral history, because
those columns are `RESTRICT`. Credit, cash, Store Wallet and address rows would
cascade away; every actor column (`created_by`, `caused_by`, `invalidated_by`)
would be nulled.

The privacy page therefore promises **account closure and deletion of the
personal data with no financial weight** — profile details, addresses, message
history — and states plainly that money and transaction records are retained
and cannot be erased by us. That is a weaker right than customers may expect
under Act 843, so it needs a lawyer's view, and it is a product decision too:
a genuine delete-my-data capability is a real feature, not a policy clause.

### 2. Some personal data has no expiry mechanism at all

Confirmed with no deletion or retention job anywhere: orders and order items,
order payments (including `authorization_url` and `access_code`), stored Paystack
webhook payloads, addresses, deliveries and their frozen address copies plus
internal `staff_notes`, bids, all credit/cash/Store Wallet ledgers, referrals,
OTP rows, notifications, idempotency keys, session rows including `ip_address`
and `user_agent`, rate-limit entries in the `cache` table, activity-log rows, and
queued and failed job payloads.

The activity log has a 365-day retention setting in `config/activitylog.php`,
but **no cleanup is scheduled** — `routes/console.php` runs only
`auctions:tick`, `orders:expire-checkouts`, `refunds:reconcile` and
`credits:expire-unused`. The privacy page does not claim a 12-month purge that
does not happen. Either schedule `activitylog:clean` or do not describe that
retention. Scheduling it deletes audit evidence, so it is a decision and not a
cleanup.

### 3. Smaller disclosures the pages now make

- **Paystack receives `user_id`** in transaction metadata, and where an account
  has no email we send a generated placeholder in the form
  `user-{id}@no-email.{host}`. Both are disclosed.
- **Arkesel receives the phone number and the plaintext one-time code** for
  every SMS-authenticated action. The pages describe this and note the channel
  is not currently enabled.
- **No age verification exists.** The pages state 18+, which is a term rather
  than an enforced control.
- **The session cookie's `secure` flag is environment-driven with no secure
  default** (`config/session.php:174`). On a live HTTPS site it must be set, or
  the cookie will be sent over plain HTTP.
- **`deliveries.staff_notes`** is free text written by staff about a delivery
  and never shown to a customer. It is customer-adjacent personal data with no
  retention rule. Worth a house style or a length limit before launch.

## Two follow-ups this does not do, on purpose

- **No terms checkbox at registration.** 31.5 omitted it because the legal
  pages did not exist. They now exist, so it could be added, but that changes
  the registration flow 31.5 deliberately designed and is a UX decision, not a
  consequence of writing these pages.
- **No consent banner.** None is needed: there are no non-essential cookies.
  If a tracker or widget is ever added, all three pages become false and a
  consent mechanism becomes required. The cookie page says this in writing so
  the next person to add a pixel finds the warning.
