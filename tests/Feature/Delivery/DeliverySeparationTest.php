<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Delivery\Exceptions\DeliveryNotAllowed;
use App\Domain\Delivery\Services\DeliveryLifecycle;
use App\Domain\Notifications\Services\NotificationDispatcher;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Domain\Refunds\Actions\ProcessRefund;
use App\Enums\AuctionStatus;
use App\Enums\DeliveryFailureReason;
use App\Enums\DeliveryStatus;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\Delivery;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * The lines a delivery must never cross.
 *
 * Moving a box is not a statement about money. Every test here breaks a
 * package in some way and then checks that the payment, the credit ledger, the
 * inventory and the auction are all exactly where they were.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);

    $this->staff = userWithRole('admin');
    $this->deliveries = app(DeliveryLifecycle::class);
});

// ------------------------------------------------------------- Payment

/*
 * Structural, and the point of it: there is nothing to call. A member of staff
 * moving a package has no route to the one thing that decides an order is
 * paid.
 */
it('has no way for a delivery to mark an order paid', function (): void {
    expect(method_exists(DeliveryLifecycle::class, 'markPaid'))->toBeFalse()
        ->and(method_exists(DeliveryLifecycle::class, 'confirmPayment'))->toBeFalse()
        ->and(method_exists(Delivery::class, 'markOrderPaid'))->toBeFalse()
        // And the order lifecycle still refuses Paid as a target for anybody.
        ->and(fn () => app(OrderLifecycle::class)->advance(
            Order::factory()->create(), OrderStatus::Paid,
        ))->toThrow(DomainException::class);
});

it('creates and alters no payment when a package moves', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Pending);
    $order = $delivery->order;

    $payment = $order->payments()->successful()->first();
    $before = [
        'amount' => $payment->amount_minor,
        'status' => $payment->status,
        'reference' => $payment->provider_reference,
        'count' => $order->payments()->count(),
    ];

    deliveryAt(DeliveryStatus::Delivered, $this->staff, $order);

    $after = $payment->fresh();

    expect($after->amount_minor)->toBe($before['amount'])
        ->and($after->status)->toBe($before['status'])
        ->and($after->provider_reference)->toBe($before['reference'])
        ->and($order->fresh()->payments()->count())->toBe($before['count']);
});

// -------------------------------------------------------------- Credits

/*
 * The rule the whole platform is arranged around. A customer who bid 170
 * credits and had their package fail keeps none of them, and gains none back.
 */
it('touches no credit ledger row at any point in a delivery', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    $buyer = bidder(1_000);
    placeBid($auction, $buyer, 20);
    placeBid($auction->fresh(), $buyer, 50);
    placeBid($auction->fresh(), $buyer, 100);

    Address::factory()->ownedBy($buyer)->isDefault()->create();

    $balance = creditWalletFor($buyer)->fresh()->balance;
    expect($balance)->toBe(830);

    payOrder(buyNowCheckout($buyer->fresh(), $product->fresh(), $auction->fresh()));

    $order = Order::query()->where('user_id', $buyer->id)->latest('id')->first();
    $ledgerRows = DB::table('credit_transactions')->count();
    $lots = DB::table('credit_lots')->get()->toArray();

    // The whole journey, including a failure and a retry.
    $delivery = $order->delivery;
    $this->deliveries->prepare($delivery, $this->staff);
    $this->deliveries->markReady($delivery->fresh(), $this->staff);
    $this->deliveries->dispatch($delivery->fresh(), $this->staff);
    $this->deliveries->markFailed($delivery->fresh(), DeliveryFailureReason::RecipientRefused, $this->staff);
    $this->deliveries->retry($delivery->fresh(), DeliveryStatus::ReadyForDispatch, $this->staff);
    $this->deliveries->dispatch($delivery->fresh(), $this->staff);
    $this->deliveries->markDelivered($delivery->fresh(), $this->staff);

    expect(creditWalletFor($buyer)->fresh()->balance)->toBe($balance)
        ->and(DB::table('credit_transactions')->count())->toBe($ledgerRows)
        ->and(DB::table('credit_lots')->get()->toArray())->toEqual($lots)
        ->and(DB::table('credit_lot_consumptions')->count())->toBeGreaterThan(0);
});

it('has no mechanism that could return a credit', function (): void {
    expect(method_exists(DeliveryLifecycle::class, 'restoreCredits'))->toBeFalse()
        ->and(method_exists(DeliveryLifecycle::class, 'refund'))->toBeFalse()
        // The deliveries table cannot even express a credit or an amount.
        ->and(Schema::hasColumn('deliveries', 'credits'))->toBeFalse()
        ->and(Schema::hasColumn('deliveries', 'amount_minor'))->toBeFalse()
        ->and(Schema::hasColumn('deliveries', 'delivery_fee_minor'))->toBeFalse();
});

