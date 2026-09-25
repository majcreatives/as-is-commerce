# Stages 31–33 — UX, bid-increment cap, auction sharing (PLAN, nothing built)

Status: **proposal for review.** No code has been changed. Each stage below is
independently reviewable and shippable, and none depends on another being
approved except where a dependency is stated.

BidRush was used as UX/information-architecture inspiration only (trending
items, partners, success stories, subscription prompt, image-led browsing). No
branding, copy, assets or implementation is to be reproduced. The Shop stays
the primary identity; auctions stay a controlled channel layered onto it.

```
Stage 31   Presentation / UX / information architecture   no economics, no auction rules
Stage 32   Auction rule: maximum bid increment            auction engine + snapshot
Stage 33   Auction sharing + referral qualification       referral / credit economics
```

Recommended order is 31 → 32 → 33. 32 and 33 do not depend on each other, but
33 needs Stage 31's legal/cookie pages if it ever stores a cookie.

---

## 0. Findings that change the brief

Read these first. Several terms in the request do not match the code, and three
requests collide with rules recorded in `CLAUDE.md`.

| # | Finding | Effect |
|---|---|---|
| F1 | **`bid_cost_credits` and `unique_leader_rule` do not exist.** They were removed in the highest-bid correction stage; tests (`AuctionModelCorrectionTest`, `AuctionEngineRegressionTest`) assert they stay gone. A bid carries its own amount and consumes exactly that many credits. | Stage 32 is written against the real model (§32.2), not against those two names. |
| F2 | **Condition is a PHP enum plus a DB CHECK**, not a table. "Create new conditions like brand and category" is a schema change, not a UI change. | Split into its own step, 31.2b, with a migration. Needs a decision (D2). |
| F3 | **There is no newsletter mechanism.** The matches for "subscriber" in the code are event subscribers. Notification preferences are per registered user and cover transactional/bidding/delivery only. (The OTP/mail half of this finding is now stale: mail is provisioned on staging via Gmail SMTP, and 30.5 shipped email OTP.) | The popup needs a new subscriptions table, double opt-in and its own consent record. It is a data-collection feature, not presentation (31.7). |
| F4 | **"Auction ending soon" alerts are deliberately not built** (the threshold is undecided; see Notifications in `CLAUDE.md`). | The popup must not promise auction alerts. It can offer only what exists. |
| F5 | **`CLAUDE.md` lists "CMS, blog, reviews, recommendations, analytics" under *Do not build here*.** Blog, success stories/testimonials, partners and "trending" touch every one. It also forbids fabricated data. | Each becomes real, admin-supplied or record-derived content with an honest empty state. Approving this plan means updating that section of `CLAUDE.md` (see §Docs to update). |
| F6 | **Attribution is registration-only, query-string-only, permanently: "No cookie, no session, no tracking window."** Surviving session/device changes contradicts it. | Stage 33 cannot meet "survive device/session changes" without an explicit policy change (D9). |
| F7 | **Referral qualification today is one rule:** a verified `Paid`, non-blocked order by the referred user, from *any* order source. Credit purchases and bids qualify nothing, and `referrals.qualifying_order_id` can express no other evidence. | Path A (credit purchase + bid) is new evidence, new columns and a trigger change. |
| F8 | **The existing bid-history label is the bug you described.** `auction-room.blade.php:351` prints `'Bidder #'.$bid->sequence`, which is bid order, not participant. No test covers it. | Fix is read-only (31.2). |
| F9 | **With no minimum increment configured, `BidValidator` does not require a bid to exceed the standing highest.** Only `if ($rules->hasMinimumIncrement())` compares against it. As I read it, a non-leading bid is accepted and its credits are consumed. This is unconfirmed until a test proves it. | Affects how "27 → 28 valid" reads. Surfaced in §32.2; not changed by this plan. |

---

## Stage 31 — Presentation, UX, information architecture

**Invariant for the whole stage:** it displays what the domain decided and
decides nothing. No migration touches a financial, inventory, payment, auction
or referral table, with two flagged exceptions (31.2b conditions, 31.7
newsletter), each in its own step.

### Sub-steps (each its own reviewable change)

| Step | Items | Schema | Depends on |
|---|---|---|---|
| **31.1** Product page | 2, 3 (links), 4 | none | none |
| **31.2** Bid-history participants | 11 | none | none |
| **31.2b** Conditions as data | 3 (create conditions) | **yes** | decision D2 |
| **31.3** Pages, footer, How It Works | 5, 9, 10 | none | D3, D4 (content) |
| **31.4** Homepage discovery | 8 | none | 31.3 (Success Stories/Partners content) |
| **31.5** Registration redesign | 1 | none | 31.3 (consent links) |
| **31.6** Notification badge | 6 | none | D5 |
| **31.7** Newsletter popup | 7 | **yes** (new table) | 31.3 (privacy/cookie), mail provisioned (F3, F4) |

