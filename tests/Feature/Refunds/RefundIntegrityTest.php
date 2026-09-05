<?php

declare(strict_types=1);

use App\Domain\Notifications\Services\NotificationDispatcher;
use App\Domain\Refunds\Actions\ProcessRefund;
use App\Domain\Refunds\Actions\VerifyRefund;
use App\Domain\Refunds\Services\RefundLifecycle;
use App\Domain\Shared\Money\Money;
use App\Enums\CreditTransactionType;
use App\Enums\InventoryTransactionType;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Models\CreditTransaction;
use App\Models\InventoryTransaction;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
 * The lines a refund must never cross.
 *
 * A refund returns cedis. It does not return credits, it does not put stock
 * back, it does not rewrite the payment it refunds, and it does not reopen an
 * order that closed. Each of those is asserted here rather than left to the
 * comments that claim it.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);

    $this->admin = userWithRole('admin');
});

/**
 * Take a blocked order all the way through a confirmed refund.
 */
function refundFully(Order $order, User $admin, string $reference = 'RF-INT'): Refund
{
    // The same person throughout, so the audit assertions have one causer to
    // check rather than two administrators the helper invented.
    $refund = requestRefund($order, actor: $admin);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'processed', $reference));

    return app(ProcessRefund::class)->handle($refund, $admin);
}

// ------------------------------------------------------------- Credits

/*
 * The most important rule in the application, and the one a refund is most
 * likely to be expected to break. A customer who bid 170 credits and then
 * bought outright gets their cedis back and keeps none of the credits.
 */
it('restores no bid credits when a payment is refunded', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    $buyer = bidder(1_000);
    placeBid($auction, $buyer, 20);
    placeBid($auction->fresh(), $buyer, 50);
    placeBid($auction->fresh(), $buyer, 100);

    $balanceAfterBidding = creditWalletFor($buyer)->fresh()->balance;

    expect($balanceAfterBidding)->toBe(830);

    // This buyer's Buy Now loses the race for the unit and is blocked.
    $order = buyNowCheckout($buyer->fresh(), $product->fresh(), $auction->fresh());
    payOrder(buyNowCheckout(bidder(), $product->fresh(), $auction->fresh()));
    payOrder($order->fresh());

    expect($order->fresh()->isFulfilmentBlocked())->toBeTrue();

    // Measured here rather than earlier: setting up the buyer who wins the
    // race grants credits of their own, and those rows are not the refund's
    // doing.
    $ledgerRows = CreditTransaction::count();

    refundFully($order->fresh(), $this->admin);

    expect(creditWalletFor($buyer)->fresh()->balance)->toBe($balanceAfterBidding)
        // Not one new ledger row, in either direction.
        ->and(CreditTransaction::count())->toBe($ledgerRows)
        ->and(CreditTransaction::whereIn('type', [
            CreditTransactionType::Refund,
            CreditTransactionType::Reversal,
        ])->count())->toBe(0);
});

it('touches no credit ledger row at any stage of a refund', function (string $outcome): void {
    $order = blockedPaidOrder();

    $before = CreditTransaction::count();
    $walletTotals = DB::table('credit_wallets')->sum('balance');

    $refund = requestRefund($order);

    // Requested: nothing yet.
    expect(CreditTransaction::count())->toBe($before);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, $outcome, 'RF-LEDGER'));
    app(ProcessRefund::class)->handle($refund, $this->admin);

    expect(CreditTransaction::count())->toBe($before)
        ->and(DB::table('credit_wallets')->sum('balance'))->toEqual($walletTotals)
        // And no cash ledger movement either: a refund is a provider
        // operation, not a platform wallet posting.
        ->and(DB::table('cash_transactions')->count())->toBe(0);
})->with(['processed', 'pending', 'failed']);

it('has no mechanism that could return a credit', function (): void {
    // Structural, and the point of it: there is nothing to call. A refund
    // moves cedis through the provider and has no route into the ledger.
    expect(method_exists(ProcessRefund::class, 'refundCredits'))->toBeFalse()
        ->and(method_exists(RefundLifecycle::class, 'restoreCredits'))->toBeFalse()
        ->and(class_exists('App\\Domain\\Refunds\\Actions\\RefundBidCredits'))->toBeFalse()
        // The refunds table cannot even express a credit.
        ->and(Schema::hasColumn('refunds', 'credits'))->toBeFalse()
        ->and(Schema::hasColumn('refunds', 'credit_amount'))->toBeFalse();
});

// ----------------------------------------------------------- Inventory

/*
 * A refund is a movement of money. Whether an item is back on the shelf is a
 * physical question, and reverse logistics do not exist here.
 */
