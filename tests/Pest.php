<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CreateAuction;
use App\Domain\Auction\Actions\PlaceBid;
use App\Domain\Auction\Services\AuctionLifecycle;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Credit\Services\CreditLedgerService;
use App\Domain\Delivery\Services\DeliveryLifecycle;
use App\Domain\Orders\Actions\FulfillOrderPayment;
use App\Domain\Orders\Actions\InitializeOrderPayment;
use App\Domain\Orders\Actions\StartBuyNowCheckout;
use App\Domain\Orders\Actions\StartSettlementCheckout;
use App\Domain\Referrals\Actions\AttributeReferral;
use App\Domain\Referrals\Services\ReferralCodes;
use App\Domain\Refunds\Actions\RequestRefund;
use App\Domain\Shared\Money\Money;
use App\Enums\CreditTransactionType;
use App\Enums\DeliveryStatus;
use App\Enums\NotificationType;
use App\Enums\RefundReason;
use App\Models\Address;
use App\Models\Auction;
use App\Models\AuctionRuleset;
use App\Models\Bid;
use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Models\Delivery;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\Referral;
use App\Models\Refund;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/*
 * Concurrency tests truncate rather than wrap in a transaction.
 *
 * They open a second database connection, which cannot see rows written by an
 * uncommitted transaction on the first. Under RefreshDatabase the second
 * connection would observe an empty database and the tests would pass without
 * exercising anything.
 */
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Seeds the roles the application depends on.
 *
 * Reference data, not fixtures: these roles exist in every environment.
 */
function seedRoles(): void
{
    app(RoleSeeder::class)->run();
}

/**
 * Seeds roles and the permissions attached to them.
 */
function seedPermissions(): void
{
    seedRoles();
    app(PermissionSeeder::class)->run();
}

/**
 * Seeds the application's settings definitions.
 */
function seedSettings(): void
{
    app(SettingsSeeder::class)->run();
}

/**
 * A user holding the given role, with permissions already seeded.
 */
function userWithRole(string $role): User
{
    seedPermissions();

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

/**
 * A user's credit wallet.
 *
 * Wallets are created with the user, so this only fetches.
 */
function creditWalletFor(User $user): CreditWallet
{
    return app(CreditLedgerService::class)->walletFor($user);
}

/**
 * Replace every HTTP stub with the given set.
 *
 * Http::fake() appends rather than replaces, and the first matching stub wins.
 * A test that fakes a failure after a beforeEach faked success would therefore
 * silently keep getting the success -- and would pass while proving nothing.
 * Swapping the factory guarantees exactly the stubs asked for.
 *
 * @param  array<string, mixed>  $responses
 */
function fakeHttp(array $responses): void
{
    Http::swap(new Factory);
    Http::fake($responses);
}

/**
 * Stub the Paystack verify endpoint, replacing any previous stub.
 *
 * @param  array<string, mixed>  $data
 */
function fakePaystackVerify(array $data): void
{
    fakeHttp([
        'api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => $data,
        ]),
    ]);
}

/**
 * A signature computed the way Paystack computes it.
 *
 * Tests never need real credentials: the verifier works against whatever
 * secret is configured, and the HTTP client is faked.
 */
function paystackSignature(string $rawPayload, ?string $secret = null): string
{
    return hash_hmac('sha512', $rawPayload, $secret ?? (string) config('paystack.secret_key'));
}

/**
 * A Paystack charge.success webhook body.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function paystackChargePayload(
    string $reference,
    int $amountMinor,
    string $currency = 'GHS',
    int $transactionId = 1234567890,
    array $overrides = [],
): array {
    return array_replace_recursive([
        'event' => 'charge.success',
        'data' => [
            'id' => $transactionId,
            'reference' => $reference,
            'status' => 'success',
            'amount' => $amountMinor,
            'currency' => $currency,
            'channel' => 'mobile_money',
            'paid_at' => now()->toIso8601String(),
        ],
    ], $overrides);
}

/**
 * Grant credits through the ledger, the same way production does.
 *
 * Tests never write a balance directly -- doing so would test a path the
 * application does not have.
 */
function grantCredits(
    User $user,
    int $amount,
    CreditTransactionType $type = CreditTransactionType::Purchase,
    ?DateTimeInterface $expiresAt = null,
): CreditTransaction {
    return app(CreditLedgerService::class)->addCredits(
        wallet: creditWalletFor($user),
        type: $type,
        amount: $amount,
        expiresAt: $expiresAt,
    );
}