### 31.1 — Product page (items 2, 3, 4)

**Item 2, auction-first presentation.**
- `ProductDetail::render()` already resolves `activeAuctionFor()` (Live, Closing
  or Scheduled only) and passes `$auction`; the view currently shows one line
  and a "View the auction" button. Change the view to lead with an auction
  panel when `$availability->hasAuction()`: `x-auction-status-badge`,
  **Highest Bid (Credits)** via `x-credits` (never `x-money`), `x-countdown`
  from `ends_at`, and one primary action linking to `auctions.show`.
- A Scheduled auction shows its start, and no bid figure. No stale figure ever
  appears because only relevant auctions are passed.
- The Buy Now price block stays but moves below; the existing rule (auction owns
  the Buy Now path for its unit) is unchanged, so no cart button appears.
- **The bid form is not duplicated on the product page.** Bidding is a two-step
  review/confirm against a locked row inside `AuctionRoom`; a second copy is a
  second place to keep in sync. The panel links to the room.
- The countdown decides nothing and announces no outcome at zero.
- `auction_eligible` is not read here. Only an actual active auction triggers
  the layout.
- Files: `product-detail.blade.php`, `ProductDetail.php` (no logic change),
  `x-countdown`/`x-auction-status-badge` reused.

**Item 3, clickable metadata.**
- Category: breadcrumb already links; add the category link to the metadata
  list and the badge row.
- Brand: `route('products.index', ['brand' => $product->brand->slug])`.
- Condition: `route('products.index', ['condition' => $product->condition->value])`.
  `ProductCatalog` already binds `brand`, `category` and `condition` with
  `#[Url]`, and `ProductDiscoveryQuery` already filters on all three. No query
  change needed.
- Real anchors with `wire:navigate`, keyboard focusable, visible focus ring.
  The catalogue only offers brands/categories that hold visible products
  (`brands()`/`categories()`), so a link to a filter with a select that has no
  matching option is possible for a brand whose only products are hidden. The
  result count will simply read 0 with the existing empty state. Acceptable.

**Item 4, image viewer.**
- Pure Alpine, no dependency (Livewire bundles Alpine). The gallery data is
  already `$product->images` with the legacy single-image fallback.
- `role="dialog"`, `aria-modal="true"`, labelled by the product name; focus is
  moved in on open, trapped, and returned to the trigger on close. Keys:
  `Esc` close, `←`/`→` previous/next, `Home`/`End`. Touch: horizontal swipe
  with a threshold and vertical scroll left alone. "3 / 7" count announced via
  an `aria-live="polite"` region. Body scroll locked while open.
- Thumbnails only when more than one image. Featured image becomes a `<button>`
  wrapping the `<img>`.
- **Alt text:** `ProductImage` has no alt column, only `image_path`, `position`,
  `created_by`, so alt stays generated ("{name} — image N of M"). Editable alt
  text would be a media-manager change plus a column; proposed as optional
  follow-up, not included.
- Progressive enhancement: with JS off the featured image is still shown as
  today.

**Tests:** feature test that a page with an active auction renders the auction
panel before the price block and shows no stale auction (finished/closed) figure;
that the brand and condition links resolve to filtered listings that return the
product; that a Scheduled auction shows no highest bid. The lightbox is
client-side, so the JS behaviour is covered by a small `node --test` file in the
style of `auction-stream.test.mjs` for the pure index/swipe logic, plus a manual
mobile checklist in the runbook.

### 31.2 — Bid history participants (item 11)

- Replace `'Bidder #'.$bid->sequence`. New read model on `HighestBidResolver`
  (read-only): a participant ordinal per auction = dense rank of each user's
  **first** bid `sequence`, so the first person to ever bid is Bidder #1 for the
  life of the auction and a person keeps one number however often they bid.
- **The ordinal must be computed over the whole auction, not the 25 displayed
  rows.** The list is limited to 25 newest; ranking within the page would renumber
  people as history scrolls. One grouped query (`user_id, MIN(sequence)`) bounded
  by participant count.
- The viewer still sees "You". Real identity is never returned to the page: the
  query maps to ordinals before the view, so the `User` model does not reach it
  (today `history()` eager-loads `user`, which should go).
- The public broadcast payload is untouched (seven-field whitelist); participant
  ordinals are not added to it.
