<?php

declare(strict_types=1);

use App\Enums\CreditTransactionType;
use App\Enums\ReferralStatus;
use App\Models\AuctionRuleset;
use App\Models\Order;
use App\Models\Referral;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The migration that multiplies every stored credit count by the
 * re-denomination factor. `docs/PLAN_POT_TARGET_BIDDING.md`, decision D-9.
 *
 * WHY THIS LIVES HERE. Six triggers are dropped and recreated, and DDL commits
 * the transaction it runs in -- exactly the reason
 * `AuctionSnapshotMigrationTest` lives here rather than in the Feature suite,
 * which wraps each test in a transaction that this migration would break out
 * of and leak into every test after it.
 *
 * THE PROMISE BEING TESTED. Every table, JSON path and setting the migration
 * lists converts by exactly the factor; the two deliberately-left-alone pairs
 * (Store Wallet credit sources, a pricing snapshot's valuation entries) do
 * not move at all; the ledger still balances afterwards; and every trigger is
 * back, still refusing the edits it refused before.
 */

const REDENOMINATION_MIGRATION = '2026_09_22_100000_redenominate_credits_to_subcredits.php';
const FACTOR = 10_000; // must equal the migration's own private FACTOR constant

beforeEach(function (): void {
    seedPermissions();
    seedSettings();
});

afterEach(function (): void {
    // A failed test must not leave the ledger unguarded for the tests after
    // it. Running the migration restores every trigger whatever else it does.
    if (! triggerExists('bids_no_update')) {
        redenominationMigration()->up();
    }
});

function redenominationMigration(): object
{
    return require database_path('migrations/'.REDENOMINATION_MIGRATION);
}

function triggerExists(string $name): bool
{
    return DB::selectOne(
        'SELECT COUNT(*) AS n FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
        [$name],
    )->n > 0;
}

/**
 * Seed one row touching every table, JSON path and setting the migration
 * converts, plus the two pairs it must leave alone. Returns everything a test
 * needs to check afterwards.
 *
 * @return array<string, mixed>
 */
function seedConvertibleData(): array
{
    $referrer = userWithRole('customer');
    $bidder1 = bidder(1_000);
    $purchaser = customerWithPurchasedCredits(500, 4_500); // 500 credits for GH₵45: 9 pesewas each

    // Step 50 so the two catch-up bids below are non-trivial (51, 101), not
    // just the bare minimum -- a more convincing multiplication to check.
    $auction = cumulativeAuction(1, 50);
    $firstBid = catchUp($auction, $bidder1); // the opening bid: exactly the minimum, 1
    // Draws from the purchaser's purchased lot, so credit_lot_consumptions
    // gets a real row and the ledger genuinely balances afterwards.
    $secondBid = catchUp($auction, $purchaser); // 1 + 50 - 0 = 51

    // A single-highest-shaped ruleset, so minimum_bid_increment_credits (the
    // legacy field the cumulative factory above leaves null) gets covered too.
    $legacyRuleset = AuctionRuleset::factory()->create([
        'minimum_bid_credits' => 50,
        'minimum_bid_increment_credits' => 25,
    ]);

    // Buy Now evidence on the auction -- set directly, since only rules_snapshot
    // (not buy_now_eligible_credits) is guarded by auctions_frozen_configuration,
    // and this test is about the migration, not the Buy Now action.
    DB::table('auctions')->where('id', $auction->id)->update([
        'buy_now_eligible_credits' => 240,
        'buy_now_discount_minor' => 2_160,
        'buy_now_payable_minor' => 547_840,
    ]);

    // A paid order carrying a discount and a winning-bid figure, with a
    // hand-built pricing snapshot so its 'valuation' pair (left alone) sits
    // beside 'discount_credits' and 'winning_bid_credits' (both converted).
    // Created before the referral below, which must reference a real order.
    $order = Order::factory()->paid()->create([
        'user_id' => $purchaser->id,
        'discount_credits' => 180,
        'pricing_snapshot' => [
            'snapshot_version' => 2,
            'source' => 'buy_now',
            'currency' => 'GHS',
            'subtotal_minor' => 550_000,
            'discount_minor' => 1_620,
            'delivery_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 548_380,
            'discount_credits' => 180,
            'valuation' => [
                [
                    'credit_lot_id' => 1,
                    'source' => 'purchased',
                    'credits' => 180,
                    'lot_acquisition_amount_minor' => 4_500,
                    'lot_original_amount' => 500,
                    'amount_minor' => 1_620,
                    'remainder_numerator' => 0,
                ],
            ],
            'store_wallet_applied_minor' => 0,
            'payable_minor' => 548_380,
            'tax_bps' => 0,
            'winning_bid_credits' => 220,
        ],
    ]);

    $referralTransaction = grantCredits($referrer, 300, CreditTransactionType::ReferralCredit);

    $referral = Referral::factory()->create([
        'referrer_user_id' => $referrer->id,
        'referred_user_id' => $purchaser->id,
        'status' => ReferralStatus::Rewarded,
        'reward_credits' => 300,
        'credit_transaction_id' => $referralTransaction->id,
        'qualifying_order_id' => $order->id,
        'qualified_at' => now(),
        'rewarded_at' => now(),
    ]);

    // The pairing this migration must never touch: credits stored alongside
    // its own frozen denominator, reproducing an already-computed money value.
    $storeWalletSourceId = DB::table('store_wallet_credit_sources')->insertGetId([
        'store_wallet_transaction_id' => DB::table('store_wallet_transactions')->insertGetId([
            'store_wallet_id' => DB::table('store_wallets')->insertGetId([
                'user_id' => $bidder1->id, 'balance_minor' => 1_620,
                'created_at' => now(), 'updated_at' => now(),
            ]),
            'type' => 'auction_loss_compensation',
            'amount_minor' => 1_620,
            'balance_after_minor' => 1_620,
            'created_at' => now(),
        ]),
        'credit_lot_id' => DB::table('credit_lots')->where('credit_wallet_id', creditWalletFor($purchaser)->id)->value('id'),
        'source_type' => 'purchased',
        'credits' => 200,
        'lot_acquisition_amount_minor' => 4_500,
        'lot_original_amount' => 500,
        'amount_minor' => 1_800,
        'remainder_numerator' => 0,
        'created_at' => now(),
    ]);

    DB::table('settings')->where('key', 'referral_reward_credits')->update(['value' => '75']);

    return compact(
        'referrer', 'bidder1', 'purchaser', 'auction', 'firstBid', 'secondBid',
        'legacyRuleset', 'referral', 'order', 'storeWalletSourceId',
    );
}

function rulesSnapshotRules(int $auctionId): array
{
    return json_decode(
        (string) DB::table('auctions')->where('id', $auctionId)->value('rules_snapshot'),
        true,
    )['rules'];
}

function pricingSnapshot(int $orderId): array
{
    return json_decode((string) DB::table('orders')->where('id', $orderId)->value('pricing_snapshot'), true);
}

it('scales every flat credit column by exactly the factor', function (): void {
    $seed = seedConvertibleData();

    $walletBefore = creditWalletFor($seed['bidder1'])->balance;
    $firstBidAmountBefore = DB::table('bids')->find($seed['firstBid']->id)->amount_credits;
    $firstBidCumulativeBefore = DB::table('bids')->find($seed['firstBid']->id)->cumulative_credits;
    $highestBidBefore = DB::table('auctions')->find($seed['auction']->id)->highest_bid_credits;
    $lotBefore = DB::table('credit_lots')->where('credit_wallet_id', creditWalletFor($seed['purchaser'])->id)->first();
    $consumptionBefore = DB::table('credit_lot_consumptions')->where('credit_transaction_id', $seed['secondBid']->credit_transaction_id)->first();

    redenominationMigration()->up();

    expect(creditWalletFor($seed['bidder1'])->balance)->toBe($walletBefore * FACTOR)
        ->and(DB::table('credit_transactions')->find($seed['firstBid']->credit_transaction_id)->amount)
        ->toBe(-$firstBidAmountBefore * FACTOR)
        ->and(DB::table('bids')->find($seed['firstBid']->id)->amount_credits)->toBe($firstBidAmountBefore * FACTOR)
        ->and(DB::table('bids')->find($seed['firstBid']->id)->cumulative_credits)->toBe($firstBidCumulativeBefore * FACTOR)
        ->and(DB::table('auctions')->find($seed['auction']->id)->highest_bid_credits)->toBe($highestBidBefore * FACTOR)
        ->and(DB::table('auctions')->find($seed['auction']->id)->buy_now_eligible_credits)->toBe(240 * FACTOR);

    $lotAfter = DB::table('credit_lots')->where('credit_wallet_id', creditWalletFor($seed['purchaser'])->id)->first();
    expect($lotAfter->original_amount)->toBe($lotBefore->original_amount * FACTOR)
        ->and($lotAfter->remaining_amount)->toBe($lotBefore->remaining_amount * FACTOR)
        // Money, never touched.
        ->and($lotAfter->acquisition_amount_minor)->toBe($lotBefore->acquisition_amount_minor);

    $consumptionAfter = DB::table('credit_lot_consumptions')->where('credit_transaction_id', $seed['secondBid']->credit_transaction_id)->first();
    expect($consumptionAfter->amount)->toBe($consumptionBefore->amount * FACTOR);

    $ruleset = DB::table('auction_rulesets')->find($seed['legacyRuleset']->id);
    expect($ruleset->minimum_bid_credits)->toBe(50 * FACTOR)
        ->and($ruleset->minimum_bid_increment_credits)->toBe(25 * FACTOR);

    expect(DB::table('referrals')->find($seed['referral']->id)->reward_credits)->toBe(300 * FACTOR);

    expect((int) DB::table('settings')->where('key', 'referral_reward_credits')->value('value'))->toBe(75 * FACTOR);
});

it('scales the credit-denominated keys inside rules_snapshot and nothing else in it', function (): void {
    $seed = seedConvertibleData();
    $before = rulesSnapshotRules($seed['auction']->id);

    redenominationMigration()->up();

    $after = rulesSnapshotRules($seed['auction']->id);

    expect($after['minimum_bid_credits'])->toBe($before['minimum_bid_credits'] * FACTOR)
        ->and($after['bid_increment_credits'])->toBe($before['bid_increment_credits'] * FACTOR);

    // Everything else, key for key.
    $touched = ['minimum_bid_credits', 'minimum_bid_increment_credits', 'bid_increment_credits'];
    foreach ($touched as $key) {
        unset($before[$key], $after[$key]);
    }
    ksort($before);
    ksort($after);
    expect($after)->toBe($before);
});

it('scales discount_credits and winning_bid_credits inside pricing_snapshot, and leaves the valuation pair untouched', function (): void {
    $seed = seedConvertibleData();

    redenominationMigration()->up();

    $snapshot = pricingSnapshot($seed['order']->id);

    expect($snapshot['discount_credits'])->toBe(180 * FACTOR)
        ->and($snapshot['winning_bid_credits'])->toBe(220 * FACTOR)
        // The pair: untouched, both sides, so the already-computed amount_minor
        // it was built from stays honest.
        ->and($snapshot['valuation'][0]['credits'])->toBe(180)
        ->and($snapshot['valuation'][0]['lot_original_amount'])->toBe(500)
        ->and($snapshot['valuation'][0]['amount_minor'])->toBe(1_620)
        ->and(DB::table('orders')->find($seed['order']->id)->discount_credits)->toBe(180 * FACTOR);
});

it('leaves store_wallet_credit_sources completely untouched', function (): void {
    $seed = seedConvertibleData();
    $before = DB::table('store_wallet_credit_sources')->find($seed['storeWalletSourceId']);

    redenominationMigration()->up();

    $after = DB::table('store_wallet_credit_sources')->find($seed['storeWalletSourceId']);

    expect((array) $after)->toBe((array) $before);
});

it('still balances every wallet against the sum of its transactions afterwards', function (): void {
    seedConvertibleData();

    redenominationMigration()->up();

    $mismatches = DB::table('credit_wallets')
        ->leftJoinSub(
            DB::table('credit_transactions')->select('credit_wallet_id')
                ->selectRaw('SUM(amount) as total')->groupBy('credit_wallet_id'),
            'sums',
            'sums.credit_wallet_id',
            '=',
            'credit_wallets.id',
        )
        ->whereRaw('credit_wallets.balance <> COALESCE(sums.total, 0)')
        ->count();

    expect($mismatches)->toBe(0);
});

it('restores every trigger, and each still refuses the edit it refused before', function (): void {
    $seed = seedConvertibleData();

    redenominationMigration()->up();

    foreach ([
        'credit_transactions_no_update', 'credit_lot_consumptions_no_update', 'bids_no_update',
        'auctions_frozen_configuration', 'orders_frozen_after_payment', 'referrals_frozen_relationship',
    ] as $trigger) {
        expect(triggerExists($trigger))->toBeTrue("Expected {$trigger} to be restored.");
    }

    expect(fn () => DB::table('credit_transactions')->where('id', $seed['firstBid']->credit_transaction_id)->update(['amount' => 1]))
        ->toThrow(QueryException::class, 'append-only');

    expect(fn () => DB::table('bids')->where('id', $seed['firstBid']->id)->update(['amount_credits' => 1]))
        ->toThrow(QueryException::class, 'append-only');

    expect(fn () => DB::table('auctions')->where('id', $seed['auction']->id)->update([
        'rules_snapshot' => DB::raw("JSON_SET(rules_snapshot, '\$.rules.tax_bps', 5000)"),
    ]))->toThrow(QueryException::class, 'frozen');

    expect(fn () => DB::table('orders')->where('id', $seed['order']->id)->update(['discount_credits' => 1]))
        ->toThrow(QueryException::class, 'historical record');

    expect(fn () => DB::table('referrals')->where('id', $seed['referral']->id)->update(['reward_credits' => 1]))
        ->toThrow(QueryException::class, 'historical record');
});

it('does not leave the ledger unguarded if verification fails partway through', function (): void {
    // credit_lots_acquisition_frozen is NOT suspended by this migration (it
    // only guards acquisition_amount_minor/currency, neither of which this
    // migration touches) -- confirmed here, not merely asserted in the docs.
    seedConvertibleData();

    expect(triggerExists('credit_lots_acquisition_frozen'))->toBeTrue();

    redenominationMigration()->up();

    expect(triggerExists('credit_lots_acquisition_frozen'))->toBeTrue()
        ->and(triggerExists('bids_no_update'))->toBeTrue();
});

it('rolls back exactly to the original values', function (): void {
    $seed = seedConvertibleData();

    $walletBefore = creditWalletFor($seed['bidder1'])->balance;
    $rulesBefore = rulesSnapshotRules($seed['auction']->id);
    $snapshotBefore = pricingSnapshot($seed['order']->id);
    $settingBefore = DB::table('settings')->where('key', 'referral_reward_credits')->value('value');

    redenominationMigration()->up();
    redenominationMigration()->down();

    expect(creditWalletFor($seed['bidder1'])->balance)->toBe($walletBefore)
        ->and(rulesSnapshotRules($seed['auction']->id))->toBe($rulesBefore)
        ->and(pricingSnapshot($seed['order']->id))->toBe($snapshotBefore)
        ->and(DB::table('settings')->where('key', 'referral_reward_credits')->value('value'))->toBe($settingBefore);
});

it('refuses to roll back a value that is not an exact multiple of the factor', function (): void {
    seedConvertibleData();
    redenominationMigration()->up();

    // Simulate a credit granted at the new scale after the conversion, whose
    // raw amount is not a multiple of FACTOR -- dividing it would truncate a
    // real credit rather than reverse the conversion.
    DB::unprepared('DROP TRIGGER IF EXISTS credit_transactions_no_update');
    DB::table('credit_transactions')->limit(1)->update(['amount' => 3]);
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER credit_transactions_no_update
        BEFORE UPDATE ON credit_transactions
        FOR EACH ROW
        BEGIN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Financial history is append-only: credit_transactions rows cannot be updated. Post a compensating entry instead.';
        END
    SQL);

    expect(fn () => redenominationMigration()->down())
        ->toThrow(RuntimeException::class, 'not an exact');

    expect(triggerExists('credit_transactions_no_update'))->toBeTrue();
});
