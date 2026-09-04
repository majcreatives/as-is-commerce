<?php

declare(strict_types=1);

use App\Domain\Shared\Money\Money;
use App\Enums\CreditTransactionType;
use App\Models\CreditPackage;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * A product's Buy Now price, a credit package's price, a credit balance and a
 * bid are four separate things. This file exists to make that separation a
 * tested property rather than a convention someone could quietly break.
 *
 * The rule that will eventually connect two of them -- one consumed bid credit
 * giving one cedi off Buy Now -- belongs to the future Auction/Buy Now engine.
 * No code in this stage performs it.
 */

beforeEach(function (): void {
    seedRoles();

    $this->user = User::factory()->create();
});

it('keeps a product price unchanged when the customer buys credits', function (): void {
    $product = Product::factory()->pricedAt(550_000)->create();

    grantCredits($this->user, 5_000, CreditTransactionType::Purchase);

    expect($product->fresh()->buy_now_price_minor)->toBe(550_000)
        ->and($product->fresh()->buyNowPrice()->toDecimalString())->toBe('5500.00');
});

it('keeps a product price unchanged whatever the credit balance is', function (int $credits): void {
    $product = Product::factory()->pricedAt(550_000)->create();

    grantCredits($this->user, $credits);

    expect(creditWalletFor($this->user)->fresh()->balance)->toBe($credits)
        ->and($product->fresh()->buy_now_price_minor)->toBe(550_000);
})->with([1, 150, 5_500, 100_000]);

/*
 * The specific confusion worth ruling out: 150 credits is not GH 150 off, and
 * it is not GH 150 either. No arithmetic ties them together in this stage.
 */
it('does not derive a product price from a credit quantity', function (): void {
    $product = Product::factory()->pricedAt(550_000)->create();

    grantCredits($this->user, 150);

    $price = $product->fresh()->buy_now_price_minor;

    expect($price)->toBe(550_000)
        ->and($price)->not->toBe(15_000)      // not 150 cedis
        ->and($price)->not->toBe(535_000);    // not 5,500 less 150
});

it('keeps credit package prices independent of product prices', function (): void {
    $package = CreditPackage::factory()->priced(500, 4_500)->create();
    $product = Product::factory()->pricedAt(550_000)->create();

    // 500 credits costs GH 45.00; the product costs GH 5,500.00. Neither
    // figure is computed from the other.
    expect($package->price_minor)->toBe(4_500)
        ->and($product->buy_now_price_minor)->toBe(550_000);

    $product->update(['buy_now_price_minor' => 900_000]);

    expect($package->fresh()->price_minor)->toBe(4_500);
});

it('keeps a product price unchanged when a package is repriced', function (): void {
    $package = CreditPackage::factory()->priced(500, 4_500)->create();
    $product = Product::factory()->pricedAt(550_000)->create();

    $package->update(['price_minor' => 9_900]);

    expect($product->fresh()->buy_now_price_minor)->toBe(550_000);
});

/*
 * Structural, not just behavioural: there is no column joining a product to
 * anything in the credit system, so no query could accidentally relate them.
 */
it('has no database link between products and the credit system', function (): void {
    $columns = collect(DB::select('SHOW COLUMNS FROM products'))
        ->pluck('Field')
        ->map(fn (string $c): string => strtolower($c))
        ->all();

    foreach (['credit', 'wallet', 'bid', 'auction', 'package'] as $forbidden) {
        expect(collect($columns)->filter(fn (string $c): bool => str_contains($c, $forbidden))->all())
            ->toBe([], "products should have no column referring to [{$forbidden}]");
    }
});

it('has no seller or vendor column, because inventory is platform-owned', function (): void {
    $columns = collect(DB::select('SHOW COLUMNS FROM products'))
        ->pluck('Field')
        ->map(fn (string $c): string => strtolower($c))
        ->all();

    foreach (['seller', 'vendor', 'merchant', 'supplier'] as $forbidden) {
        expect(collect($columns)->filter(fn (string $c): bool => str_contains($c, $forbidden))->all())
            ->toBe([], "products should have no [{$forbidden}] column in a platform-owned catalog");
    }
});

it('stores both prices as exact integers in their own units', function (): void {
    $package = CreditPackage::factory()->priced(500, Money::fromDecimalString('45.00')->minor)->create();
    $product = Product::factory()->pricedAt(Money::fromDecimalString('5500.00')->minor)->create();

    expect($package->price_minor)->toBe(4_500)->toBeInt()
        ->and($package->credit_amount)->toBe(500)->toBeInt()
        ->and($product->buy_now_price_minor)->toBe(550_000)->toBeInt();
});

it('does not change the credit ledger when a product is priced or repriced', function (): void {
    grantCredits($this->user, 100);
    $ledgerBefore = (int) DB::table('credit_transactions')->sum('amount');

    $product = Product::factory()->pricedAt(550_000)->create();
    $product->update(['buy_now_price_minor' => 100_000]);

    expect((int) DB::table('credit_transactions')->sum('amount'))->toBe($ledgerBefore)
        ->and(creditWalletFor($this->user)->fresh()->balance)->toBe(100);
});