- Admin auction detail is unchanged (staff already see identity).
- **Tests:** one participant bidding three times appears as the same number
  three times; numbers stay stable when the history exceeds the display limit;
  the viewer sees "You"; another user's id/name/phone never appears in the HTML.

### 31.2b — Conditions as data (item 3, "create new conditions")

Currently `ProductCondition` enum + `chk_products_condition CHECK (... IN
('new','used','refurbished'))`. To let an administrator create conditions:
- New `conditions` table (name, slug, description, badge tone, status,
  sort_order), seeded with the three existing rows, plus `products.condition_id`
  FK backfilled from `condition`, then the old column and CHECK dropped in a
  follow-up migration.
- Touches `Product`, factories, `ProductDiscoveryQuery` (filter by slug),
  `ProductCatalog`, `TaxonomyManager` (already manages brands/categories, so
  conditions fit there), structured data, the badge component, and every test that
  builds `ProductCondition::…`.
- Rule to keep: a condition, like a category, is **archived, never deleted**
  while products use it (same DB-level refusal). "If it exists, don't create it"
  is a unique slug/name constraint with a friendly duplicate message.
- MariaDB caveat recorded in `AGENTS.md`: dropping a CHECK is `DROP CONSTRAINT`,
  not `DROP CHECK`.
- **Recommendation:** approve this only if you expect conditions beyond
  New/Used/Refurbished. It is the single highest-churn item in the stage for the
  least customer-visible gain; the link behaviour in 31.1 works without it.

### 31.3 — Pages, footer, How It Works (items 5, 9, 10)

New public routes (Blade views via `Route::view`, no CMS): `/about`, `/contact`,
`/faqs`, `/blog`, `/privacy`, `/cookies`, `/terms`. Footer becomes grouped
columns (Shop / Company / Legal) and keeps Shop, Auctions and How It Works. Primary
nav is not reduced.

- **Legal pages are drafted as structure and content the business must own.**
  Privacy, Cookie and Terms must be reviewed by counsel before launch (Ghana's
  Data Protection Act, 2012 (Act 843) is the obvious reference). I will not
  present drafted legal text as final, and pages must describe only what the
  platform actually does: credits are consumed and not returned; Store Wallet
  rules; verified payments; manual delivery.
- **Returns/cancellation:** returns and reverse logistics are not built. The Terms
  and FAQ must state the real position (what cancels, what refunds and when), not
  imply a returns policy that has no process behind it. Needs business input (D4).
- **Contact:** a details page (address/phone/email from settings) rather than a
  form. A public form is a spam and abuse surface and there is nowhere for
  the message to go until mail is provisioned. Form is a later step.
- **Blog:** no content model exists and none is proposed. The page ships with an
  honest empty state until you decide to publish something (F5, D3).
- **Success Stories (item 9):** a section on About, and only real, consented
  content. Empty state until supplied. Source of truth is an admin-managed record,
  or content you hand me to render. Never seeded or invented.
- **How It Works consolidation:** audit every page's explanatory copy and move
  the general education into `how-it-works` (already the home of buy credits /
  find / bid / Buy Now / settle / delivery). Candidates seen so far: the homepage
  hero paragraph and the three "trust" cards, the footer tagline. The remaining
  pages get the same audit at implementation (credits, auction index, checkout,
  register). **Kept in place** because they are decision-point information: product
  condition and its description, delivery information, payment information,
  auction rules on the auction page, the "credits are consumed and not returned"
  disclosure next to bidding, Store Wallet restrictions at checkout, and anything
  legally required. The hero's one-line credit-consumption disclosure stays.

### 31.4 — Homepage (item 8)

Order: hero → **Newly added** → **Trending / Popular** → **Partners** → **Success
Stories** → **Newsletter CTA**, replacing the current "In the shop" and
trust-card sections. Shop-first copy retained.

**Shipped (31.4): Newly added + Trending.** Partners, Success Stories and the
Newsletter CTA are the remainder. Runbook: `VERIFY_31_4_TRENDING.md`.

- *Newly added:* `ProductDiscoveryQuery::featured()` already orders by
  `published_at`, so this is reuse.
- *Trending:* no analytics exist and none is added. It is derived from records
  only: units on paid `order_items` in a trailing window, plus accepted bids on
  auctions a customer can still act on, each computed server-side in
  `ProductDiscoveryQuery::trending()`, bounded, and empty-stated when there is
  no data. The window is a setting, not a constant (D6).
- *Partners:* no partner concept exists. Real logos and permission are needed;
  section is hidden until there is content, not filled with placeholders.
- *Success Stories:* selected items only, linking to About.
- Every card goes through `x-product-card` with one `availabilityFor()` call for
  the page, so no per-card query and no stale auction figure.