// ------------------------------------------------------------ Inventory

/*
 * The unit was sold when the payment was verified. Moving the box it is in
 * does not sell it again, and a package that comes back does not put it on the
 * shelf -- that is a physical return, which this platform does not do.
 */
it('posts no inventory movement anywhere in a delivery', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Pending);
    $order = $delivery->order;

    $movements = DB::table('inventory_transactions')->count();
    $products = DB::table('products')->select('id', 'stock_on_hand', 'stock_reserved')->get()->toArray();

    $this->deliveries->prepare($delivery, $this->staff);
    $this->deliveries->markReady($delivery->fresh(), $this->staff);
    $this->deliveries->dispatch($delivery->fresh(), $this->staff);
    $this->deliveries->markFailed($delivery->fresh(), DeliveryFailureReason::IncorrectAddress, $this->staff);
    $this->deliveries->retry($delivery->fresh(), DeliveryStatus::Preparing, $this->staff);

    expect(DB::table('inventory_transactions')->count())->toBe($movements)
        ->and(DB::table('products')->select('id', 'stock_on_hand', 'stock_reserved')->get()->toArray())
        ->toEqual($products);

    // And nor does the delivery finally arriving.
    $this->deliveries->markReady($delivery->fresh(), $this->staff);
    $this->deliveries->dispatch($delivery->fresh(), $this->staff);
    $this->deliveries->markDelivered($delivery->fresh(), $this->staff);

    expect(DB::table('inventory_transactions')->count())->toBe($movements)
        ->and($order->fresh()->status)->toBe(OrderStatus::Fulfilled);
});

it('restores no stock when a delivery is cancelled', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Preparing);

    $movements = DB::table('inventory_transactions')->count();
    $products = DB::table('products')->select('id', 'stock_on_hand', 'stock_reserved')->get()->toArray();

    $this->deliveries->cancel($delivery, $this->staff, 'Stopping this one.');

    expect(DB::table('inventory_transactions')->count())->toBe($movements)
        ->and(DB::table('products')->select('id', 'stock_on_hand', 'stock_reserved')->get()->toArray())
        ->toEqual($products);
});

// -------------------------------------------------------------- Refunds

it('refunds nothing when a delivery fails', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Dispatched);

    $this->deliveries->markFailed($delivery, DeliveryFailureReason::RecipientUnavailable, $this->staff);

    expect(Refund::count())->toBe(0)
        // The customer's money is still the platform's, and what is owed is
        // still somebody's decision.
        ->and($delivery->order->fresh()->status)->toBe(OrderStatus::Processing);
});

it('refunds nothing when a delivery is cancelled', function (): void {
    $delivery = deliveryAt(DeliveryStatus::ReadyForDispatch);

    $this->deliveries->cancel($delivery, $this->staff);

    expect(Refund::count())->toBe(0);
});

it('has no refund mechanism of its own', function (): void {
    expect(method_exists(DeliveryLifecycle::class, 'refundOrder'))->toBeFalse()
        ->and(class_exists('App\\Domain\\Delivery\\Actions\\RefundDelivery'))->toBeFalse();

    // And the delivery domain does not reach the provider at all.
    $source = file_get_contents(app_path('Domain/Delivery/Services/DeliveryLifecycle.php'));

    expect($source)->not->toContain('Paystack')
        ->not->toContain('PaymentGateway');
});

/*
 * A refund that is approved goes through the refund workflow, and it stops the
 * package rather than the package stopping it.
 */
it('will not send a package on an order that has been refunded', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Preparing);
    $order = $delivery->order;

    // The order is refunded while the box sits in the warehouse. Blocked
    // first, since that is the only state a refund is issued against.
    app(OrderLifecycle::class)->blockFulfilment($order, 'Cannot complete.');

    $refund = requestRefund($order->fresh(), actor: $this->staff);
    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'processed', 'RF-DEL'));
    app(ProcessRefund::class)->handle($refund, $this->staff);

    expect($order->fresh()->status)->toBe(OrderStatus::Refunded);

    // The package must not go out: the customer has their money back.
    expect(fn (): Delivery => $this->deliveries->markReady($delivery->fresh(), $this->staff))
        ->toThrow(DeliveryNotAllowed::class);

    // Cancelling it is still allowed, because that is how an operator responds.
    $this->deliveries->cancel($delivery->fresh(), $this->staff);

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Cancelled);
});

// -------------------------------------------------------------- Auctions

