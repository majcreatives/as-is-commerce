<?php

declare(strict_types=1);

use App\Domain\Credit\Services\CreditLedgerService;
use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
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