### 31.5 — Registration (item 1)

**Shipped (31.5 → 31.5b):** split-screen on desktop — a dark marketing hero on the
left (abstract geometric artwork, headline "Shop in cedis. Win with credits.") and a
minimalist white form on the right; single white column on phones. First/Last name
inputs (composed into the single `users.name` column), required phone sign-in ID,
optional email, password + confirm with show/hide toggles, primary CTA, and a
"Sign in" link. Social login (Google/Apple) and a terms/privacy consent
line/checkbox are **deliberately omitted**: there is no OAuth infrastructure in the
product, and the 31.3 legal pages remain deferred.

- **No layout copy that promises earnings or a reward.**
- If a `?ref` code is present, do not name the referrer to the new user.
- Behaviour, validation, attribution, phone-first identity and OTP wiring in
  `Register.php` unchanged; only the name input split and the layout changed.
- Uses a dedicated `register-split` layout so `/login`, `/forgot-password` and
  `/reset-password` keep the standard guest layout untouched.

### 31.6 — Notification badge (item 6)

Today `User::unreadNotificationCount()` counts `read_at IS NULL` with no upper
age bound, and rows are only marked read by `markRead`/`markAllRead`. So it is
server-authoritative, and it is also unbounded: a customer who never opens the
centre carries every notification they were ever sent.

Proposed semantics (D5): **unread AND not older than N days** (N a setting,
suggested 30), transactional and non-transactional alike, one rule for desktop,
drawer and hamburger.

- One query per request rather than the two now run separately in
  `navigation.blade.php` (`$unread` and `$unreadMobile`).
- The hamburger button gets a dot/count badge, capped `99+`, hidden at 0, plus an
  `sr-only` "N unread notifications" so it is not colour- or shape-only. The
  drawer's own count stays.
- Server-rendered on navigation, as today; no realtime, so no transport change.
- Cart count is not added to the hamburger unless you want it (asked only for
  notifications).
- **Tests:** counts only own, unread, in-window; 0 hides the badge; 100+ renders
  `99+`; the accessible label is present in both places.

### 31.7 — Newsletter popup (item 7)

Because of F3/F4 this is a small feature, not a widget.

- New `newsletter_subscriptions` (email unique, consent timestamp, consent text
  version, source, confirmation token hash, confirmed_at, unsubscribed_at). Email
  only; no phone, no auto-attach to an account.
- **Double opt-in:** the popup records a *pending* subscription and sends a
  confirmation link; only a confirmed address counts. Every message must carry a
  working unsubscribe link, so an unsubscribe route ships with the popup.
- **Blocked on mail provisioning** (`PROVISION_MAIL_STAGE30_5.md`): mail is now
  provisioned on staging, so the confirmation can be delivered.
- **Sending is out of scope.** "Marketing campaigns" are not built. The plan
  delivers collection + consent + unsubscribe only, and the copy says what is
  true (e.g. "news from the shop"), not "auction alerts" (F4).
- Frequency: shown once after a delay (suggest 20 s or 40% scroll) on public pages
  only, never on checkout/auth/admin; dismissal and success remembered in
  `localStorage` for N days (setting), with try/catch since storage may be
  unavailable; a signed-in customer is not asked. Escape and an explicit close
  button; focus trap; no dark pattern.
- Not shown before the cookie/privacy pages exist. `localStorage` for a dismissal
  flag is first-party functional storage; state this in the Cookie Policy.
- Rate-limited endpoint, honeypot field, no enumeration of already-subscribed
  addresses.

### Stage 31 — invariants that must not move

- `ListingAvailability` decides availability; never `isInStock()` on a page.
- `x-money` for GH₵, `x-credits` for counts; label is **Highest Bid (Credits)**.
  "Auction price" appears nowhere.
- Only currently relevant auctions reach a card or panel.
- The countdown decides nothing.
- No bidder is named to another bidder; no Buy Now buyer to anybody.
- Broadcast payload whitelist untouched; `wire:poll` untouched.
- Structured data stays truthful; no rating or review count is invented.
- No fabricated content (`CLAUDE.md` rule 5).

---

## Stage 32 — Maximum bid increment (auction rule)

> **SUPERSEDED.** Replaced by `PLAN_32_FIXED_BID_INCREMENT.md`. An exact bid step
> makes a cap on the size of a jump redundant, so this design is not being built.
> Kept below as the record of the reasoning.

### 32.1 — How the engine works today (verified in code)

- `PlaceBid` → idempotency key → lock auction row → `BidValidator::assertValid`
  → consume credits → write bid → rebuild projection → maybe extend.