it('puts no stock back when a payment is refunded', function (): void {
    $order = blockedPaidOrder();

    $movements = InventoryTransaction::count();
    $sales = InventoryTransaction::where('type', InventoryTransactionType::Sale)->count();
    $products = DB::table('products')->select('id', 'stock_on_hand', 'stock_reserved')->get()->toArray();

    refundFully($order, $this->admin);

    expect(InventoryTransaction::count())->toBe($movements)
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe($sales)
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Release)->count())->toBe(0)
        ->and(DB::table('products')->select('id', 'stock_on_hand', 'stock_reserved')->get()->toArray())
        ->toEqual($products);
});

it('creates no inventory movement for a failed refund either', function (): void {
    $order = blockedPaidOrder();
    $movements = InventoryTransaction::count();

    $refund = requestRefund($order);
    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'failed', 'RF-INV'));
    app(ProcessRefund::class)->handle($refund, $this->admin);

    expect(InventoryTransaction::count())->toBe($movements);
});

// ------------------------------------------------------ Historical record

it('refuses to let a refund amount be edited', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    // The trigger, not the model: this is the guarantee that survives a
    // console command or a hand-run statement.
    expect(fn () => DB::table('refunds')->where('id', $refund->id)->update(['amount_minor' => 1]))
        ->toThrow(QueryException::class);
});

it('refuses to let a settled refund be reopened', function (): void {
    $order = blockedPaidOrder();
    $refund = refundFully($order, $this->admin, 'RF-SETTLED');

    expect($refund->status)->toBe(RefundStatus::Succeeded)
        ->and(fn () => DB::table('refunds')->where('id', $refund->id)->update(['status' => 'pending']))
        ->toThrow(QueryException::class);
});

it('refuses to let a refund be deleted', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    expect(fn () => DB::table('refunds')->where('id', $refund->id)->delete())
        ->toThrow(QueryException::class);
});

it('keeps a refunded order commercially frozen', function (): void {
    $order = blockedPaidOrder();
    refundFully($order, $this->admin, 'RF-FROZEN');

    expect($order->fresh()->status)->toBe(OrderStatus::Refunded)
        ->and(fn () => DB::table('orders')->where('id', $order->id)->update(['total_minor' => 1]))
        ->toThrow(QueryException::class);
});

it('offers no path from refunded back to anything else', function (): void {
    expect(OrderStatus::Refunded->allowedTransitions())->toBe([])
        ->and(OrderStatus::Refunded->isTerminal())->toBeTrue()
        ->and(OrderStatus::Refunded->isPaid())->toBeFalse()
        ->and(OrderStatus::Refunded->acceptsPayment())->toBeFalse()
        // Reachable from exactly one place.
        ->and(OrderStatus::Cancelled->canTransitionTo(OrderStatus::Refunded))->toBeFalse()
        ->and(OrderStatus::PaymentExpired->canTransitionTo(OrderStatus::Refunded))->toBeFalse()
        ->and(OrderStatus::Fulfilled->canTransitionTo(OrderStatus::Refunded))->toBeFalse()
        ->and(OrderStatus::Paid->canTransitionTo(OrderStatus::Refunded))->toBeTrue();
});

// ------------------------------------------------------- Notifications

it('tells a customer only what is true at each stage', function (): void {
    $order = blockedPaidOrder();
    $customer = $order->user;

    $refund = requestRefund($order);

    // Requested but not sent: the customer is told nothing, because nothing
    // has happened yet.
    expect(notificationsFor($customer, NotificationType::RefundStarted))->toHaveCount(0);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'pending', 'RF-NOTIF'));
    app(ProcessRefund::class)->handle($refund, $this->admin);

    // On its way, and said as such -- never as done.
    $started = notificationsFor($customer, NotificationType::RefundStarted)->first();

    expect($started)->not->toBeNull()
        ->and($started->message)->toContain('started returning')
        ->and(notificationsFor($customer, NotificationType::RefundCompleted))->toHaveCount(0);

    fakePaystackRefund(
        paystackRefundBody($refund->amount_minor, 'pending', 'RF-NOTIF'),
        paystackRefundBody($refund->amount_minor, 'processed', 'RF-NOTIF'),
    );
    app(VerifyRefund::class)->handle($refund->fresh());

    $done = notificationsFor($customer, NotificationType::RefundCompleted)->first();

    expect($done)->not->toBeNull()
        ->and($done->message)->toContain('returned');
});

it('never tells a customer their credits are coming back', function (): void {
    $order = blockedPaidOrder();

    refundFully($order, $this->admin, 'RF-WORDS');

    $message = notificationsFor($order->user, NotificationType::RefundCompleted)->first()->message;

    expect($message)->toContain('Credits you spent bidding remain consumed')
        ->and($message)->not->toContain('credits have been returned')
        ->and($message)->not->toContain('credits refunded');
});

