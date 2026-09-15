# Reference model — As-Is-Commerce

| | |
|---|---|
| **Model** | **A full e-commerce store that also runs a gamified auction channel** |
| **Market** | Ghana, GHS / GH₵ |
| **Baseline** | Stage 27 runbook committed (`25091df`); this model is the operating frame from now on |
| **Approval** | Validated by the operator (the business owner) before P1 implementation began |
| **Governance** | `AGENTS.md` remains authoritative. This document records the business model; it changes nothing operational and relaxes no rule. |

---

## 1. The model in one sentence

As-Is-Commerce is a **store first** — customers shop, pay and receive goods —
and, for the inventory admin chooses, a **gamified credit auction channel**
layered on top.

## 2. The five roles

| Component | Role in the model |
| --- | --- |
| **Shop** | Primary commerce and the site's identity. Buy Now in cedis, checkout, payment, delivery. |
| **Auction** | A gamified *alternative sales channel* — an experience some products carry, never a parallel identity. |
| **Store Wallet** | Retention / compensation mechanism. Issued to qualifying losers; spendable in the catalogue checkout only. |
| **Credits** | Auction-participation currency. Purchased, consumed when bid, not cedis. |
| **Paystack** | The real-money payment rail. Server-verified. |

## 3. What is authoritative (unchanged, canonical)

- The server decides: auction state, bid validity/ordering, credit consumption,
  inventory ownership, payment validity, order/financial state.
- The browser never decides and never supplies authoritative figures.
- Ledgers are authoritative; balances and projections are derived.
- All money is integer minor units (pesewas).
- Credit and cash are separate systems.
- Store Wallet is catalogue-checkout-only (three layers: pricer, domain guard,
  database CHECK), not a credit refund, not expandable to auctions without
  explicit approval.
- Highest Valid Credit Bid wins (Snapshot Version 3; no Last Bidder Standing).
- Auction settlement is separately configured, frozen, and unrelated to Buy
  Now price.
- A verified Paid order stays Paid even when fulfilment becomes blocked.
- No automatic financial repair; discrepancies are reported, not written over.

## 4. What "shop primary, auction channel" means operationally

1. The homepage and navigation state the store first; a live auction is
   surfaced *on its product's card*, not as a banner above the shop.
2. A product whose unit a live auction holds shows the auction on its listing;
   a product not being auctioned is a plain shop item. Both are somewhere
   obtainable, per `ListingAvailability`.
3. **Admin controls which inventory enters the auction channel.** An explicit,
   product-level opt-in gate (`products.auction_eligible`, default `false`)
   determines what admin may auction. This is P2 — documented now,
   implemented in its own verified step after production prep.
4. Catalogue Buy Now, auction Buy Now and auction settlement are **never mixed
   in one checkout**. Each is a distinct order path under the existing order
   model.
5. No new financial wallet. Paystack and the existing payment/order model are
   the rail and the truth.

## 5. Terminology (unchanged, enforced)

`Credits`, `Credit Balance`, `Highest Bid (Credits)`, `Consumed Credits`,
`Purchased Credits` — and never "Auction Price", "Credit Money" or a cash
value of every credit. Store Wallet is never a "refund" of consumed credits.

## 6. Relationship to the roadmap

- **P1 (positioning)** implements §4.1–4.2 + labels/copy. No schema, no logic.
- **P2 (eligibility gate)** implements §4.3, separately gated.
- **P3 (cart, future)** extends the *store* (§4.5 preserved) with multi-item /
  multi-quantity shopping strictly core to the existing architecture. It must
  never mix auction settlement, credit purchases and shop items — planned in
  `docs/CART_SCOPE_AND_IMPACT.md`, not in P1.