- **A bid is an absolute amount, and it consumes that whole amount.** A
  bidder holding 27 who bids 300 consumes 300 more (27 already gone). There is no
  per-bid fee and no `bid_cost_credits`.
- Rule fields in the frozen snapshot: `minimum_bid_credits`,
  `minimum_bid_increment_credits`, `allow_bid_increase` (all nullable, null = "no
  rule"), and `minimum_bid_interval_ms` (a per-bidder **time** throttle, not an
  amount, so the word "interval" must not be reused for this feature).
- Validation order: auction open, user eligible, amount positive, amount rules,
  throttle, credits available. Ties go to earliest `sequence`.
- Extension: `AuctionClock::extensionFor()` is computed from the clock only and
  applied after the bid is written. A rejected bid rolls back before it.
- Store Wallet: issued to losing bidders from consumed **purchased** credits by
  lot valuation; independent of bid validation.
- There is no auto-bid, proxy bid or max-bid anywhere in `app/`.

### 32.2 — Proposed semantics

Field: **`maximum_bid_increment_credits`** — nullable integer, credits, in the
ruleset and in the snapshot. Null means no cap (matches how every undecided rule
is treated; no default invented).

Rule, when a standing highest bid exists:

```
amount  <=  highest_bid_credits + maximum_bid_increment_credits
```

Examples with maximum = 1: 27 → 28 valid; 27 → 29 refused. With maximum = 5:
27 → 32 valid; 27 → 33 refused.

| Question | Proposal |
|---|---|
| **Units** | Credits, integers. |
| **Configurable range** | Null, or an integer ≥ 1. Must be ≥ `minimum_bid_increment_credits` when both are set, otherwise no bid could ever be valid; rejected at ruleset save and again in `AuctionRules::assertValid`. No invented ceiling. |
| **First bid (no standing bid)** | *Decision D7.* The example only covers a standing bid, but an opening bid of 300 has the same intimidating effect. Recommended: if `minimum_bid_credits` is set, cap the opening bid at `minimum_bid_credits + maximum_bid_increment_credits`; if it isn't, no cap (the engine must not invent a floor). Alternative: apply nothing to opening bids. |
| **Bids at or below the highest** | The cap bounds only the upward jump. Whether a non-leading bid is allowed at all is the existing rule set (F9) and is not changed here. |
| **`bid_cost_credits`** | Does not exist. The cap limits the bid amount, and since a bid consumes its own amount, it directly limits how much a single bid can burn. |
| **`unique_leader_rule`** | Does not exist. Ties still go to the earliest sequence; an equal bid never overtakes. |
| **Raising your own bid** | The cap compares against the standing highest even when it is yours. `allow_bid_increase` is checked first, unchanged. |
| **Timer extensions** | No interaction. The cap is checked in validation, before an extension is decided; a refused bid extends nothing. `AuctionClock` untouched. |
| **Store Wallet** | No interaction in code. A cap reduces how many credits one bid can burn, which reduces a bidder's possible loss-compensation exposure; it does not change how compensation is valued. |
| **Future Auto-Bid/Max-Bid** | Not introduced and not designed. The field name is neutral, but a later proxy-bid feature would have to define whether the cap constrains the ceiling a user enters or each stepped increment. Recorded as an open question, not decided. |
| **Invalid bid** | `BidRejected`, nothing consumed, no row, no extension (validation is inside the transaction, as today). |
| **Customer message** | "The largest bid you can place right now is 28 credits. The highest bid is 27 credits and this auction allows raises of up to 1 credit." Plain language; a count, never GH₵. |
| **Existing/live auctions** | Unchanged. Their snapshots have no cap, so behaviour is identical. A cap applies only to auctions created from a ruleset that sets it. A live auction's configuration is frozen by design; to change it, cancel/relist or use a new ruleset version for new auctions. |

### 32.3 — Implementation plan

- **Schema:** `auction_rulesets.maximum_bid_increment_credits` nullable, with a CHECK
  (`NULL OR >= 1`, and `>= minimum_bid_increment_credits` when both set), using the
  MariaDB `DROP CONSTRAINT` form on rollback.
- **Snapshot:** bump `AuctionRules::SNAPSHOT_VERSION` 3 → **4**. `fromArray()`
  refuses any other version, so every stored snapshot must be rewritten. Precedent
  is `2026_09_10_100200_replace_flat_buy_now_credit_discount_with_lot_valuation`:
  drop `auctions_frozen_configuration`, `JSON_SET` the new key to null and the
  version to 4, recreate the trigger, all in one migration. Existing auctions
  keep their exact behaviour.
