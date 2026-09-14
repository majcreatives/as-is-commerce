# Stage 24 — Payment/webhook staging audit (operator runbook)

This is the Stage 24 gate (`docs/ROADMAP.md`): exercise every Paystack payment
edge path for real on Hostinger staging in **Paystack test mode** — nothing
here moves real money. It is a verification stage; it does not change
application logic or the schema.

**Who runs this:** a human operator over SSH + browser (steps from
`AGENTS.md` §86). Staging is for verification; if a manual change is needed to
unblock a flow, reproduce it in local source before it counts as product.

**What this stage proves:** initialization, callback, webhook, HMAC signature
verification, duplicate-event idempotency, payment conflict (Paid +
fulfilment blocked), failed payment, late payment.

**Not in scope (later stages):** production keys/domains (Stage 27+), security
lab for auth/OTP (Stage 26), scheduler/operations audit (Stage 25), and any
feature not yet approved.

> **Standing rule for any discrepancy found.** Record evidence, triage, and —
> if it is a genuine product bug — reproduce the fix in local source, ship the
> normal flow (commit → push `main` → release tag → deploy), and re-verify on
> staging. Never silently repair financial or auction data on the server.

---

## Prep

At the bash prompt (app directory):

```bash
cd /home/u146516859/domains/darksalmon-swan-978886.hostingersite.com/public_html/as-is-commerce-stage20
/opt/alt/php84/usr/bin/php artisan db:seed --class="Database\Seeders\AuctionRulesetSeeder"
/opt/alt/php84/usr/bin/php artisan db:seed --class="Database\Seeders\CatalogSeeder"
```

Both are safe to re-run (ruleset: skipped if "Standard Auction" exists;
catalog: existing SKUs left untouched).

Confirm the mechanical posture:

- `APP_ENV=staging`, `APP_DEBUG=false`, Paystack **test** keys in `.env`.
- `PAYSTACK_TIMEOUT` unset or `15` (default) — the test-mode HTTP timeout.
- Caches rebuilt after any `.env` change (`config:clear` → `config:cache`).
- Webhook URL registered in the Paystack dashboard (test mode):
  `https://darksalmon-swan-978886.hostingersite.com/webhooks/paystack`
  (confirmed registered for this audit).
- The seeded "Standard Auction" ruleset carries `checkout_deadline_minutes=60`;
  use a short-deadline test ruleset (min `1`) when a flow needs a settlement
  or checkout to lapse quickly — same technique as Stage 23.

---

## Accounts used

- **super_admin** — admin UI for `/admin/payments`, `/admin/payment-events`,
  Exception Centre.
- **Customer A** and **Customer B** — test customers with a credit-funded
  wallet where a bid/Buy Now is needed (users 2/3 have wallets from Stage 23).
- Paystack test card **4084 0840 8408 4081**, any future expiry, any CVV,
  OTP `1234` if prompted by the Paystack test checkout.

---

## Flow A — Webhook signature + duplicate-event idempotency

Goal: prove real signed deliveries are honoured exactly once, forged ones are
rejected, and replays cannot duplicate a financial effect.

### A1. Real first delivery

1. Perform a real test-mode payment (Flow 1-style Buy Now or credit purchase)
   that ends in the Paystack test checkout, paying with the test card.
2. In the Paystack dashboard → Settings → Webhooks/Live log (test mode), locate
   the delivered `charge.success` for that reference.
3. Verify on staging:

| Check | Expectation |
|---|---|
| HTTP response for the delivery | `200` |
| `payment_webhook_events` row | one row, `processing_status=Processed`, `order_payment_id` or `credit_purchase_id` resolved |
| Financial effect | exactly one sale / one credit grant, matching the payment |
| Order/purchase | `Paid`, `order_payments` one `Success` attempt |

Tinker:

```bash
/opt/alt/php84/usr/bin/php artisan tinker --execute="
dump(App\Models\PaymentWebhookEvent::latest('id')->first()?->only('provider','provider_event_id','event_type','processing_status','order_payment_id','credit_purchase_id'));
"
```

### A2. Replay is a duplicate

1. Resend the same event from the dashboard (or replay the identical payload
   with a valid signature — snippet below).
