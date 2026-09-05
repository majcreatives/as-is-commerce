<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Auction\Services\BuyNowPricer;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Credit\Services\CreditLedgerService;
use App\Domain\Notifications\Services\NotificationDispatcher;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Domain\Referrals\Actions\AttributeReferral;
use App\Domain\Referrals\Actions\RewardReferral;
use App\Domain\Referrals\Services\ReferralCodes;
use App\Domain\Refunds\Actions\ProcessRefund;
use App\Enums\CreditLotSource;
use App\Enums\CreditTransactionType;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Enums\ReferralStatus;
use App\Models\CreditLot;
use App\Models\CreditTransaction;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * What earns a referrer credits, and what emphatically does not.
 *
 * The rules under all of it: a signup earns nothing, only a verified payment on
 * an order the platform can deliver against qualifies, the reward is issued
 * once, the amount is snapshotted, and every credit goes through the ordinary
 * ledger. There is no referral wallet anywhere.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);

    // A running programme. Off by default, so every test that wants a reward
    // has to say so.
    settings()->set('referrals_enabled', true);
    settings()->set('referral_reward_credits', 50);

    $this->codes = app(ReferralCodes::class);
    $this->attribute = app(AttributeReferral::class);
    $this->rewards = app(RewardReferral::class);
});

// --------------------------------------------------- What does not qualify

it('rewards nothing for a signup alone', function (): void {
    [$referrer, , $referral] = referralPair();

    expect($referral->status)->toBe(ReferralStatus::Attributed)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(0)
        ->and(CreditTransaction::where('type', CreditTransactionType::ReferralCredit)->count())->toBe(0);
});

it('rewards nothing for a checkout nobody paid for', function (): void {
    [$referrer, $joiner] = referralPair();

    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout($joiner, $product->fresh());
    initializePayment($order);

    expect(Referral::first()->status)->toBe(ReferralStatus::Attributed)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(0);
});

it('rewards nothing when a checkout expires', function (): void {
    [$referrer, $joiner] = referralPair();

    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout($joiner, $product->fresh());

    $this->travel(2)->hours();
    app(OrderLifecycle::class)->expire($order->fresh());

    expect(Referral::first()->status)->toBe(ReferralStatus::Attributed)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(0);
});

/*
 * The case the brief singles out. A payment landing on an order that already
 * closed does not resurrect it, and must not qualify a referral either.
 */
it('rewards nothing when a payment lands on a closed checkout', function (string $how): void {
    [$referrer, $joiner] = referralPair();

    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout($joiner, $product->fresh());
    $payment = initializePayment($order);

    if ($how === 'expired') {
        $this->travel(2)->hours();
        app(OrderLifecycle::class)->expire($order->fresh());
    } else {
        app(OrderLifecycle::class)->cancel($order->fresh(), 'Withdrawn.');
    }

    // The payment succeeds afterwards. Real money, recorded, and not a
    // completed purchase.
    payOrder($order->fresh(), $payment->fresh());

    expect($order->fresh()->status)->not->toBe(OrderStatus::Paid)
        ->and(Referral::first()->status)->toBe(ReferralStatus::Attributed)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(0);
})->with(['expired', 'cancelled']);

/*
 * A payment succeeded and nothing could be delivered against it. The platform
 * owes that customer an item or a refund; paying a referrer for it would be
 * rewarding a transaction the business could not complete.
 */
it('rewards nothing for a paid order that could not be delivered', function (): void {
    [$referrer, $joiner] = referralPair();

    $order = blockedPaidOrder($joiner);

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->isFulfilmentBlocked())->toBeTrue()
        ->and(Referral::first()->status)->toBe(ReferralStatus::Attributed)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(0);
});

/*
 * Bidding is not buying. A referred customer can spend every credit they own
 * and lose, and that earns their referrer nothing.
 */