- **Domain:** `AuctionRules` (property, `toArray`, `fromArray`, `assertValid`,
  `hasMaximumIncrement()`, `largestValidBid(?int $highest)`);
  `AuctionRuleset::toRules()`; `RulesetInvariants`; `CreateRuleset`, `UpdateRuleset`,
  `CreateRulesetVersion` carry the field.
- **Engine:** `BidValidator::assertAmountSatisfiesRules` adds the cap check, under
  the same auction lock, against `highestBidForUpdate`. `BidRejected::aboveMaximum…`.
  A `largestValidBid()` mirror of `smallestValidBid()` for display.
- **UI (admin):** `ruleset-form.blade.php` field next to the minimum increment with
  hint text; ruleset index and `auction-detail.blade.php` display it read-only.
  Only draft rulesets are editable (unchanged).
- **UI (customer):** `AuctionRoom` shows the valid range ("between N and M credits")
  when a cap applies, computed server-side, alongside the existing smallest-valid
  hint. `review()` keeps checking shape only; the domain owns the rule.
- **Broadcast:** the seven-field whitelist is not extended; polling carries the
  derived range.

### 32.4 — Tests

- Cap boundaries (exactly at, one over); null cap allows any amount; opening-bid
  behaviour per D7.
- Cap < minimum increment rejected at ruleset save and in `AuctionRules`.
- A refused bid leaves no bid row, no credit consumption, no extension.
- Two concurrent bids: only the one within `highest + max` of the *locked* standing
  bid clears, with a real lock (MySQL, not SQLite).
- Own-bid raise obeys both `allow_bid_increase` and the cap.
- Extension-window bid over the cap extends nothing.
- Snapshot v4 round-trip; v3 refused; migrated auction snapshots have the null key
  and an unchanged rules hash otherwise; frozen-configuration trigger still fires
  after the migration.
- Snapshot-half test: no settlement key in the rules half (unchanged).
- Suggested F9 characterisation test so the existing behaviour is pinned before
  anyone relies on it.

### 32.5 — Invariants that must hold

Server decides; validation inside the transaction after the auction lock; no
invented defaults for undecided rules; `AuctionRules` stays `readonly`; a frozen
snapshot never changes meaning; credits are never refunded by a refused or
accepted bid; lock order auction → product → wallet → lots unchanged.

---

## Stage 33 — Auction sharing and referral qualification

**No reward is issued by a click, a view, a registration or opening a product.**
Everything below extends the existing referral architecture; nothing is a second
system.

### 33.1 — What exists

- `users.referral_code`: random 8-char, immutable, lazily issued; the sole referrer
  identity today.
- `AttributeReferral`: registration only, `?ref=CODE`, resolved server-side; a bad
  code never blocks registration; self-referral refused by application and CHECK.
- `referrals`: unique `referred_user_id` (one referrer for ever), unique
  `credit_transaction_id` (one reward per referral), triggers freezing the pair and
  `code_used` and forbidding deleting a rewarded row.
- `ReferralStatus`: Attributed → Qualified → Rewarded (+ Invalidated). `qualify()`
  and `reward()` are separate so a declined reward stays retryable.
- Qualifying event: `OrderStatusChanged` to `Paid` via `ReferralSubscriber`, by
  `ReferralProgramme::orderQualifies()` (paid/processing/fulfilled, not
  fulfilment-blocked, has a successful payment). Any order source.
- `RewardReferral::reward()`: idempotent (`IdempotencyGuard`), reads the amount from
  settings once and snapshots it, honours the programme switch and per-referrer cap.
- `ReferralReconciler` reports, never repairs.
- Settings: `referrals_enabled`, `referral_reward_credits`,
  `referral_max_rewards_per_referrer`. Permissions: `referrals.view/manage/settings`.
- No credit-purchase-fulfilled event exists; `BidAccepted` does.

### 33.2 — Sharing design (item 13)

- **Reuse the existing code as the identity; add a share token as a pointer.** New
  small table `referral_shares` (referrer_user_id, opaque random token, nullable
  `auction_id`, nullable `product_id`, created_at), **one row per (referrer, target)**
  (`firstOrCreate`) so it cannot grow unbounded. The token resolves to a referrer and
  an origin; it carries no personal data and is not sequential.
- Share URL: `/auctions/{id}?s=TOKEN` (and product equivalent). The auction page
  itself is unchanged; `?s=` is read only by registration and by the register link
  rendered on that page.
- `referrals` gains nullable `share_id` and `origin_auction_id`/`origin_product_id`
  (copied, not referenced, like `code_used`), frozen by the same trigger once set.
  `code_used` keeps recording what was actually used.