2. Expect http `200 {'status':'duplicate'}`, **no new** `payment_webhook_events`
   row, and **no second** financial effect (order stays one `Success` attempt,
   exactly one sale/grant).

Count-before/count-after:

```bash
/opt/alt/php84/usr/bin/php artisan tinker --execute="
dump(App\Models\PaymentWebhookEvent::where('event_type','charge.success')->count());
"
```

### A3. Forged / malformed deliveries

Replay a stored payload against the local webhook with tampered or absent
authentication. Run these from tinker on the server (the snippets call the
public webhook through the full stack; the secret stays in config and is never
printed):

```bash
/opt/alt/php84/usr/bin/php artisan tinker --execute="
\$event = App\Models\PaymentWebhookEvent::latest('id')->first();
\$payload = \$event->payload;
\$json = json_encode(\$payload);
\$before = App\Models\PaymentWebhookEvent::count();

// (1) No signature header -> 401, nothing stored.
dump(['unsigned' => Http::asJson()->post(route('webhooks.paystack'), \$payload)->status()]);

// (2) Signed with the wrong secret -> 401.
\$bad = hash_hmac('sha512', \$json, 'not-the-secret');
dump(['wrong_secret' => Http::asJson()->withHeaders(['X-Paystack-Signature' => \$bad])->post(route('webhooks.paystack'), \$payload)->status()]);

// (3) Tampered body signed with the ORIGINAL signature (attacker cannot
//     re-sign) -> 401; the tampered payload is never processed.
\$tampered = json_decode(\$json, true);
if (\is_array(\$tampered)) { \$tampered['data']['reference'] = 'AIC-P-TAMPERED'; }
\$tamperedJson = json_encode(\$tampered);
\$origSig = hash_hmac('sha512', \$json, (string) config('paystack.secret_key'));
dump(['tampered_after_signing' => Http::asJson()->withHeaders(['X-Paystack-Signature' => \$origSig])->post(route('webhooks.paystack'), \$tampered)->status()]);

// (4) Correctly re-signed identical body -> 200 duplicate, no new row.
\$sig = hash_hmac('sha512', \$json, (string) config('paystack.secret_key'));
dump(['signed_replay' => Http::asJson()->withHeaders(['X-Paystack-Signature' => \$sig])->post(route('webhooks.paystack'), \$payload)->status()]);

// The 401s must not store anything: count unchanged.
dump(['events_before' => \$before, 'events_after' => App\Models\PaymentWebhookEvent::count()]);
"
```

Run the same probes by keeping a reference to the tampered payload in memory:

| Check | Expectation |
|---|---|
| unsigned | `401`, no row stored |
| wrong secret | `401`, no row stored |
| tampered after signing | `401`, no row stored, no financial effect |
| signed replay | `200` duplicate, single row, single effect |
| events_before vs events_after | equal (nothing stored by the 401s) |

---

## Flow B — Initialization + callback

Goal: the amount asked is server-frozen, the browser never influences it, and
the callback only ever reports what the server verifies.

### B1. Buy Now initialization

1. Customer A: catalogue Buy Now → checkout → the Paystack test page shows the
   exact frozen `payable_minor` (store Wallet case: `total − applied`), the
   reference is `AIC-P-{date}-{16}` and returns an `authorization_url` that
   redirects in test mode.
2. Cancel checkout in the browser before paying → order still `PendingPayment`,
   one `Initiated/Pending` attempt; the unit is **held** (`holds_reservation`,
   `stock_reserved +1` via `OrderLifecycle::reserveUnit`) but not sold.

Verifier:

```bash
/opt/alt/php84/usr/bin/php artisan tinker --execute="
\$o = App\Models\Order::where('status','pending_payment')->latest('id')->first();
dump([\$o?->order_number, \$o?->status->value, \$o?->holds_reservation, \$o?->payable_minor, \$o?->total_minor]);
dump(\$o?->payments()->latest('id')->first()?->only('status','provider_reference','amount_minor'));
\$inv = App\Models\InventoryTransaction::latest('id')->first();
dump([\$inv?->type->value, \$inv?->quantity_delta, \$inv?->stock_reserved_after, \$inv?->reason]);
"
```

### B2. Callback before / after settlement