it('rewards nothing for bidding alone', function (): void {
    [$referrer] = referralPair();

    $joiner = Referral::first()->referred;
    creditWalletFor($joiner);
    app(CreditLedgerService::class)->addCredits(
        wallet: creditWalletFor($joiner),
        type: CreditTransactionType::Purchase,
        amount: 500,
    );

    $auction = liveAuction();
    placeBid($auction, $joiner->fresh(), 200);

    expect(Referral::first()->status)->toBe(ReferralStatus::Attributed)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(0);
});

// ------------------------------------------------------- What qualifies

it('rewards a referrer when their referred customer buys something', function (): void {
    [$referrer, $joiner] = referralPair();

    $order = qualifyingPurchase($joiner);

    $referral = Referral::first();

    expect($referral->status)->toBe(ReferralStatus::Rewarded)
        ->and($referral->qualifying_order_id)->toBe($order->id)
        ->and($referral->reward_credits)->toBe(50)
        ->and($referral->credit_transaction_id)->not->toBeNull()
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(50);
});

it('rewards a referrer when their referred customer settles an auction win', function (): void {
    [$referrer, $joiner] = referralPair();

    $product = Product::factory()->active()->create();
    $auction = liveAuction(product: $product);

    // The referred customer needs credits to bid with, bought like anybody.
    app(CreditLedgerService::class)->addCredits(
        wallet: creditWalletFor($joiner),
        type: CreditTransactionType::Purchase,
        amount: 500,
    );

    placeBid($auction, $joiner->fresh(), 200);

    $order = app(CloseAuction::class)
        ->handle($auction, force: true)->settlementOrder;

    payOrder($order->fresh());

    // Neither purchase path is favoured over the other.
    expect(Referral::first()->status)->toBe(ReferralStatus::Rewarded)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(50);
});

// ------------------------------------------------------------- The ledger

it('puts referral credits through the ordinary credit ledger', function (): void {
    [$referrer, $joiner] = referralPair();

    qualifyingPurchase($joiner);

    $referral = Referral::first();
    $transaction = CreditTransaction::find($referral->credit_transaction_id);

    expect($transaction)->not->toBeNull()
        ->and($transaction->type)->toBe(CreditTransactionType::ReferralCredit)
        ->and($transaction->amount)->toBe(50)
        ->and($transaction->wallet->user_id)->toBe($referrer->id);

    // And a lot whose source says where the credits came from.
    $lot = CreditLot::where('credit_transaction_id', $transaction->id)->first();

    expect($lot)->not->toBeNull()
        ->and($lot->source_type)->toBe(CreditLotSource::Referral)
        ->and($lot->original_amount)->toBe(50)
        ->and($lot->remaining_amount)->toBe(50);
});

it('has no referral wallet anywhere', function (): void {
    foreach (['referral_balance', 'reward_balance', 'bonus_balance', 'promo_balance'] as $column) {
        expect(Schema::hasColumn('credit_wallets', $column))->toBeFalse()
            ->and(Schema::hasColumn('users', $column))->toBeFalse()
            ->and(Schema::hasColumn('referrals', $column))->toBeFalse();
    }

    expect(Schema::hasTable('referral_wallets'))->toBeFalse()
        ->and(Schema::hasTable('reward_balances'))->toBeFalse();
});

it('shows referral credits in the customer credit history', function (): void {
    [$referrer, $joiner] = referralPair();

    qualifyingPurchase($joiner);

    $transaction = CreditTransaction::where('type', CreditTransactionType::ReferralCredit)->first();

    // Labelled, so a customer does not have to guess why their balance grew.
    expect($transaction->type->label())->toBe('Referral reward')
        ->and($transaction->description)->toBe('Referral reward');
});

// ------------------------------------------------------------ The amount

it('snapshots what was actually granted', function (): void {
    [$referrer, $joiner] = referralPair();

    qualifyingPurchase($joiner);

    expect(Referral::first()->reward_credits)->toBe(50);

    // The programme becomes more generous afterwards.
    settings()->set('referral_reward_credits', 500);

    // The old reward is unchanged. Recomputing it from today's setting would
    // disagree with the ledger and rewrite history.
    expect(Referral::first()->fresh()->reward_credits)->toBe(50)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(50);
});