- Share UI: a **Share** control on the auction and product pages for signed-in
  customers using the Web Share API on mobile, with a copy-link and a WhatsApp link
  fallback. Signed-out visitors are asked to sign in to share (no code exists for
  them). Wording never promises income or a payout; rewards are a count of credits.
- No click counting. Counting clicks is analytics and an enumeration surface; nothing
  in the qualification rules needs it.
- Preview cards: the auction room should supply `description`/`ogImage` so a shared
  link renders well. Read-only presentation, low risk.
- A referrer is still never told who registered.

### 33.3 — Persistence across sessions/devices (F6, D9)

The honest options:

1. **Query string only (today).** Works if the friend registers from the link they
   were sent. Lost if they browse away and come back another day.
2. **Carry-through:** the register link and login-to-register prompts on any page
   reached via `?s=` re-append the token server-side. Still no cookie; survives
   browsing within the visit only.
3. **First-party cookie (or `localStorage`) set on landing**, holding the token for a
   configured window. Survives sessions on that device. **Contradicts
   "no cookie, no session, no tracking window"** in `CLAUDE.md` and must be disclosed
   in the Cookie Policy (31.3).
4. **Across devices:** not possible without an identity. The only case that works is
   the friend opening the shared link on the device they register on.

Recommendation: ship 1+2 now with no policy change, and take 3 as an explicit,
separate decision with a defined window (setting, no invented default). State
plainly that cross-device continuity means "same link, wherever it is opened".

### 33.4 — Qualification (item 14): proposed definitions

Nothing here is decided; these are recommendations for you to confirm, because each
changes what the business pays for.

| Question | Proposed definition | Note |
|---|---|---|
| **Path A: qualifying credit purchase** | A `credit_purchases` row by the referred user that reached **Fulfilled** (credits granted after verified Paystack). Not Pending/Failed/Cancelled/Reversed. | Optional minimum package value: *D8*. Not invented. |
| **"Participate in an auction"** | An **accepted bid** by the referred user (bids are only written with their credits consumed, and are irreversible). | Whether one bid is enough, or a minimum amount, is *D8*. |
| **"Subsequently"** | The bid was placed **after** the qualifying purchase was fulfilled. | Evaluated when the bid is accepted; no partial state stored. |
| **Path B: qualifying Shop purchase** | Today's `orderQualifies()` rule: verified `Paid`, not fulfilment-blocked, by the referred user. | Whether it means *catalogue orders only* or **any** order source (auction settlement, auction Buy Now): *D10*. Today it is any. |
| **Auction Buy Now** | It is a paid order, so it counts under B if B stays "any order". Under a Shop-only B it does not, and it is not "participation" unless bids exist. | D10. |
| **Refunded/cancelled Shop orders** | Cancelled/expired never qualify (existing). A refund is only possible on a *fulfilment-blocked* order, which is excluded already, so refund-after-qualifying cannot occur through current flows. | No clawback (existing rule). |
| **Refunded/chargeback credit purchases** | Refunds and chargebacks are not implemented for credit purchases; `Reversed` is a status only. Nothing is clawed back. | If a hold period before rewarding is wanted, it is a new economic decision (D11). |
| **Attribution window** | None today. A new `referral_qualification_window_days` setting (0 = no limit), measured from registration to the qualifying event. | Value is *D11*. Not invented. |
| **Self-referral** | Existing: same-user application check and CHECK. Also unique phone identity. Additional signals (shared delivery address, same email) are **reported, not blocked**, via `ReferralReconciler`. | Blocking on shared signals risks false positives (households). D12. |
| **Duplicate abuse** | Unique `referred_user_id` (one referrer), unique `credit_transaction_id` (one reward), per-referrer cap, idempotent reward. One reward per referral however many paths are satisfied. | Already enforced. |
| **When it is locked** | At **Qualified**: qualifying evidence becomes immutable (trigger extended to the new evidence columns). | Existing trigger covers relationship only. |
| **When credits are issued** | Immediately after `qualify()` via `reward()`, as today: after commit, idempotent, may decline and stay `Qualified` for reconcile. | The alternative of waiting for delivery was considered and rejected in the original design (see `ReferralProgramme`). |

### 33.5 — Implementation plan

- **Schema:** `referral_shares`; on `referrals` add `share_id`, `origin_auction_id`,
  `origin_product_id`, `qualifying_path` (enum: `shop_order` | `credit_purchase_bid`),
  `qualifying_credit_purchase_id`, `qualifying_bid_id`; extend the freeze trigger to
  cover them; `qualifying_order_id` stays for path B. Settings for window (and any
  thresholds decided in D8).