1. Customer A returns to the callback **before** Paystack settled
   (mobile-money/async) → page shows a pending state, never "Paid"/"Failed".
2. After settlement resolves, the same callback reference → confirmed; a second
   refresh → no double effect.

### B3. Credit purchase initialization

1. `/credits` → select smallest package → Paystack test checkout shows the
   package price (server-side, from the immutable snapshot).
2. Callback handles the same states; a re-visit is idempotent (the existing
   `credit_purchase` idempotency + ID guard test floor covers the duplicate
   callback case; verify here that a manual re-run does not double-issue).

Verifier:

```bash
/opt/alt/php84/usr/bin/php artisan tinker --execute="
dump(App\Models\OrderPayment::latest('id')->first()?->only('provider_reference','status','amount_minor','currency'));
dump(App\Models\CreditPurchase::latest('id')->first()?->only('reference','status','package_name','amount_minor'));
"
```

Reference guards (already asserted locally by
`PaymentInitializationTest`/`PaymentCallbackTest`): amount never from the
browser; a query-string amount is ignored; a callback for another customer's
reference resolves to "not found".

---

## Flow C — Payment conflict (two customers, one unit)

Goal: when two legitimate customers both pay for the last unit, the losing
order stays **Paid + fulfilment blocked** and is surfaced to admin — never
reframed as a payment failure, never auto-refunded.

1. Pick a product with exactly one unit of available stock (adjust stock via
   `InventoryService` on staging or use a freshly listed product with
   stock 1).
2. Customer A and Customer B each reach the Paystack test checkout for that
   product (both order rows `PendingPayment`, both attempts pending).
3. Pay both with the test card — first verification wins the unit; the second
   is verified successfully but the unit is gone.

| Check | Expectation |
|---|---|
| Winner order | `Paid` + `Processing/Fulfilled` path via `FulfilmentHandoff` |
| Loser order | `Paid` with `fulfilment_blocked_reason` set; payment attempt `Success`; listed in admin (OrderPaymentIndex blocked queue) and Exception Centre |
| Inventory | on-hand/reserved reflect exactly one sale |
| Money | both payments real, neither auto-refunded (refunds are a separate manual decision) |

Verifier:

```bash
/opt/alt/php84/usr/bin/php artisan tinker --execute="
\$orders = App\Models\Order::latest('id')->take(2)->get();
foreach (\$orders as \$o) {
  dump([\$o->order_number, \$o->status->value, \$o->fulfilment_blocked_reason, \$o->successfulPayment()?->status->value]);
}
"
```

Same principle in the auction forms already covered locally: Buy Now vs
auction-close race, and webhook/callback racing a single fulfilment — the
staging re-run here is the two-checkout conflict, which is the money-moving
shape.

---

## Flow D — Late payment

Goal: verified success that arrives after the checkout is closed is recorded
faithfully — attempt `Success`, order stays terminal, nothing is resurrected,
credits are never returned, and the provider gets a 2xx.

Precondition: a fast way to expire a checkout — use the short-deadline `1`-min
ruleset and/or `orders:expire-checkouts` after `payment_due_at` passes.

1. Customer A creates a catalogue checkout; let it expire
   (`payment_due_at` passes → `orders:expire-checkouts` → `PaymentExpired`),
   with inventory reservation and any applied Store Wallet released.
2. Pay the late reference (or deliver the verified `charge.success` webhook).

| Check | Expectation |
|---|---|
| `order_payments` attempt | `Success` recorded (money is real) |
| Order | stays `PaymentExpired` (terminal) + `fulfilment_blocked_reason` |
| Webhook response | `200` (no endless provider retries) |
| Store Wallet | NOT re-applied, NOT released again, no credit return |
| Inventory | unchanged (no second sale) |

Additional shapes (re-verify per existing late-payment coverage,
`LatePaymentPolicyTest`): payment against a `Cancelled` checkout; a forfeited
settlement; and a **second** verified payment on an already-`Paid` order →
recorded on its attempt, no second sale, no auto-refund.

Verifier:

```bash
/opt/alt/php84/usr/bin/php artisan tinker --execute="
\$o = App\Models\Order::where('status','payment_expired')->latest('id')->first();
dump([\$o?->order_number, \$o?->status->value, \$o?->fulfilment_blocked_reason]);
dump(\$o?->payments()->latest('id')->first()?->only('status','provider_reference','amount_minor'));
"
```

