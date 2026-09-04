<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Auction\Actions\CreateAuction;
use App\Domain\Auction\Services\AuctionLifecycle;
use App\Domain\Catalog\Exceptions\StockMutationForbidden;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Orders\Actions\FulfillOrderPayment;
use App\Domain\Orders\Actions\InitializeOrderPayment;
use App\Domain\Orders\Exceptions\PaymentNotAcceptable;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Domain\Shared\Ledger\BalanceMutationForbidden;
use App\Domain\Shared\Money\Money;
use App\Enums\AuctionClosureReason;
use App\Enums\AuctionStatus;
use App\Enums\CreditTransactionType;
use App\Enums\InventoryTransactionType;
use App\Enums\OrderStatus;
use App\Models\AuctionRuleset;
use App\Models\CreditTransaction;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Guards against the checkout layer drifting away from what the business
 * model actually says.
 *
 * These are structural on purpose. They read the schema and the stored records
 * rather than exercising a path, because the failures they guard against
 * arrive as a well-meaning column or a plausible-looking calculation.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);

    $this->close = app(CloseAuction::class);
});

// ------------------------------------------------------------ Schema sweep

/*
 * An order is a GHS obligation. If these tables ever gain a credit balance, a
 * wallet reference or a credit charge, somebody has started paying for things
 * with credits.
 */
it('has no credit column on any order table', function (string $table): void {
    $columns = collect(DB::select("SHOW COLUMNS FROM {$table}"))
        ->pluck('Field')
        ->map(fn (string $c): string => strtolower($c))
        ->all();

    foreach (['wallet', 'credit_balance', 'credit_transaction', 'credit_lot'] as $forbidden) {
        expect(collect($columns)->filter(fn (string $c): bool => str_contains($c, $forbidden))->all())
            ->toBe([], "[{$table}] should have no column containing [{$forbidden}]");
    }
})->with(['orders', 'order_items', 'order_payments']);

/*
 * The one credit column that does exist, and what it is for. `discount_credits`
 * is a count kept as evidence for `discount_minor`; it is never money and
 * never a charge.
 */
it('keeps the discount credit count separate from the discount amount', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $buyer = bidder(1_000);

    placeBid($auction, $buyer, 150);
    $order = buyNowCheckout($buyer, $product, $auction);

    // A count of 150 and an amount of 15,000 pesewas. Different numbers,
    // different columns, and neither is the other.
    expect($order->discount_credits)->toBe(150)
        ->and($order->discount_minor)->toBe(15_000)
        ->and($order->discount_credits)->not->toBe($order->discount_minor);
});

it('stores every order amount as an integer', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product);

    foreach (['subtotal_minor', 'discount_minor', 'delivery_minor', 'tax_minor', 'total_minor'] as $column) {
        expect($order->{$column})->toBeInt();
    }
});

// ------------------------------------------- No credit-to-GHS conversion

it('never charges a customer for credits they already consumed', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $buyer = bidder(1_000);

    placeBid($auction, $buyer, 150);
    $before = creditWalletFor($buyer)->fresh()->balance;

    payOrder(buyNowCheckout($buyer, $product, $auction));

    // Not one credit moved by paying in cedis.
    expect(creditWalletFor($buyer)->fresh()->balance)->toBe($before)->toBe(850);
});

it('never refunds bid credits, at checkout or at payment', function (): void {
    $auction = liveAuction();
    $winner = bidder(1_000);
    $loser = bidder(1_000);

    placeBid($auction, $loser, 100);
    placeBid($auction, $winner, 400);
    $this->close->handle($auction, force: true);

    payOrder(settlementCheckout($auction->fresh(), $winner));

    expect(CreditTransaction::whereIn('type', [
        CreditTransactionType::Refund,
        CreditTransactionType::Reversal,
    ])->count())->toBe(0)
        ->and(creditWalletFor($loser)->fresh()->balance)->toBe(900)
        ->and(creditWalletFor($winner)->fresh()->balance)->toBe(600);
});

