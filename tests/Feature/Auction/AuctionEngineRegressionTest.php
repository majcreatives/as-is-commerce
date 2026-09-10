<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Auction\Actions\CompleteBuyNow;
use App\Domain\Auction\Exceptions\InvalidAuctionRules;
use App\Domain\Auction\ValueObjects\AuctionRules;
use App\Domain\Catalog\Exceptions\StockMutationForbidden;
use App\Domain\Shared\Ledger\BalanceMutationForbidden;
use App\Domain\Shared\Money\Money;
use App\Enums\CreditTransactionType;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\CreditPackage;
use App\Models\CreditTransaction;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/*
 * Guards against the engine drifting back to a model this platform does not
 * use.
 *
 * The correction stage removed Last Bidder Standing from the ruleset; this
 * file makes sure the engine built on top of it cannot reintroduce that, or
 * lowest-unique-bid, or cash bidding, or any conversion between credits and
 * the price of anything.
 *
 * These tests are structural on purpose. They read the schema and the
 * snapshots rather than exercising a path, because the failures they guard
 * against arrive as a well-meaning column or a plausible-looking calculation.
 */

// ------------------------------------------------------------ Schema sweep

it('has no obsolete ruleset columns anywhere in the auction tables', function (string $column): void {
    foreach (['auctions', 'bids', 'auction_rulesets'] as $table) {
        $columns = collect(DB::select("SHOW COLUMNS FROM {$table}"))
            ->pluck('Field')
            ->map(fn (string $c): string => strtolower($c))
            ->all();

        expect($columns)->not->toContain($column, "[{$table}] should have no [{$column}] column");
    }
})->with([
    // A leader forbidden from leading twice running.
    'unique_leader',
    // A fixed cost per bid action.
    'bid_cost_credits',
    // The ruleset-level checkout price the correction stage removed.
    'default_checkout_price_minor',
]);

it('has no column implying a last-bidder or unique-bid winner', function (string $table): void {
    $columns = collect(DB::select("SHOW COLUMNS FROM {$table}"))
        ->pluck('Field')
        ->map(fn (string $c): string => strtolower($c))
        ->all();

    foreach (['leader', 'last_bid', 'standing', 'unique_bid', 'consecutive', 'lowest'] as $forbidden) {
        expect(collect($columns)->filter(fn (string $c): bool => str_contains($c, $forbidden))->all())
            ->toBe([], "[{$table}] should have no column containing [{$forbidden}]");
    }
})->with(['auctions', 'bids', 'auction_rulesets']);

/*
 * A bid is a number of credits. If it ever gains a monetary column, somebody
 * has started treating bids as cash.
 */
it('has no monetary column on the bid table', function (): void {
    $columns = collect(DB::select('SHOW COLUMNS FROM bids'))
        ->pluck('Field')
        ->map(fn (string $c): string => strtolower($c))
        ->all();

    foreach (['minor', 'price', 'amount_ghs', 'currency', 'cash', 'money'] as $forbidden) {
        expect(collect($columns)->filter(fn (string $c): bool => str_contains($c, $forbidden))->all())
            ->toBe([], "bids should have no column containing [{$forbidden}]");
    }

    // The one amount a bid carries, and it is a count of credits.
    expect($columns)->toContain('amount_credits');
});

it('has no credit column on the product table', function (): void {
    $columns = collect(DB::select('SHOW COLUMNS FROM products'))
        ->pluck('Field')
        ->map(fn (string $c): string => strtolower($c))
        ->all();

    foreach (['credit', 'bid', 'wallet', 'auction'] as $forbidden) {
        expect(collect($columns)->filter(fn (string $c): bool => str_contains($c, $forbidden))->all())
            ->toBe([], "products should have no column containing [{$forbidden}]");
    }
});

it('keeps the platform-owned inventory rule after the auction stage', function (): void {
    $columns = collect(DB::select('SHOW COLUMNS FROM products'))
        ->pluck('Field')
        ->map(fn (string $c): string => strtolower($c))
        ->all();

    foreach (['seller', 'vendor', 'merchant', 'supplier'] as $forbidden) {
        expect(collect($columns)->filter(fn (string $c): bool => str_contains($c, $forbidden))->all())
            ->toBe([], "products should have no column containing [{$forbidden}]");
    }
});

