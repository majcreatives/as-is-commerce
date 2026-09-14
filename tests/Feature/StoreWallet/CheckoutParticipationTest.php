<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Orders\Actions\StartBuyNowCheckout;
use App\Domain\Orders\Exceptions\InvalidCheckout;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Domain\Refunds\Actions\ProcessRefund;
use App\Domain\StoreWallet\Services\StoreWalletCheckout;
use App\Domain\StoreWallet\Services\StoreWalletLedgerService;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Enums\StoreWalletTransactionType;
use App\Models\Auction;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreWalletTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * How Store Wallet value behaves at a real catalogue checkout.
 *
 * The issuance side -- who is compensated, by how much, when -- is proven in
 * CreditCashValueTest. This file proves the *spend* side, end to end through
 * StartBuyNowCheckout and OrderLifecycle: value is committed at checkout,
 * frozen on the order with a provider-chargeable remainder, released again
 * when the checkout closes unpaid, and never applied on any path but a
 * fixed-price catalogue purchase.
 */

/**
 * A closed auction with a winner whose settlement checkout is waiting.
 *
 * @return array{Auction, Order}
 */
function settledOrder(): array
{
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $winner = bidder(1_000);

    placeBid($auction, $winner, 200);

    $closed = app(CloseAuction::class)->handle($auction, force: true);

    return [$closed, $closed->settlementOrder];
}

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    $this->wallets = app(StoreWalletLedgerService::class);
    $this->orders = app(OrderLifecycle::class);
    $this->checkout = app(StoreWalletCheckout::class);
});

// ------------------------------------------------------- Applying the value

it('applies the full balance to a catalogue checkout and freezes the remainder', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);

    $buyer = userWithRole('customer');
    fundStoreWallet($buyer, 300);

    $order = buyNowCheckout($buyer, $product);

    expect($order->store_wallet_applied_minor)->toBe(300)
        // The provider is asked only for what the wallet did not cover.
        ->and($order->payable_minor)->toBe(549_700)
        ->and($order->total_minor)->toBe(550_000)
        ->and($order->payable_minor + $order->store_wallet_applied_minor)->toBe($order->total_minor)
        // The value left the wallet at checkout, not at payment.
        ->and(storeWalletBalance($buyer)->minor)->toBe(0);

    // The debit is a real ledger row, pointed at the order it was committed to.
    $applied = StoreWalletTransaction::where('idempotency_key', StoreWalletCheckout::appliedKeyFor($order->id))
        ->firstOrFail();

    expect($applied->type)->toBe(StoreWalletTransactionType::OrderApplied)
        ->and($applied->amount_minor)->toBe(-300)
        ->and($applied->reference_type)->toBe(Order::class)
        ->and($applied->reference_id)->toBe($order->id)
        ->and($applied->balance_after_minor)->toBe(0);
});

it('applies only as much as the wallet holds', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);

    $buyer = userWithRole('customer');
    fundStoreWallet($buyer, 1_000);

    $order = buyNowCheckout($buyer, $product);

    expect($order->store_wallet_applied_minor)->toBe(1_000)
        ->and($order->payable_minor)->toBe(549_000);
});

it('refuses a checkout the wallet would cover in full', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);

    $buyer = userWithRole('customer');
    fundStoreWallet($buyer, 600_000);

    expect(fn (): Order => buyNowCheckout($buyer, $product))
        ->toThrow(InvalidCheckout::class, 'cannot cover an order in full')
        // Nothing was committed: the refusal happens before any debit.
        ->and(storeWalletBalance($buyer)->minor)->toBe(600_000);
});

it('applies nothing when there is no balance', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(userWithRole('customer'), $product);

    expect($order->store_wallet_applied_minor)->toBe(0)
        ->and($order->payable_minor)->toBe($order->total_minor)
        ->and(StoreWalletTransaction::count())->toBe(0);
});

// ---------------------------------------------- Releasing on a closed layaway

