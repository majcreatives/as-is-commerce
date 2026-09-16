# Stage 29.1 — P2 auction eligibility gate (operator checklist)

Deploys the `stage29.1` GitHub Release (P2, `docs/VERIFY_29_AUCTION_ELIGIBILITY_P2.md`)
to the existing staging install. Nothing here is run by an AI agent on the
server; the human operator executes these steps over SSH (paths from
`AGENTS.md` §86; credentials never stored here or in git).

**What this release carries**

- P2 — auction-channel eligibility gate (commit `fe6c58f`):
  - `products.auction_eligible` boolean, default `false`, with a MariaDB
    `CHECK (auction_eligible IN (0, 1))`.
  - `CreateAuction` refuses any product never marked eligible.
  - Admin auction picker offers only opted-in products.
  - Admin catalogue form gains the "Opt in to the auction channel" checkbox.
  - Factory/test/regression-guard updates.
- P2 runbook (commit `9ba0558`).

**A migration IS included** — the new `products.auction_eligible` column. The
`DB::statement`/`Schema::table` pair is additive, MariaDB-compatible
(`DROP CONSTRAINT`, not `DROP CHECK`, on the way out — AGENTS.md §92).

---

## 1. Create the release (local/GitHub, operator)

From a clean checkout at `origin/main` (expect `9ba0558`; check `git status`
first, keep `.gitignore`'s local `.opencode` line out of any commit):

```bash
git tag stage29.1
git push origin stage29.1
```

The push of the `stage*` tag triggers the GitHub Actions workflow
(`.github/workflows/build-deploy.yml`), which builds
`as-is-commerce-stage29.1.zip` and attaches it to a new GitHub Release named
`stage29.1`. Wait for the run to finish:

```text
https://github.com/majcreatives/as-is-commerce/actions
https://github.com/majcreatives/as-is-commerce/releases
```

Only ever download that zip — never a locally built one.

---

## 2. Confirm the release exists

Verify on GitHub that a non-draft Release named `stage29.1` exists with the
asset `as-is-commerce-stage29.1.zip`.

---

## 3. Backup + extract on staging

```bash
ssh -p 65002 u146516859@89.116.53.20
```

```bash
cd /home/u146516859/domains/darksalmon-swan-978886.hostingersite.com/public_html
cp as-is-commerce-stage20/.env .env.bak-stage29.1
mv as-is-commerce-stage20 as-is-commerce-stage20.bak-29.1
mkdir -p as-is-commerce-stage20 temp-stage29.1
cd temp-stage29.1
# Download as-is-commerce-stage29.1.zip into this dir from the GitHub Release,
# then:
unzip as-is-commerce-stage29.1.zip -d ../as-is-commerce-stage20
cd ../as-is-commerce-stage20
cp ../.env.bak-stage29.1 .env
```

Stop and confirm: `ls -la` shows `.env`, `artisan`, `public`, `vendor`. If
anything looks wrong, delete the new dir and
`mv as-is-commerce-stage20.bak-29.1 as-is-commerce-stage20`.

---

## 4. Storage + permissions

```bash
/opt/alt/php84/usr/bin/php artisan storage:link
chmod -R u+rwX storage bootstrap/cache
```

---

## 5. Clear + rebuild caches

```bash
/opt/alt/php84/usr/bin/php artisan config:clear
/opt/alt/php84/usr/bin/php artisan config:cache
/opt/alt/php84/usr/bin/php artisan route:cache
/opt/alt/php84/usr/bin/php artisan view:cache
```

---

## 6. Schema + boot

```bash
/opt/alt/php84/usr/bin/php artisan migrate --force
/opt/alt/php84/usr/bin/php artisan about
/opt/alt/php84/usr/bin/php artisan tinker --execute="
dump(collect(DB::select('SHOW COLUMNS FROM products'))->pluck('Field')->contains('auction_eligible'));
"
```

Expect the column exists (`true`). Nothing needs seeding — the staging
database is untouched otherwise.

---

## 7. Health + smoke

1. `GET https://darksalmon-swan-978886.hostingersite.com/health`
   → `{"status":"ok"}`.
2. One customer page (catalogue/product/login).
3. One admin page (dashboard).
4. No `.env` value, secret, or stack trace in any rendered HTML or log.

---

## 8. Verify P2 on staging (per `docs/VERIFY_29_AUCTION_ELIGIBILITY_P2.md` §4.2)

1. **Admin → Catalog → create a product:** "Opt in to the auction channel" is
   present and **unchecked by default**; saving leaves the product shop-only.
2. **Admin → Catalog → edit** an existing product: checkbox reflects the
   saved value; toggling it persists.
3. **Admin → Auctions → create:** the product picker lists only opted-in
   products; a shop-only product is not offered.
4. **Backend refusal:** a never-opted-in product cannot be auctioned. Since the
   picker already hides it, this is belt-and-braces — confirm via tinker that
   `CreateAuction` throws for a non-eligible product, e.g.:

   ```bash
   /opt/alt/php84/usr/bin/php artisan tinker --execute="
   \$p = App\Models\Product::factory()->auctionIneligible()->create();
   try {
       app(App\Domain\Auction\Actions\CreateAuction::class)->handle(
           \$p, App\Models\AuctionRuleset::factory()->active()->create(),
           App\Support\Money::fromMinor(10000),
       );
       dump('NOT REFUSED');
   } catch (App\Domain\Auction\Rules\InvalidAuctionRules \$e) {
       dump('REFUSED: ' . \$e->getMessage());
   }
   "
   ```

   Expect `REFUSED: This product is not marked as eligible for the auction
   channel.` (Then clean up the test product.)

5. Existing Buy Now/credit/auction flows still behave as before (quick
   spot-check only; the new column is additive and the engine is unchanged).

---

## 9. After verification

- Update `docs/VERIFY_29_AUCTION_ELIGIBILITY_P2.md` `Results` table rows #4–#6
  with the outcomes and gate-close verdict, and commit/push.
- Leave `as-is-commerce-stage20.bak-29.1` and `.env.bak-stage29.1` in place
  until the next deploy succeeds, then remove them.
- The tag `stage29.1` stays as the permanent versioned record; nothing is
  deleted.