it('refuses to let an issued reward be revalued', function (): void {
    [, $joiner] = referralPair();

    qualifyingPurchase($joiner);
    $referral = Referral::first();

    expect(fn () => DB::table('referrals')->where('id', $referral->id)
        ->update(['reward_credits' => 5_000]))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('referrals')->where('id', $referral->id)->delete())
        ->toThrow(QueryException::class);
});

// ------------------------------------------------------ Once, and only once

it('rewards once however many times the payment is delivered', function (): void {
    [$referrer, $joiner] = referralPair();

    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout($joiner, $product->fresh());
    $payment = initializePayment($order);

    foreach (range(1, 5) as $ignored) {
        payOrder($order->fresh(), $payment->fresh());
    }

    expect(CreditTransaction::where('type', CreditTransactionType::ReferralCredit)->count())->toBe(1)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(50);
});

it('rewards once however many times the action is called', function (): void {
    [$referrer, $joiner] = referralPair();

    qualifyingPurchase($joiner);
    $referral = Referral::first();

    foreach (range(1, 4) as $ignored) {
        $this->rewards->reward($referral->fresh());
    }

    expect(CreditTransaction::where('type', CreditTransactionType::ReferralCredit)->count())->toBe(1)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(50);
});

it('rewards nothing a second time when a customer buys again', function (): void {
    [$referrer, $joiner] = referralPair();

    qualifyingPurchase($joiner);
    qualifyingPurchase($joiner->fresh());

    // One referral, one reward, however many purchases follow.
    expect(Referral::count())->toBe(1)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(50);
});