// ----------------------------------------------------------- The snapshot

it('records the highest-bid winner rule and no other', function (): void {
    $snapshot = liveAuction()->rules_snapshot;

    expect($snapshot['rules']['winner_rule'])->toBe('highest_valid_credit_bid');

    $flat = json_encode($snapshot, JSON_THROW_ON_ERROR);

    foreach (['last_bidder', 'lowest_unique', 'unique_leader', 'bid_cost'] as $forbidden) {
        expect($flat)->not->toContain($forbidden);
    }
});

it('carries no ruleset-level settlement price in the rules half of a snapshot', function (): void {
    // The auction's own settlement amount lives beside the rules, not inside
    // them: it is a per-auction figure, and putting it in the ruleset would
    // recreate the shared checkout price the correction stage removed.
    $snapshot = liveAuction()->rules_snapshot;

    foreach (array_keys($snapshot['rules']) as $key) {
        expect($key)->not->toContain('checkout_price')
            ->and($key)->not->toContain('settlement');
    }

    expect($snapshot['auction'])->toHaveKey('settlement_amount_minor');
});

// ------------------------------------------------- No bid-to-price conversion

it('never derives the settlement amount from the winning bid', function (): void {
    $auction = liveAuction(settlementMinor: 10_000);
    placeBid($auction, bidder(500), 180);

    $closed = app(CloseAuction::class)->handle($auction, force: true);

    // 180 credits committed. What is owed is GH 100, and never GH 180.
    expect($closed->highest_bid_credits)->toBe(180)
        ->and($closed->settlement_amount_minor)->toBe(10_000)
        ->and($closed->settlement_amount_minor)->not->toBe(18_000);
});

it('never repriced the product as bidding proceeded', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    foreach ([50, 200, 900] as $amount) {
        placeBid($auction->fresh(), bidder(2_000), $amount);
    }

    app(CloseAuction::class)->handle($auction->fresh(), force: true);

    expect($product->fresh()->buy_now_price_minor)->toBe(550_000);
});

it('never uses the Buy Now price as the settlement amount', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product, settlementMinor: 10_000);

    expect($auction->settlement_amount_minor)->not->toBe($product->buy_now_price_minor)
        // Strict: a loose comparison matches any truthy value, so `true` would
        // register as the price and this would pass for nothing.
        ->and(collect($auction->rules_snapshot['auction'])->containsStrict(550_000))->toBeFalse();
});

it('never converts a credit balance into money outside the Buy Now discount', function (): void {
    $auction = liveAuction();
    $user = bidder(5_000);

    placeBid($auction, $user, 150);

    // Every cash transaction in the system, after a bid. There should be none:
    // bidding moves credits, and credits are not money.
    expect(DB::table('cash_transactions')->count())->toBe(0)
        ->and(creditWalletFor($user)->fresh()->balance)->toBe(4_850);
});

it('never posts a cash transaction when an auction closes', function (): void {
    $auction = liveAuction();
    placeBid($auction, bidder(500), 200);

    app(CloseAuction::class)->handle($auction, force: true);

    expect(DB::table('cash_transactions')->count())->toBe(0);
});

it('keeps credit package prices unrelated to anything the auction does', function (): void {
    $package = CreditPackage::factory()->priced(500, 4_500)->create();
    $auction = liveAuction();

    placeBid($auction, bidder(1_000), 500);
    app(CloseAuction::class)->handle($auction, force: true);

    expect($package->fresh()->price_minor)->toBe(4_500);
});

// ------------------------------------------------------- Cash bidding

it('records a bid as a credit debit and never as money', function (): void {
    $auction = liveAuction();
    $bid = placeBid($auction, bidder(500), 150);

    $transaction = $bid->creditTransaction;

    expect($transaction->type)->toBe(CreditTransactionType::BidDebit)
        ->and($transaction->amount)->toBe(-150)
        // It is on the credit ledger, not the cash ledger.
        ->and($transaction)->toBeInstanceOf(CreditTransaction::class)
        ->and(DB::table('cash_transactions')->count())->toBe(0);
});

it('has no relationship from a bid to a cash wallet', function (): void {
    $columns = collect(DB::select('SHOW COLUMNS FROM bids'))->pluck('Field')->all();

    expect($columns)->not->toContain('cash_wallet_id')
        ->not->toContain('cash_transaction_id')
        ->toContain('credit_transaction_id');
});