/**
 * An auction that is genuinely open, created and published the way the
 * application does it.
 *
 * Not a factory state: publishing reserves a unit of stock through the
 * inventory service, and an auction that skipped that would be a state the
 * engine never produces. Anything about Buy Now, settlement or the races
 * between them needs the reservation to be real.
 */
function liveAuction(
    ?Product $product = null,
    ?AuctionRuleset $ruleset = null,
    int $settlementMinor = 10_000,
    int $stock = 1,
): Auction {
    seedPermissions();

    $product ??= Product::factory()->active()->create();
    // No throttle by default: the helper's job is a plain open auction, and a
    // minimum interval between bids is a specific rule that the tests about it
    // switch on themselves.
    $ruleset ??= AuctionRuleset::factory()->active()->withoutThrottle()->create();

    if ($product->inventoryTransactions()->doesntExist()) {
        app(InventoryService::class)->initialStock($product, $stock);
    }

    $auction = app(CreateAuction::class)->handle(
        product: $product->fresh(),
        ruleset: $ruleset,
        settlementAmount: Money::fromMinor($settlementMinor),
    );

    return app(AuctionLifecycle::class)->start($auction);
}

/**
 * A customer with credits, ready to bid.
 */
function bidder(int $credits = 1_000): User
{
    $user = userWithRole('customer');

    if ($credits > 0) {
        grantCredits($user, $credits);
    }

    return $user;
}

/**
 * Place a bid the way the application does, credits and all.
 *
 * Every test goes through this rather than inserting bid rows, because a bid
 * without its credit consumption is a state the engine cannot produce and a
 * test built on one would prove nothing.
 */
function placeBid(Auction $auction, User $user, int $amountCredits, ?string $key = null): Bid
{
    return app(PlaceBid::class)->handle(
        auction: $auction->fresh(),
        user: $user,
        amountCredits: $amountCredits,
        idempotencyKey: $key ?? ('bid-'.Str::uuid()->toString()),
    );
}

/**
 * Open a Buy Now checkout the way the application does.
 *
 * Through the real action, so the pricing is frozen, the reservation is taken
 * where one is due, and the order that comes back is a state the application
 * genuinely produces.
 */
function buyNowCheckout(User $buyer, Product $product, ?Auction $auction = null): Order
{
    return app(StartBuyNowCheckout::class)->handle($buyer, $product->fresh(), $auction?->fresh());
}

/**
 * Open the checkout an auction winner settles through.
 */
function settlementCheckout(Auction $auction, User $winner): Order
{
    return app(StartSettlementCheckout::class)->handle($auction->fresh(), $winner);
}

/**
 * Open a payment with the (faked) provider for an order.
 *
 * The amount is never passed: the action reads it off the frozen order, which
 * is the whole point of the design being tested.
 */
function initializePayment(Order $order): OrderPayment
{
    // A configured secret, never a real one. The HTTP client is faked and the
    // signature verifier works against whatever is configured, so tests need
    // no credentials -- but the gateway refuses to run without one at all,
    // which is itself the right behaviour.
    config(['paystack.secret_key' => 'sk_test_orders']);

    fakeHttp([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'reference' => 'ignored-the-server-sends-its-own',
                'authorization_url' => 'https://checkout.paystack.com/test',
                'access_code' => 'test-access-code',
            ],
        ]),
    ]);

    return app(InitializeOrderPayment::class)->handle($order->fresh());
}

/**
 * Take an order all the way through a successful, verified payment.
 *
 * Stubs the provider's verify endpoint to agree with what the attempt was
 * actually opened for, then runs the one fulfilment path. Anything testing a
 * mismatch stubs its own disagreeing response instead.
 *
 * @return array{order_id: int, payment_id: int, already_fulfilled: bool}
 */
function payOrder(Order $order, ?OrderPayment $payment = null): array
{
    config(['paystack.secret_key' => 'sk_test_orders']);

    $payment ??= initializePayment($order);

    fakePaystackVerify([
        'reference' => $payment->provider_reference,
        'status' => 'success',
        'amount' => $payment->amount_minor,
        'currency' => $payment->currency,
        'id' => random_int(1, PHP_INT_MAX),
        'channel' => 'mobile_money',
        'paid_at' => now()->toIso8601String(),
    ]);

    return app(FulfillOrderPayment::class)->handle($payment->fresh());
}