- **Domain:** `ReferralProgramme` gains `bidQualifies(Bid)` (accepted bid by the
  referred user, after a Fulfilled credit purchase by the same user, inside the
  window); `RewardReferral::qualify()` accepts either evidence type; `AttributeReferral`
  resolves a share token as well as a code, still never throwing into registration.
- **Wiring:** `ReferralSubscriber` adds `onBidAccepted(BidAccepted)`, wrapped in the
  same guard as the order handler so nothing throws into `PlaceBid`. Dispatch is
  already after commit.
- **Reconciler:** extend `unrewardedQualifications` and `rewardsWithoutEvidence` to
  recognise path-A evidence and to report a referred user who has both a fulfilled
  purchase and a later accepted bid but is still `Attributed`. Reports only.
- **UI:** Share control (auction/product), referral dashboard shows origin as a
  category ("shared from an auction") without naming the referred person, admin
  referral queue shows path and evidence.
- **Docs:** update the Referrals section of `CLAUDE.md` (attribution, qualification).

### 33.6 — Tests

- Registration via `?s=TOKEN` attributes to the token's referrer and records the
  origin; bad/expired/unknown token still registers; self-referral refused.
- Click, view, registration, product open: no credits and no status change.
- Path A: purchase Fulfilled → no reward; later accepted bid → Qualified → Rewarded
  once. Bid before the purchase → no qualification. Pending/Failed/Reversed purchase
  → none. Window exceeded → none.
- Path B unchanged: blocked, cancelled and expired orders still qualify nobody.
- Both paths satisfied → exactly one reward, one ledger row.
- Replay of `BidAccepted`/`OrderStatusChanged` → idempotent; failure in the listener
  never fails the bid (the existing throwing-dispatcher suite stays green).
- Cap and programme-off decline leaves the referral `Qualified`; reconcile reports it
  and does not issue credit.
- Trigger: evidence columns cannot be edited after Qualified; a rewarded row cannot
  be deleted.
- Privacy: a referrer's screens never expose the referred person.

### 33.7 — Invariants that must hold

Referral credits are ordinary credits through `CreditLedgerService::addCredits` as
`ReferralCredit`; no referral balance/wallet; nothing is clawed back; reward amount
snapshotted at issue; `qualify()` and `reward()` stay separate; reconciliation never
issues credit; notifications dispatch after commit and never throw; rewards are a
count of credits, never a cedis figure or a promise of income.

---

## Decisions needed from you

| ID | Decision | Recommendation |
|---|---|---|
| D1 | Confirm the stage split and order 31 → 32 → 33. | Yes. |
| D2 | Conditions as admin-created data (schema change) vs. keep the three-value enum. | Keep the enum unless you expect new conditions; do links first. |
| D3 | Blog, Partners, Success Stories: who supplies real content, and is a minimal admin-managed record wanted or should pages be static? | Static pages with empty states until content exists. |
| D4 | Returns/cancellation position for Terms/FAQ, and who owns legal review of Privacy/Cookie/Terms. | Business + counsel decide; I draft structure only. |
| D5 | Notification badge semantics: unread only, or unread within N days (N=?). | Unread within 30 days, as a setting. |
| D6 | "Trending" definition and window. | Paid units + live-auction bids over a setting-driven window; **empty-stated** when there is no activity, not hidden. Shipped in 31.4 — see `VERIFY_31_4_TRENDING.md`. |
| D7 | Cap on the opening bid. | Cap relative to `minimum_bid_credits` when set; otherwise none. |
| D8 | Any minimum purchase/bid size for referral qualification; is one accepted bid enough? | One accepted bid after a Fulfilled purchase, threshold only if you choose a number. |
| D9 | Referral persistence: query-string + carry-through, or approve a first-party cookie with a window. | Query-string + carry-through now; cookie as a separate approval. |
| D10 | Path B: any paid order (today) or catalogue Shop orders only. | Keep any paid order. |
| D11 | Attribution window length; reward at `Paid` (today) or after a hold. | Keep `Paid`; window value from you. |
| D12 | Shared-address/email signals: report-only or block. | Report only. |

## Docs to update when approved

`CLAUDE.md`: *Do not build here* (marketplace layer) for blog/success stories;
*Attribution is at registration only* (if D9 = cookie); Referrals qualification;
the auction snapshot version (3 → 4) and a new maximum-increment note under *The
auction model*. `docs/ROADMAP.md`: add 31/32/33 and the 30.x sub-stages it currently
omits. A runbook per stage in the style of `VERIFY_30_SHOP_CART_P3.md`.

## Not in this plan

Auto-Bid/Max-Bid, courier or shipping work, disputes/chargebacks, returns logistics,
a CMS, campaign sending, click/traffic analytics, any change to the financial, order,
inventory or payment architecture.