it('returns the value when the checkout is cancelled, once', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);

    $buyer = userWithRole('customer');
    fundStoreWallet($buyer, 300);

    $order = buyNowCheckout($buyer, $product);

    $this->orders->cancel($order->fresh(), 'Changed my mind.');

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(storeWalletBalance($buyer)->minor)->toBe(300)
        ->and(StoreWalletTransaction::where('idempotency_key', StoreWalletCheckout::releasedKeyFor($order->id))->count())
        ->toBe(1);

    // A duplicated release of the same order returns the value exactly once.
    $this->checkout->release($order->fresh());

    expect(storeWalletBalance($buyer)->minor)->toBe(300)
        ->and(StoreWalletTransaction::where('idempotency_key', StoreWalletCheckout::releasedKeyFor($order->id))->count())
        ->toBe(1);
});

it('returns the value when the checkout expires, once', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);

    $buyer = userWithRole('customer');
    fundStoreWallet($buyer, 300);

    $order = buyNowCheckout($buyer, $product);

    $this->travel(91)->minutes();
    $this->artisan('orders:expire-checkouts')->assertSuccessful();
    $this->artisan('orders:expire-checkouts')->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::PaymentExpired)
        ->and(storeWalletBalance($buyer)->minor)->toBe(300)
        ->and(StoreWalletTransaction::where('idempotency_key', StoreWalletCheckout::releasedKeyFor($order->id))->count())
        ->toBe(1);
});

it('returns the value when the payment fails', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);

    $buyer = userWithRole('customer');
    fundStoreWallet($buyer, 300);

    $order = buyNowCheckout($buyer, $product);

    $this->orders->markPaymentFailed($order->fresh(), 'The provider declined.');

    expect($order->fresh()->status)->toBe(OrderStatus::PaymentFailed)
        ->and(storeWalletBalance($buyer)->minor)->toBe(300);
});

// ------------------------------------------------- The value spent for good

it('keeps the value applied when the payment succeeds', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);

    $buyer = userWithRole('customer');
    fundStoreWallet($buyer, 300);

    $order = buyNowCheckout($buyer, $product);

    payOrder($order);

    expect($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->fresh()->store_wallet_applied_minor)->toBe(300)
        // The value paid for a real purchase; it does not come back.
        ->and(storeWalletBalance($buyer)->minor)->toBe(0)
        // A paid order is released by nobody.
        ->and($this->checkout->release($order->fresh()))->toBeNull()
        ->and(storeWalletBalance($buyer)->minor)->toBe(0);
});

/*
 * A refund is commercial fact as surely as a paid order: money was taken, the
 * platform failed to deliver against it, and the Refund workflow returned it.
 * An order that closed as expired keeps the status it closed with -- the
 * refund record is what says the money went back -- and the committed Store
 * Wallet value returned exactly once, at the moment the checkout closed. No
 * later step, refund included, may give that value back again.
 */
it('never releases a checkout once it has been refunded', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);

    $buyer = userWithRole('customer');
    fundStoreWallet($buyer, 300);

    $order = buyNowCheckout($buyer, $product);
    $payment = initializePayment($order);

    // The checkout expires: the committed value returns once, at close.
    $this->travel(2)->hours();
    $this->orders->expire($order->fresh());

    expect($order->fresh()->status)->toBe(OrderStatus::PaymentExpired)
        ->and(storeWalletBalance($buyer)->minor)->toBe(300);

    // The payment lands late, so this expired checkout becomes a blocked paid
    // order -- refundable, and worth refunding in full.
    payOrder($order->fresh(), $payment->fresh());
    expect($order->fresh()->isFulfilmentBlocked())->toBeTrue();

    $refund = requestRefund($order->fresh());
    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'processed', 'RF-200'));
    app(ProcessRefund::class)->handle($refund, userWithRole('admin'));

    // The money went back, but the order keeps the status that explains why it
    // closed in the first place -- exactly what the refund lifecycle says.
    expect($order->fresh()->status)->toBe(OrderStatus::PaymentExpired)
        ->and($refund->fresh()->status)->toBe(RefundStatus::Succeeded)
        // Emphatically not a second payout of the Store Wallet value: the one
        // release that may happen did happen at expiry, and is the only one.
        ->and($this->checkout->release($order->fresh()))->toBeNull()
        ->and(storeWalletBalance($buyer)->minor)->toBe(300)
        ->and(StoreWalletTransaction::where('idempotency_key', StoreWalletCheckout::releasedKeyFor($order->id))->count())
        ->toBe(1);
});

