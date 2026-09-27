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

1. **The registered legal entity and its address are not supplied.**
   The privacy notice and the terms both print `[REGISTERED LEGAL ENTITY — NOT
   YET SUPPLIED]` and `[REGISTERED ADDRESS — NOT YET SUPPLIED]` in the visible
   marker while these are blank. Naming a data controller is a requirement
   under Act 843, so this is not cosmetic.

   Both are now settings — `legal_entity_name` and `legal_entity_address` — so
   supplying them is an administrative act on `/admin/settings` rather than a
   code change, a deploy and a push. That matters for the schedule, not just
   the convenience: this was the slowest item on the list, and it was slow only
   because of how the value was stored. `app:check-environment` now reports it
   as a **blocker** on an exposed site and a warning locally, so a site
   carrying the marker cannot be served to anybody by accident. The Data
   Protection Commission registration (`dpc_registration`) is on the same
   screen but is deliberately not a blocker: not having one is a compliance
   gap rather than an identity gap, and failing a deploy over it would be the
   tool insisting on something it has no standing to judge.
2. **`support_email` is unset**, so the privacy page falls back to pointing at
   the contact page. A published privacy notice with no way to contact the
   controller is not acceptable — set this setting before launch. Reported by
   `app:check-environment` as a warning, for the same reason: a contact form is
   a weaker answer than an address, but a site with one is not a site that is
   lying to anybody.
3. **A lawyer must review all three.** The structure and every factual claim
   are ours; the wording, the limitation of liability, the governing-law clause
   and the enforceability of the whole thing are not ours to settle. D4 in
   `PLAN_31_UX_BIDCAP_SHARING.md` — "who owns legal review" — is still open and
   is the decision blocking this. This is now the only thing on the list that
   genuinely needs a person outside the codebase.

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

## Sending these to a lawyer

```bash
php artisan legal:review-packet
```

Writes a self-contained packet to `storage/app/legal-review/<timestamp>/`:
an `index.html` cover plus one file per page, each with the real compiled
stylesheet inlined, no scripts, and no reference to the build directory. Open
`index.html` in any browser; it works with no server, no network, no build and
no credentials, which matters because there is nowhere to deploy these yet and a
Blade template is not something a lawyer can read.

The pages are rendered through the real HTTP kernel, so what is written is what
a customer would actually receive rather than a re-assembly of it, and the cover
states plainly which fields are still provisional, what is being asked of the
reviewer, and that none of it is legal advice. Every page is stamped `DRAFT —
NOT LEGAL ADVICE` on its face: an unmarked draft can be mistaken for the
finished article, which is the one outcome worse than not sending it at all.

Re-run the command after any change to the pages or the settings.

### The trading name is not settled

"As-Is-Commerce" is a working name, and it appears in both the privacy notice
and the terms as the name the business trades as. A trading name used in Ghanaian
commerce is normally registered with the Registrar General's Department, so the
reviewer has to be told this is provisional or they will proofread around it and
those comments will be invalidated by the rename. The cover says so.

### The registered entity, address and DPC registration

`legal_entity_name`, `legal_entity_address` and `dpc_registration` are settings,
filled in at `/admin/settings`, not code. The first two are hard blockers in
`app:check-environment` while the site is exposed. `dpc_registration` is shown
on the privacy notice as soon as it has a value, and is deliberately not a
blocker: not every operator is registered, and printing a registration number
nobody holds would be worse than printing none.

### The site name and the emails had drifted apart

The public pages, the page titles and both legal notices read
`config('app.name')` — `APP_NAME`. Order confirmations, password resets and
one-time codes read the `site_name` setting. Nothing tied the two together, and
the seeder wrote the setting as a literal rather than deriving it, so renaming
by setting `APP_NAME` would have renamed the site and left every customer email
calling the old name, with nothing broken to signal it.

The seeder now derives the setting from `APP_NAME`, the catalogue seeder no
longer hardcodes the name in product descriptions, and `app:check-environment`
reports a `brand_name` warning whenever the two disagree. It is a warning
rather than a blocker because it is a two-minute fix on a settings screen, and
stopping a deploy for it would only ever be a nuisance.