it('never posts a credit transaction from any checkout or payment path', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $buyer = bidder(1_000);

    placeBid($auction, $buyer, 150);
    $before = CreditTransaction::count();

    payOrder(buyNowCheckout($buyer, $product, $auction));

    // The only credit transaction in this test is the bid, made before any
    // of this began.
    expect(CreditTransaction::count())->toBe($before);
});

// --------------------------------------- Settlement is not the Buy Now price

it('never charges the Buy Now price as a settlement', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product, settlementMinor: 10_000);
    $winner = bidder(500);

    placeBid($auction, $winner, 180);
    $this->close->handle($auction, force: true);

    $order = settlementCheckout($auction->fresh(), $winner);

    expect($order->total_minor)->toBe(10_000)
        ->and($order->total_minor)->not->toBe($product->buy_now_price_minor)
        // And not a percentage of it either.
        ->and($order->total_minor)->not->toBe((int) ($product->buy_now_price_minor * 0.1));
});

it('never charges a winning bid converted into cedis', function (int $credits): void {
    $auction = liveAuction(settlementMinor: 10_000);
    $winner = bidder(2_000);

    placeBid($auction, $winner, $credits);
    $this->close->handle($auction, force: true);

    $order = settlementCheckout($auction->fresh(), $winner);

    // Whatever the bid was, the settlement is unchanged.
    expect($order->total_minor)->toBe(10_000)
        ->and($order->total_minor)->not->toBe($credits * 100);
})->with([50, 180, 900, 1_500]);

it('charges two auctions on one product their own settlement amounts', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 2);

    $totals = [];

    foreach ([5_000, 15_000] as $settlement) {
        $auction = app(CreateAuction::class)->handle(
            $product->fresh(),
            AuctionRuleset::factory()->active()->withoutThrottle()->create(),
            Money::fromMinor($settlement),
        );

        app(AuctionLifecycle::class)->start($auction);

        $winner = bidder(500);
        placeBid($auction->fresh(), $winner, 100);
        $this->close->handle($auction->fresh(), force: true);

        $totals[] = settlementCheckout($auction->fresh(), $winner)->total_minor;
    }

    expect($totals)->toBe([5_000, 15_000]);
});

// ------------------------------------- Payment authority stays server-side

it('cannot be made to charge an amount the browser chose', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product);
    $payment = initializePayment($order);

    // The only figure the provider was ever asked for is the frozen total.
    // There is no code path that accepts an amount from outside.
    expect($payment->amount_minor)->toBe($order->total_minor)
        ->and((new ReflectionMethod(InitializeOrderPayment::class, 'handle'))
            ->getNumberOfParameters())->toBe(1);
});

it('cannot be fulfilled by a browser saying the payment succeeded', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product);
    $payment = initializePayment($order);

    // The customer returns claiming success. Paystack says otherwise, and
    // Paystack is asked.
    fakePaystackVerify([
        'reference' => $payment->provider_reference,
        'status' => 'abandoned',
        'amount' => $payment->amount_minor,
        'currency' => 'GHS',
    ]);

    $this->actingAs($order->user)
        ->get(route('checkout.callback', [
            'reference' => $payment->provider_reference,
            // A hopeful query string, ignored entirely.
            'status' => 'success',
            'trxref' => $payment->provider_reference,
        ]))
        ->assertOk()
        ->assertDontSee('Payment confirmed');

    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(0);
});

// ------------------------------------------------- One sale, one fulfilment

it('never sells one unit twice, whichever path completes', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $bidder = bidder(1_000);

    placeBid($auction, $bidder, 200);

    payOrder(buyNowCheckout(bidder(), $product->fresh(), $auction->fresh()));

    // A closing sweep afterwards has nothing to do.
    $this->close->handle($auction->fresh(), force: true);

    expect(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1)
        ->and($product->fresh()->stock_on_hand)->toBe(0)
        ->and($product->fresh()->stock_reserved)->toBe(0);
});

it('never fulfils one order twice', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product);
    $payment = initializePayment($order);

    foreach (range(1, 5) as $ignored) {
        payOrder($order, $payment);
    }

    expect(Order::findOrFail($order->id)->transitions()
        ->where('to_status', OrderStatus::Paid)->count())->toBe(1)
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1);
});

// ----------------------------------- Buy Now does not end an auction early