---

## Flow E — Failed payment

1. Customer A creates a catalogue checkout (short-deadline), applies nothing
   special, then a `charge.failed` event is delivered for its reference (either
   a real failed test charge or a crafted signed delivery for the attempt's
   reference).

| Check | Expectation |
|---|---|
| Order | `PaymentFailed` (only valid from `PendingPayment`) |
| Inventory | held unit released (reservation `Release`) |
| Store Wallet | any applied value released via the order lifecycle |
| `payment_webhook_events` | row stored with `Failed` outcome, retry-safe |

Verifier:

```bash
/opt/alt/php84/usr/bin/php artisan tinker --execute="
\$o = App\Models\Order::where('status','payment_failed')->latest('id')->first();
dump([\$o?->order_number, \$o?->status->value]);
dump(\$o?->product?->only('stock_on_hand','stock_reserved'));
"
```

### E-pending (async mobile-money state)

The `pending` (payment processing / unsettled) state is exercised live where
the Paystack test checkout offers an async channel; otherwise it is recorded
as already covered at the unit level — a callback whose provider verification
is not yet successful renders `pending`, never "Paid"/"Failed"
(`PaymentCallbackTest`). Both are documented here so the gap is explicit, not
silent.

---

## Flow F — Mechanical + reconciliation

### F1. DB freeze triggers

Try to rewrite frozen rows through raw SQL — each must be refused:

```bash
/opt/alt/php84/usr/bin/php artisan tinker --execute="
try { DB::update('update orders set total_minor = 1 where id = ?', [App\Models\Order::where('status','paid')->value('id')]); dump('orders: NOT frozen!'); }
catch (Throwable \$e) { dump('orders trigger: '.(\$e->getCode() === 45000 ? '45000' : 'other-'.$e->getCode())); }
\$p = App\Models\OrderPayment::where('status','success')->first();
try { DB::update('update order_payments set amount_minor = 1 where id = ?', [\$p->id]); dump('order_payments: NOT frozen!'); }
catch (Throwable \$e) { dump('order_payments trigger: '.(\$e->getCode() === 45000 ? '45000' : 'other-'.$e->getCode())); }
"
```

Expect `45000` on both. (The model-level equivalent is covered by
`OrderPaymentTest` and the Stage 23 raw-update probe.)

### F2. Secrets

- Confirm no secret, card, or full payload appears in
  `storage/logs/laravel.log` after the flows above.
- `/admin/payments` and `/admin/payment-events` render attempt/event data with
  provider reference/timestamps; card details and full auth payloads are
  redacted on screen.

### F3. Reconciliation cross-check

Every `Processed` `charge.success` must map to a financial record:

```bash
/opt/alt/php84/usr/bin/php artisan tinker --execute="
foreach (App\Models\PaymentWebhookEvent::where('event_type','charge.success')->latest('id')->get() as \$e) {
  dump([\$e->provider_event_id, \$e->processing_status,
        \$e->order_payment_id ?? \$e->credit_purchase_id, \$e->processing_error]);
}
"
```

- No `Processed` event missing its `order_payment`/`credit_purchase`.
- No `Failed`/`Received` events left unresolved after all flows.

---

## Results

Record per-flow PASS / FAIL with the verifying query/output.

| Flow | Result |
|---|---|
| A Webhook signature + dedupe | A1 PASS (purchase 7, single Processed row, 1 credit grant, 2 cash entries) · A2 PASS (replay → 200 duplicate, no new row/grant) · A3 PENDING |
| B Initialization + callback | |
| C Payment conflict | |
| D Late payment | |
| E Failed payment (+ pending note) | |
| F Triggers / secrets / reconciliation | |

Verdict: **PASS / FAIL / NEEDS REVIEW**

---

## Gate close

- **PASS all** → Stage 24 gate closes; Stage 25 (scheduler/cron/operations
  audit) opens.
- **Any FAIL or blocked item** → do not proceed. Record evidence, triage the
  discrepancy (never silently repair financial/auction data), reproduce any fix
  in local source, ship it through the normal release flow, and re-verify on
  staging.