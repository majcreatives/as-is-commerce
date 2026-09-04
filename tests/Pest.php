<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CreateAuction;
use App\Domain\Auction\Actions\PlaceBid;
use App\Domain\Auction\Services\AuctionLifecycle;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Credit\Services\CreditLedgerService;
use App\Domain\Orders\Actions\FulfillOrderPayment;
use App\Domain\Orders\Actions\InitializeOrderPayment;
use App\Domain\Orders\Actions\StartBuyNowCheckout;
use App\Domain\Orders\Actions\StartSettlementCheckout;
use App\Domain\Shared\Money\Money;
use App\Enums\CreditTransactionType;
use App\Models\Auction;
use App\Models\AuctionRuleset;
use App\Models\Bid;
use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
