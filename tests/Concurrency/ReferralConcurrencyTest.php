<?php

declare(strict_types=1);

use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Referrals\Actions\AttributeReferral;
use App\Domain\Referrals\Actions\RewardReferral;
use App\Domain\Referrals\Services\ReferralCodes;
use App\Enums\CreditTransactionType;
use App\Enums\ReferralStatus;
use App\Models\CreditTransaction;
use App\Models\Product;
use App\Models\Referral;
use Illuminate\Support\Facades\DB;

/*
 * Two of everything at once, against real MySQL locks.
 *
 * Truncation rather than a wrapping transaction: a second connection cannot
 * see rows an uncommitted transaction has written, so under RefreshDatabase
 * these tests would observe an empty database and pass without exercising
 * anything.
 *
 * The invariant: one relationship, one qualifying event, one reward, one credit
 * transaction -- however many events arrive and however they interleave.
 * A duplicated referral reward is credits created out of nothing.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);

    settings()->set('referrals_enabled', true);
    settings()->set('referral_reward_credits', 50);

    $this->codes = app(ReferralCodes::class);
    $this->attribute = app(AttributeReferral::class);
    $this->rewards = app(RewardReferral::class);

    config(['database.connections.second' => config('database.connections.mysql')]);
    DB::purge('second');
});

afterEach(function (): void {
    try {
        while (DB::connection('second')->transactionLevel() > 0) {
            DB::connection('second')->rollBack();
        }
    } catch (Throwable) {
        // Connection already gone.
    }

    DB::purge('second');
});

/*
 * The primitive the reward rests on. Without a real lock on the referral row,
 * two payment events would both read `Qualified` and both issue credits.
 */
it('holds the referral row while a reward is being issued', function (): void {
    [, , $referral] = referralPair();

    DB::beginTransaction();

    DB::table('referrals')->where('id', $referral->id)->lockForUpdate()->first();

    DB::connection('second')->statement('SET SESSION innodb_lock_wait_timeout = 1');
    DB::connection('second')->beginTransaction();

    $blocked = false;

    try {
        DB::connection('second')->table('referrals')
            ->where('id', $referral->id)
            ->lockForUpdate()
            ->first();
    } catch (Throwable) {
        $blocked = true;
    }

    DB::connection('second')->rollBack();
    DB::rollBack();

    expect($blocked)->toBeTrue('A second connection must not take the referral lock while it is held.');
});

/*
 * The case from the brief: two events qualifying the same referral. One
 * relationship, one qualifying event, one reward, one credit transaction.
 */
it('issues one reward when the same payment arrives repeatedly', function (): void {
    [$referrer, $joiner] = referralPair();

    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout($joiner, $product->fresh());
    $payment = initializePayment($order);

    // Webhook, callback, retry, retry, retry.
    foreach (range(1, 5) as $ignored) {
        payOrder($order->fresh(), $payment->fresh());
    }

    expect(Referral::count())->toBe(1)
        ->and(Referral::first()->status)->toBe(ReferralStatus::Rewarded)
        ->and(CreditTransaction::where('type', CreditTransactionType::ReferralCredit)->count())->toBe(1)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(50);
});

it('issues one reward when the action is called repeatedly', function (): void {
    [$referrer, $joiner] = referralPair();

    $order = qualifyingPurchase($joiner);

    foreach (range(1, 6) as $ignored) {
        $this->rewards->qualify(Referral::first(), $order);
        $this->rewards->reward(Referral::first());
    }

    expect(CreditTransaction::where('type', CreditTransactionType::ReferralCredit)->count())->toBe(1)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(50)
        // And the snapshot was written once.
        ->and(Referral::first()->reward_credits)->toBe(50);
});

it('creates one relationship when attribution is attempted repeatedly', function (): void {
    $referrer = bidder(0);
    $code = $this->codes->forUser($referrer);
    $joiner = bidder(0);

    foreach (range(1, 5) as $ignored) {
        $this->attribute->handle($joiner->fresh(), $code);
    }

    expect(Referral::count())->toBe(1);
});

/*
 * The cap is counted under the same lock the reward is issued under, so two
 * referrals qualifying together cannot both slip past a cap of one.
 */
it('respects the cap when two referrals qualify together', function (): void {
    settings()->set('referral_max_rewards_per_referrer', 1);

    $referrer = bidder(0);
    $code = $this->codes->forUser($referrer);

    $first = bidder(0);
    $second = bidder(0);

    $this->attribute->handle($first, $code);
    $this->attribute->handle($second, $code);

    qualifyingPurchase($first->fresh());
    qualifyingPurchase($second->fresh());

    expect(Referral::where('status', ReferralStatus::Rewarded)->count())->toBe(1)
        ->and(CreditTransaction::where('type', CreditTransactionType::ReferralCredit)->count())->toBe(1)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(50);
});

/*
 * However the events fall, nothing outside the referral programme moves. The
 * referred customer's own wallet is untouched, and no bid credit is restored.
 */
it('leaves every other ledger untouched', function (): void {
    [$referrer, $joiner] = referralPair();

    $creditRows = DB::table('credit_transactions')->count();
    $inventory = DB::table('inventory_transactions')->count();

    qualifyingPurchase($joiner);

    expect(creditWalletFor($joiner)->fresh()->balance)->toBe(0)
        // Exactly one new credit row: the referrer's reward.
        ->and(DB::table('credit_transactions')->count())->toBe($creditRows + 1)
        ->and(DB::table('cash_transactions')->count())->toBe(0)
        // The purchase moved stock; the referral moved none of it.
        ->and(DB::table('inventory_transactions')->count())->toBeGreaterThan($inventory)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(50);
});