it('tells a customer plainly when a refund did not go through', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'failed', 'RF-FAIL'));
    app(ProcessRefund::class)->handle($refund, $this->admin);

    $failed = notificationsFor($order->user, NotificationType::RefundFailed)->first();

    expect($failed)->not->toBeNull()
        ->and($failed->message)->toContain('did not go through')
        // No timeline is promised: the platform does not control when
        // somebody else's bank posts a credit.
        ->and($failed->message)->not->toContain('working days');
});

it('sends one notification however many reconciliations run', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    fakePaystackRefund(
        paystackRefundBody($refund->amount_minor, 'pending', 'RF-ONCE'),
        paystackRefundBody($refund->amount_minor, 'processed', 'RF-ONCE'),
    );
    app(ProcessRefund::class)->handle($refund, $this->admin);

    foreach (range(1, 4) as $ignored) {
        app(VerifyRefund::class)->handle($refund->fresh());
    }

    expect(notificationsFor($order->user, NotificationType::RefundCompleted))->toHaveCount(1);
});

/*
 * The Stage 9 guarantee, extended to money going out. A broken notification
 * layer cannot fail a refund.
 */
it('refunds successfully even when every notification throws', function (): void {
    $order = blockedPaidOrder();

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

    $refund = refundFully($order, $this->admin, 'RF-BROKEN');

    expect($refund->status)->toBe(RefundStatus::Succeeded)
        ->and($order->fresh()->status)->toBe(OrderStatus::Refunded)
        // No refund notification was written, which is the honest outcome --
        // the setup's own notifications predate the broken dispatcher.
        ->and(Notification::query()->where('event_type', 'like', 'refund.%')->count())->toBe(0);
});

// -------------------------------------------------------------- Audit

it('records who asked for a refund and who sent it', function (): void {
    $order = blockedPaidOrder();

    $refund = refundFully($order, $this->admin, 'RF-AUDIT');

    // Scoped to the entries the actions write deliberately. The model's own
    // LogsActivity also records its column changes, which carry no `action`
    // and are a different kind of evidence.
    $entries = Activity::query()
        ->where('log_name', 'refund')
        ->where('subject_id', $refund->id)
        ->get()
        ->filter(fn (Activity $a): bool => ($a->properties['action'] ?? null) !== null);

    expect($entries)->not->toBeEmpty()
        ->and($entries->pluck('causer_id')->unique()->all())->toBe([$this->admin->id]);

    $requested = $entries->first(fn (Activity $a): bool => $a->properties['action'] === 'requested');

    expect($requested)->not->toBeNull()
        ->and($requested->properties['amount_minor'])->toBe($refund->amount_minor)
        ->and($requested->properties['reason'])->toBe($refund->reason->value)
        // Never a credential, never a payload.
        ->and(json_encode($requested->properties))->not->toContain('sk_test');
});

it('records a failed refund in the audit trail too', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'failed', 'RF-AUDITFAIL'));
    app(ProcessRefund::class)->handle($refund, $this->admin);

    $failed = Activity::query()
        ->where('log_name', 'refund')
        ->where('subject_id', $refund->id)
        ->get()
        ->first(fn (Activity $a): bool => ($a->properties['action'] ?? null) === 'failed');

    expect($failed)->not->toBeNull()
        ->and($failed->properties['status'])->toBe(RefundStatus::Failed->value);
});

// ------------------------------------------------------ Provider events

/*
 * Stage 4's webhook guarantees still hold. A refund event from the provider is
 * recorded and acts on nothing: our own refund records are what this platform
 * runs on, and a payload is a claim rather than evidence.
 */
it('does not create a refund from a provider webhook', function (): void {
    $order = blockedPaidOrder();
    $payment = $order->payments()->successful()->first();

    $before = Refund::count();

    foreach (range(1, 3) as $ignored) {
        postOrderWebhook([
            'event' => 'refund.processed',
            'data' => [
                'id' => 999,
                'reference' => $payment->provider_reference,
                'status' => 'processed',
                'amount' => $payment->amount_minor,
                'currency' => 'GHS',
            ],
        ])->assertSuccessful();
    }

    expect(Refund::count())->toBe($before)
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid);
});

it('cannot be over-refunded by a repeated request', function (): void {
    $order = blockedPaidOrder();
    $payment = $order->payments()->successful()->first();

    $refund = requestRefund($order, Money::fromMinor(1_000), key: 'same-key');
    requestRefund($order->fresh(), Money::fromMinor(1_000), key: 'same-key');
    requestRefund($order->fresh(), Money::fromMinor(1_000), key: 'same-key');

    expect(Refund::count())->toBe(1)
        ->and((int) DB::table('refunds')->where('order_payment_id', $payment->id)->sum('amount_minor'))
        ->toBe($refund->amount_minor);
});