it('never ends an auction before a payment is verified', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $buyer = bidder();

    // Opening a checkout.
    $order = buyNowCheckout($buyer, $product, $auction);
    expect($auction->fresh()->status)->toBe(AuctionStatus::Live);

    // Initializing a payment.
    $payment = initializePayment($order);
    expect($auction->fresh()->status)->toBe(AuctionStatus::Live);

    // A payment the provider has not confirmed.
    fakePaystackVerify([
        'reference' => $payment->provider_reference,
        'status' => 'pending',
        'amount' => $payment->amount_minor,
        'currency' => 'GHS',
    ]);

    try {
        app(FulfillOrderPayment::class)->handle($payment->fresh());
    } catch (PaymentNotAcceptable) {
        // Expected.
    }

    expect($auction->fresh()->status)->toBe(AuctionStatus::Live)
        ->and($auction->fresh()->buy_now_user_id)->toBeNull();

    // Only a verified payment does it.
    payOrder($order, $payment);

    expect($auction->fresh()->status)->toBe(AuctionStatus::Settled)
        ->and($auction->fresh()->closure_reason)->toBe(AuctionClosureReason::BuyNow);
});

it('never lets a cancelled checkout end an auction', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $buyer = bidder();

    $order = buyNowCheckout($buyer, $product, $auction);
    app(OrderLifecycle::class)->cancel($order, 'Changed my mind.');

    expect($auction->fresh()->status)->toBe(AuctionStatus::Live)
        ->and($auction->fresh()->closure_reason)->toBeNull()
        // And bidding carries on.
        ->and(placeBid($auction->fresh(), bidder(), 100)->amount_credits)->toBe(100);
});

// -------------------------------------- Buy Now stays distinct from a win

it('keeps a Buy Now ending distinguishable from a settlement', function (): void {
    $boughtProduct = Product::factory()->active()->pricedAt(550_000)->create();
    $boughtAuction = liveAuction(product: $boughtProduct);
    payOrder(buyNowCheckout(bidder(), $boughtProduct, $boughtAuction));

    $wonAuction = liveAuction(settlementMinor: 10_000);
    $winner = bidder(500);
    placeBid($wonAuction, $winner, 100);
    $this->close->handle($wonAuction, force: true);
    payOrder(settlementCheckout($wonAuction->fresh(), $winner));

    $bought = $boughtAuction->fresh();
    $won = $wonAuction->fresh();

    // Both Settled, and told apart without inference.
    expect($bought->status)->toBe(AuctionStatus::Settled)
        ->and($won->status)->toBe(AuctionStatus::Settled)
        ->and($bought->closure_reason)->toBe(AuctionClosureReason::BuyNow)
        ->and($won->closure_reason)->toBe(AuctionClosureReason::HighestBid)
        ->and($bought->winner_user_id)->toBeNull()
        ->and($won->buy_now_user_id)->toBeNull();
});

// ------------------------------------------- Earlier stages still intact

it('leaves the credit ledger rules untouched', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $buyer = bidder(500);
    payOrder(buyNowCheckout($buyer, $product));

    $wallet = creditWalletFor($buyer)->fresh();

    $wallet->balance = 9_999;
    expect(fn (): bool => $wallet->save())
        ->toThrow(BalanceMutationForbidden::class);
});

it('leaves the inventory rules untouched', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    buyNowCheckout(bidder(), $product);

    $fresh = $product->fresh();
    $fresh->stock_on_hand = 99;

    expect(fn (): bool => $fresh->save())
        ->toThrow(StockMutationForbidden::class);
});

it('leaves the auction snapshot rules untouched', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    payOrder(buyNowCheckout(bidder(), $product, $auction));

    expect($auction->fresh()->rules_snapshot['rules']['winner_rule'])
        ->toBe('highest_valid_credit_bid');
});

it('leaves bids append-only', function (): void {
    $auction = liveAuction();
    $bid = placeBid($auction, bidder(500), 100);

    expect(fn () => DB::table('bids')->where('id', $bid->id)->update(['amount_credits' => 1]))
        ->toThrow(QueryException::class, 'append-only');
});