it('refuses a second reward at the database', function (): void {
    [, $joiner] = referralPair();

    qualifyingPurchase($joiner);
    $referral = Referral::first();

    // A second referral cannot claim the same ledger row.
    expect(fn () => DB::table('referrals')->insert([
        'referrer_user_id' => bidder(0)->id,
        'referred_user_id' => bidder(0)->id,
        'code_used' => 'DUPE0001',
        'status' => 'attributed',
        'credit_transaction_id' => $referral->credit_transaction_id,
        'attributed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

// ------------------------------------------------------ Switches and caps

it('rewards nothing while the programme is switched off', function (): void {
    settings()->set('referrals_enabled', false);

    [$referrer, $joiner] = referralPair();

    qualifyingPurchase($joiner);

    // The qualifying purchase is still recorded: it happened, and the referral
    // stays retryable rather than being lost.
    expect(Referral::first()->status)->toBe(ReferralStatus::Qualified)
        ->and(Referral::first()->qualifying_order_id)->not->toBeNull()
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(0);
});

it('rewards nothing when no amount is configured', function (): void {
    settings()->set('referral_reward_credits', 0);

    [$referrer, $joiner] = referralPair();

    qualifyingPurchase($joiner);

    expect(Referral::first()->status)->toBe(ReferralStatus::Qualified)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(0);
});

it('stops rewarding a referrer who reaches the cap', function (): void {
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
        ->and(Referral::where('status', ReferralStatus::Qualified)->count())->toBe(1)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(50);
});

it('treats a zero cap as no cap at all', function (): void {
    settings()->set('referral_max_rewards_per_referrer', 0);

    $referrer = bidder(0);
    $code = $this->codes->forUser($referrer);

    foreach (range(1, 3) as $ignored) {
        $joiner = bidder(0);
        $this->attribute->handle($joiner, $code);
        qualifyingPurchase($joiner->fresh());
    }

    expect(Referral::where('status', ReferralStatus::Rewarded)->count())->toBe(3)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(150);
});

// -------------------------------------------------------------- Refunds

/*
 * The rule that matters most after issuance. A refund is a separate financial
 * event; it does not reach back into an immutable ledger.
 */
it('claws nothing back when the qualifying purchase is refunded', function (): void {
    [$referrer, $joiner] = referralPair();

    $order = blockedPaidOrder($joiner);

    // Blocked orders do not qualify, so force the qualifying path with a real
    // purchase, then refund a different, blocked one.
    $qualifying = qualifyingPurchase($joiner->fresh());

    expect(creditWalletFor($referrer)->fresh()->balance)->toBe(50);

    $refund = requestRefund($order->fresh(), actor: userWithRole('admin'));
    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'processed', 'RF-REF'));
    app(ProcessRefund::class)->handle($refund, userWithRole('admin'));

    // The referral and its credits are exactly as they were.
    expect(Referral::first()->status)->toBe(ReferralStatus::Rewarded)
        ->and(Referral::first()->reward_credits)->toBe(50)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(50);
});

it('has no clawback mechanism at all', function (): void {
    expect(method_exists(RewardReferral::class, 'clawback'))->toBeFalse()
        ->and(method_exists(RewardReferral::class, 'revoke'))->toBeFalse()
        ->and(method_exists(RewardReferral::class, 'reverse'))->toBeFalse();
});

// ------------------------------------------------- The Buy Now discount

/*
 * The invariant referral credits are most likely to be expected to break.
 * Credits sitting unused in a wallet have bought nothing and reduce nothing.
 */
it('gives no buy now discount for unspent referral credits', function (): void {
    [$referrer, $joiner] = referralPair();

    qualifyingPurchase($joiner);

    expect(creditWalletFor($referrer)->fresh()->balance)->toBe(50);

    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    $quote = app(BuyNowPricer::class)->quote($auction, $referrer->fresh());

    expect($quote->eligibleCredits)->toBe(0)
        ->and($quote->discount->minor)->toBe(0)
        ->and($quote->payable->minor)->toBe(550_000);
});

/*
 * And once they are spent on bids they behave exactly like any other credit:
 * consumed permanently, and earning the ordinary discount on that auction.
 */
it('treats spent referral credits like any other bid credits', function (): void {
    [$referrer, $joiner] = referralPair();

    qualifyingPurchase($joiner);

    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    placeBid($auction, $referrer->fresh(), 50);

    $quote = app(BuyNowPricer::class)->quote($auction->fresh(), $referrer->fresh());

    expect($quote->eligibleCredits)->toBe(50)
        ->and($quote->discount->minor)->toBe(5_000)
        // And they are gone, like every other bid credit.
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(0);
});

// -------------------------------------------------------- Notification

it('tells a referrer their reward arrived, in credits', function (): void {
    [$referrer, $joiner] = referralPair();

    qualifyingPurchase($joiner);

    $notification = notificationsFor($referrer, NotificationType::ReferralRewarded)->first();

    expect($notification)->not->toBeNull()
        // The platform's locked capitalisation for a credit count.
        ->and($notification->message)->toContain('50 Credits')
        ->and($notification->message)->toContain('not cash')
        // Never a cedis figure, and never the referred customer's name.
        ->and($notification->message)->not->toContain('GH₵')
        ->and($notification->message)->not->toContain($joiner->name ?? 'nothing');
});

it('rewards successfully even when every notification throws', function (): void {
    [$referrer, $joiner] = referralPair();

    app()->bind(
        NotificationDispatcher::class,
        fn (): NotificationDispatcher => new class extends NotificationDispatcher
        {
            public function send(
                User $recipient,
                NotificationType $type,
                string $title,
                string $message,
                ?string $eventKey = null,
                ?string $actionUrl = null,
                ?string $actionLabel = null,
                array $context = [],
            ): ?Notification {
                throw new RuntimeException('The notification layer is broken.');
            }
        },
    );

    qualifyingPurchase($joiner);

    expect(Referral::first()->status)->toBe(ReferralStatus::Rewarded)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(50);
});