// ------------------------------------------------- Earlier stages intact

it('leaves the credit ledger rules untouched', function (): void {
    $auction = liveAuction();
    $user = bidder(500);

    placeBid($auction, $user, 150);

    $wallet = creditWalletFor($user)->fresh();

    // The Stage 3 invariant: the balance is a projection of the ledger.
    $ledger = (int) CreditTransaction::where('credit_wallet_id', $wallet->id)->sum('amount');

    expect($wallet->balance)->toBe($ledger)->toBe(350);

    // And it still cannot be written by hand.
    $wallet->balance = 9_999;
    expect(fn (): bool => $wallet->save())
        ->toThrow(BalanceMutationForbidden::class);
});

it('leaves the inventory rules untouched', function (): void {
    $auction = liveAuction();
    $product = $auction->product->fresh();

    $product->stock_on_hand = 99;

    expect(fn (): bool => $product->save())
        ->toThrow(StockMutationForbidden::class);
});

it('leaves the ruleset snapshot rules untouched', function (): void {
    $auction = liveAuction();

    // Still version 3 of the corrected rules shape, inside the auction's own
    // snapshot envelope. Version 3 dropped the flat per-credit rate in favour
    // of lot-based valuation; the engine refuses every other version.
    expect($auction->rules_snapshot['rules']['snapshot_version'])
        ->toBe(AuctionRules::SNAPSHOT_VERSION)
        ->toBe(3);
});

it('still refuses a version 1 rules snapshot', function (): void {
    expect(fn () => AuctionRules::fromArray([
        'snapshot_version' => 1,
        'bid_cost_credits' => 1,
        'unique_leader' => true,
    ]))->toThrow(InvalidAuctionRules::class);
});

// ------------------------------------------------- The winner rule itself

it('resolves the winner by amount and never by recency', function (): void {
    $auction = liveAuction();
    $highest = bidder(1_000);
    $latest = bidder(1_000);

    placeBid($auction, $highest, 500);
    placeBid($auction, $latest, 499);

    $closed = app(CloseAuction::class)->handle($auction, force: true);

    expect($closed->winner_user_id)->toBe($highest->id);
});

it('resolves the winner by amount and never by scarcity of the amount', function (): void {
    $auction = liveAuction();
    $unique = bidder(1_000);
    $shared = bidder(1_000);
    $other = bidder(1_000);

    // A lowest-unique-bid model would pick the 7. This one picks the 300.
    placeBid($auction, $unique, 7);
    placeBid($auction, $shared, 300);
    placeBid($auction, $other, 300);

    $closed = app(CloseAuction::class)->handle($auction, force: true);

    expect($closed->winner_user_id)->toBe($shared->id)
        ->and($closed->highest_bid_credits)->toBe(300);
});

it('lets a Buy Now end an auction without ever creating a bid winner', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    placeBid($auction, bidder(2_000), 1_500);

    $ended = app(CompleteBuyNow::class)
        ->handle($auction->fresh(), bidder(), Money::fromMinor(550_000), 'buy');

    expect($ended->winner_user_id)->toBeNull()
        ->and($ended->winning_bid_id)->toBeNull()
        ->and($ended->buy_now_user_id)->not->toBeNull();
});

it('keeps every bid ever placed, whatever the outcome', function (): void {
    $auction = liveAuction();

    foreach ([10, 20, 30] as $amount) {
        placeBid($auction->fresh(), bidder(), $amount);
    }

    app(CloseAuction::class)->handle($auction->fresh(), force: true);

    // Losing bids are not tidied away. They are the evidence that credits were
    // consumed, and they decide the Buy Now discount later.
    expect(Bid::where('auction_id', $auction->id)->count())->toBe(3);
});

it('never leaves an auction claiming both a bid winner and a Buy Now buyer', function (): void {
    $auction = liveAuction();
    placeBid($auction, bidder(500), 100);
    app(CloseAuction::class)->handle($auction, force: true);

    $fresh = Auction::findOrFail($auction->id);

    expect($fresh->winner_user_id)->not->toBeNull()
        ->and($fresh->buy_now_user_id)->toBeNull();
});