it('changes no auction result when a delivery fails', function (): void {
    $product = Product::factory()->active()->create();
    $auction = liveAuction(product: $product);
    $winner = bidder(500);
    placeBid($auction, $winner, 200);

    Address::factory()->ownedBy($winner)->isDefault()->create();

    $order = app(CloseAuction::class)
        ->handle($auction, force: true)->settlementOrder;

    payOrder($order->fresh());

    $settled = $auction->fresh();

    expect($settled->status)->toBe(AuctionStatus::Settled)
        ->and($settled->winner_user_id)->toBe($winner->id);

    $delivery = $order->fresh()->delivery;
    $this->deliveries->prepare($delivery, $this->staff);
    $this->deliveries->markReady($delivery->fresh(), $this->staff);
    $this->deliveries->dispatch($delivery->fresh(), $this->staff);
    $this->deliveries->markFailed($delivery->fresh(), DeliveryFailureReason::RecipientRefused, $this->staff);

    $after = $auction->fresh();

    expect($after->status)->toBe(AuctionStatus::Settled)
        ->and($after->winner_user_id)->toBe($winner->id)
        ->and($after->winning_bid_id)->toBe($settled->winning_bid_id)
        // And the winner's credits are still consumed.
        ->and(creditWalletFor($winner)->fresh()->balance)->toBe(300);
});

// --------------------------------------------------------- Notifications

it('tells a customer only what has actually happened', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Pending);
    $customer = $delivery->order->user;

    $this->deliveries->prepare($delivery, $this->staff);

    expect(notificationsFor($customer, NotificationType::DeliveryPreparing))->toHaveCount(1)
        // Nothing about dispatch or delivery yet, because neither has happened.
        ->and(notificationsFor($customer, NotificationType::DeliveryDispatched))->toHaveCount(0)
        ->and(notificationsFor($customer, NotificationType::DeliveryDelivered))->toHaveCount(0);

    $this->deliveries->markReady($delivery->fresh(), $this->staff);
    $this->deliveries->dispatch($delivery->fresh(), $this->staff);

    expect(notificationsFor($customer, NotificationType::DeliveryDispatched))->toHaveCount(1)
        ->and(notificationsFor($customer, NotificationType::DeliveryDelivered))->toHaveCount(0);

    $this->deliveries->markDelivered($delivery->fresh(), $this->staff);

    expect(notificationsFor($customer, NotificationType::DeliveryDelivered))->toHaveCount(1);
});

it('sends one notification however many times a button is pressed', function (): void {
    $delivery = deliveryAt(DeliveryStatus::OutForDelivery);
    $customer = $delivery->order->user;

    foreach (range(1, 4) as $ignored) {
        $this->deliveries->markDelivered($delivery->fresh(), $this->staff);
    }

    expect(notificationsFor($customer, NotificationType::DeliveryDelivered))->toHaveCount(1);
});

it('never accuses a customer when a delivery fails', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Dispatched);

    $this->deliveries->markFailed($delivery, DeliveryFailureReason::RecipientRefused, $this->staff, 'Told us to go away.');

    $message = notificationsFor($delivery->order->user, NotificationType::DeliveryFailed)->first()->message;

    expect($message)->toContain('not accepted')
        // The rider's own words stay on the staff screen.
        ->and($message)->not->toContain('go away')
        // And no refund is offered, because that is not this decision.
        ->and($message)->not->toContain('refund');
});

/*
 * The Stage 9 guarantee, extended to packages. A broken notification layer
 * cannot lose a rider's report of a completed handover.
 */
it('delivers successfully even when every notification throws', function (): void {
    $delivery = deliveryAt(DeliveryStatus::OutForDelivery);
    $order = $delivery->order;

    app()->bind(NotificationDispatcher::class, fn (): NotificationDispatcher => new class extends NotificationDispatcher
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
    });

    $this->deliveries->markDelivered($delivery, $this->staff, receivedBy: 'Ama');

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Delivered)
        ->and($delivery->fresh()->received_by)->toBe('Ama')
        ->and($order->fresh()->status)->toBe(OrderStatus::Fulfilled)
        // No delivery notification was written for the handover, which is the
        // honest outcome -- the earlier steps' messages predate the broken
        // dispatcher.
        ->and(Notification::query()->where('event_type', 'delivery.delivered')->count())->toBe(0);
});

it('writes no notification for a move that was refused', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Pending);
    $customer = $delivery->order->user;

    expect(fn (): Delivery => $this->deliveries->markDelivered($delivery, $this->staff))
        ->toThrow(DeliveryNotAllowed::class);

    expect(notificationsFor($customer, NotificationType::DeliveryDelivered))->toHaveCount(0);
});