/**
 * Post a webhook body to the provider endpoint with a correct signature.
 *
 * Shared rather than declared in one test file: a function defined in a test
 * file exists only once that file has been loaded, so two suites needing it
 * would either collide on redeclaration or depend on load order.
 */
function postOrderWebhook(array $payload): TestResponse
{
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);

    return test()->call(
        'POST',
        route('webhooks.paystack'),
        [], [], [],
        ['HTTP_X_PAYSTACK_SIGNATURE' => paystackSignature($raw), 'CONTENT_TYPE' => 'application/json'],
        $raw,
    );
}

/*
|--------------------------------------------------------------------------
| Refunds
|--------------------------------------------------------------------------
*/

/**
 * Stub Paystack's refund endpoints, replacing any previous stub.
 *
 * Both are stubbed together because the two halves of a refund's life --
 * asking, and later finding out -- use different endpoints, and a test that
 * stubbed only one would have the reconcile pass silently fall through to a
 * real request.
 *
 * `Http::fake()` appends rather than replaces and the first match wins, so
 * this goes through `fakeHttp()`, which swaps the factory outright. Anything
 * that stubbed a verify response earlier must re-stub it here if it still
 * needs one.
 *
 * @param  array<string, mixed>  $create  What POST /refund answers.
 * @param  array<string, mixed>|null  $fetch  What GET /refund/:id answers.
 *                                            Defaults to the same body.
 */
function fakePaystackRefund(array $create, ?array $fetch = null): void
{
    fakeHttp([
        'api.paystack.co/refund/*' => Http::response(['status' => true, 'data' => $fetch ?? $create]),
        'api.paystack.co/refund' => Http::response(['status' => true, 'data' => $create]),
    ]);
}

/**
 * A Paystack refund body, in the shape the provider actually returns.
 *
 * @return array<string, mixed>
 */
function paystackRefundBody(
    int $amountMinor,
    string $status = 'pending',
    string|int $id = 'RF-1',
    string $currency = 'GHS',
): array {
    return [
        'id' => $id,
        'status' => $status,
        'amount' => $amountMinor,
        'currency' => $currency,
    ];
}

/**
 * An order that was paid and could not be delivered against.
 *
 * The situation Stages 7 and 8 deliberately left open, and the only one Stage
 * 10 refunds. Produced by two customers racing for one unit: the first takes
 * it, the second's verified payment has nothing to buy.
 *
 * Returns the loser's order -- paid, blocked, and refundable.
 */
function blockedPaidOrder(?User $buyer = null): Order
{
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    $loser = buyNowCheckout($buyer ?? bidder(), $product->fresh(), $auction->fresh());

    // Somebody else's payment lands first and takes the unit.
    payOrder(buyNowCheckout(bidder(), $product->fresh(), $auction->fresh()));

    payOrder($loser->fresh());

    return $loser->fresh();
}

/**
 * Ask for a refund the way the admin screen does.
 *
 * Shared rather than declared in one test file: a function defined in a test
 * file exists only once that file has been loaded, so two suites needing it
 * would either collide on redeclaration or depend on load order.
 */
function requestRefund(Order $order, ?Money $amount = null, ?string $key = null, ?User $actor = null): Refund
{
    return app(RequestRefund::class)->handle(
        order: $order,
        actor: $actor ?? userWithRole('admin'),
        reason: RefundReason::InventoryConflict,
        amount: $amount,
        idempotencyKey: $key,
    );
}

/**
 * Every notification this user has, of a given type.
 *
 * Shared rather than declared in one test file: a function defined in a test
 * file exists only once that file has been loaded, so two suites needing it
 * would either collide on redeclaration or depend on load order.
 *
 * @return Collection<int, Notification>
 */
function notificationsFor(User $user, ?NotificationType $type = null)
{
    return Notification::query()
        ->where('notifiable_id', $user->id)
        ->when($type !== null, fn ($q) => $q->where('event_type', $type->value))
        ->get();
}

/*
|--------------------------------------------------------------------------
| Delivery
|--------------------------------------------------------------------------
*/

/**
 * A paid order with a delivery already opened against it.
 *
 * Goes through the real checkout and the real verified-payment path, so the
 * delivery is created the way the application actually creates one: by
 * fulfilment, at the moment a payment is verified.
 *
 * The address is optional, because a delivery legitimately begins without one
 * -- that is the auction winner's case, and several tests exist for it.
 */