// --------------------------------------------- Everywhere it must never apply

it('never applies Store Wallet value to a settlement', function (): void {
    [, $order] = settledOrder();

    expect($order->fresh()->store_wallet_applied_minor)->toBe(0)
        ->and($order->fresh()->payable_minor)->toBe($order->fresh()->total_minor);
});

it('never applies Store Wallet value to an auction Buy Now', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    $buyer = userWithRole('customer');
    fundStoreWallet($buyer, 300);

    $order = buyNowCheckout($buyer, $product, $auction);

    expect($order->store_wallet_applied_minor)->toBe(0)
        ->and($order->payable_minor)->toBe($order->total_minor)
        ->and(storeWalletBalance($buyer)->minor)->toBe(300);
});

it('lets the database refuse Store Wallet value on an auction-linked order', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    $order = buyNowCheckout(bidder(), $product, $auction);

    expect(fn () => DB::table('orders')->where('id', $order->id)
        ->update(['store_wallet_applied_minor' => 100]))
        ->toThrow(QueryException::class);
});

it('lets the database refuse Store Wallet value on a settlement order', function (): void {
    [, $order] = settledOrder();

    expect(fn () => DB::table('orders')->where('id', $order->id)
        ->update(['store_wallet_applied_minor' => 100]))
        ->toThrow(QueryException::class);
});

it('does not commit a release for an order that never applied value', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(userWithRole('customer'), $product);

    expect($this->checkout->release($order->fresh()))->toBeNull()
        ->and(StoreWalletTransaction::count())->toBe(0);
});

// ---------------------------------------------- What the checkout page says

it('tells the customer what the Store Wallet covers on the bill', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);

    $buyer = userWithRole('customer');
    fundStoreWallet($buyer, 300);

    $order = buyNowCheckout($buyer, $product);

    $this->actingAs($buyer)
        ->get(route('checkout.show', $order))
        ->assertOk()
        ->assertSee('Your Store Wallet covers');
});

it('says nothing about the Store Wallet when none was applied', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(userWithRole('customer'), $product);

    $this->actingAs($order->user)
        ->get(route('checkout.show', $order))
        ->assertOk()
        ->assertDontSee('Your Store Wallet covers');
});

// -------------------------------------------- One balance, several checkouts

it('never spends the same value twice across checkouts', function (): void {
    $first = Product::factory()->active()->pricedAt(550_000)->create();
    $second = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($first, 1);
    app(InventoryService::class)->initialStock($second, 1);

    $buyer = userWithRole('customer');
    fundStoreWallet($buyer, 300);

    $firstOrder = buyNowCheckout($buyer, $first);
    $secondOrder = buyNowCheckout($buyer, $second);

    // The first checkout takes the whole balance. The second, reading the
    // balance the first left behind, applies nothing and owes its full total.
    expect($firstOrder->store_wallet_applied_minor)->toBe(300)
        ->and($firstOrder->payable_minor)->toBe(549_700)
        ->and($secondOrder->store_wallet_applied_minor)->toBe(0)
        ->and($secondOrder->payable_minor)->toBe(550_000)
        // What the two orders are owed never exceeds what the wallet held.
        ->and($firstOrder->payable_minor + $secondOrder->payable_minor)->toBe(1_099_700)
        ->and(storeWalletBalance($buyer)->minor)->toBe(0)
        ->and($this->wallets->verify($this->wallets->walletFor($buyer)))
        ->toMatchArray(['matches' => true, 'projected_minor' => 0, 'ledger_minor' => 0]);
});