function paidOrderFor(User $buyer, ?Address $address = null): Order
{
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout($buyer, $product->fresh());

    if ($address !== null) {
        $order->delivery_address_id = $address->id;
        $order->save();
    }

    payOrder($order->fresh());

    return $order->fresh();
}

/**
 * A delivery that has been taken all the way to a given state.
 *
 * Through the lifecycle rather than the factory, so every guard, every history
 * row and every order transition happens the way it would in the warehouse.
 */
function deliveryAt(DeliveryStatus $status, ?User $staff = null, ?Order $order = null): Delivery
{
    $staff ??= userWithRole('admin');
    $order ??= paidOrderFor(bidder(), Address::factory()->ownedBy(bidder())->create());

    $delivery = $order->delivery;

    if ($delivery === null) {
        throw new RuntimeException('That order has no delivery to advance.');
    }

    // Given an order whose address came from somebody else's book, copy one on
    // so the package has somewhere to go.
    if (! $delivery->hasAddress()) {
        $address = Address::factory()->ownedBy($order->user)->create();

        foreach ($address->toSnapshot() as $field => $value) {
            $delivery->{$field} = $value;
        }

        $delivery->source_address_id = $address->id;
        $delivery->save();
    }

    $lifecycle = app(DeliveryLifecycle::class);

    // A list of pairs rather than a keyed array: PHP arrays cannot be keyed by
    // an enum, and the order of these steps is the point anyway.
    $path = [
        [DeliveryStatus::Preparing, fn (Delivery $d): Delivery => $lifecycle->prepare($d, $staff)],
        [DeliveryStatus::ReadyForDispatch, fn (Delivery $d): Delivery => $lifecycle->markReady($d, $staff)],
        [DeliveryStatus::Dispatched, fn (Delivery $d): Delivery => $lifecycle->dispatch($d, $staff)],
        [DeliveryStatus::OutForDelivery, fn (Delivery $d): Delivery => $lifecycle->markOutForDelivery($d, $staff)],
        [DeliveryStatus::Delivered, fn (Delivery $d): Delivery => $lifecycle->markDelivered($d, $staff)],
    ];

    foreach ($path as [$step, $move]) {
        if ($delivery->status === $status) {
            break;
        }

        $delivery = $move($delivery->fresh());

        if ($step === $status) {
            break;
        }
    }

    return $delivery->fresh();
}

/*
|--------------------------------------------------------------------------
| Referrals
|--------------------------------------------------------------------------
*/

/**
 * A referrer, and somebody they introduced.
 *
 * Shared rather than declared in one test file: a function defined in a test
 * file exists only once that file has been loaded, so two suites needing it
 * would either collide on redeclaration or depend on load order.
 *
 * @return array{0: User, 1: User, 2: Referral|null}
 */
function referralPair(): array
{
    $referrer = bidder(0);
    $joiner = bidder(0);

    $referral = app(AttributeReferral::class)->handle(
        $joiner,
        app(ReferralCodes::class)->forUser($referrer),
    );

    return [$referrer->fresh(), $joiner->fresh(), $referral];
}

/**
 * Take a customer through a real, verified Buy Now purchase.
 *
 * The whole path, so the order genuinely reaches `Paid` through a payment the
 * provider confirmed -- which is what the referral programme asks about.
 */
function qualifyingPurchase(User $buyer): Order
{
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout($buyer, $product->fresh());
    payOrder($order->fresh());

    return $order->fresh();
}

/**
 * A product that can actually be bought.
 *
 * A product factory alone produces something the checkout refuses: being
 * listed and being purchasable are different questions, and available stock
 * answers the second. Shared rather than declared in one test file, because a
 * function defined in a test file exists only once that file has loaded.
 */
function stockedProduct(int $priceMinor = 550_000, int $stock = 1): Product
{
    $product = Product::factory()->active()->pricedAt($priceMinor)->create();

    app(InventoryService::class)->initialStock($product, $stock);

    return $product->fresh();
}

/**
 * A member of staff holding exactly the named permissions and nothing else.
 *
 * Deliberately given no role. `syncPermissions` replaces only a user's direct
 * permissions, so an administrator stripped that way still holds everything
 * through the role -- and a test built on one would assert nothing.
 *
 * @param  list<string>  $permissions
 */
function staffWith(array $permissions): User
{
    seedPermissions();

    $user = User::factory()->create();
    $user->syncPermissions($permissions);

    return $user->fresh();
}
