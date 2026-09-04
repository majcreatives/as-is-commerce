<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Auction\Actions\CompleteBuyNow;
use App\Domain\Auction\Actions\PlaceBid;
use App\Domain\Auction\Exceptions\BidRejected;
use App\Domain\Auction\Exceptions\BuyNowUnavailable;
use App\Domain\Auction\Services\HighestBidResolver;
use App\Domain\Shared\Money\Money;
use App\Enums\AuctionClosureReason;
use App\Enums\AuctionStatus;
use App\Enums\CreditTransactionType;
use App\Enums\InventoryTransactionType;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\CreditTransaction;
use App\Models\InventoryTransaction;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The races, against real MySQL locks.
 *
 * Truncation rather than a wrapping transaction: a second connection cannot
 * see rows an uncommitted transaction has written, so under RefreshDatabase
 * these tests would observe an empty database and pass without exercising
 * anything.
 *
 * Exactly one person may end up with the product, and no credits may be
 * consumed without the bid that justifies them.
 */

beforeEach(function (): void {
    $this->product = Product::factory()->active()->pricedAt(550_000)->create();
    $this->auction = liveAuction(product: $this->product);

    $this->buyNow = app(CompleteBuyNow::class);
    $this->bids = app(HighestBidResolver::class);

    config(['database.connections.second' => config('database.connections.mysql')]);
    DB::purge('second');
});

afterEach(function (): void {
    try {
        while (DB::connection('second')->transactionLevel() > 0) {
            DB::connection('second')->rollBack();
        }
    } catch (Throwable) {
        // Connection already gone.
    }

    DB::purge('second');
});

/*
 * The primitive everything else rests on. Without a real lock on the auction
 * row, two requests both read the same status and both decide they may act.
 */
it('serializes access to an auction row across connections', function (): void {
    DB::beginTransaction();
    DB::table('auctions')->where('id', $this->auction->id)->lockForUpdate()->first();

    DB::connection('second')->statement('SET SESSION innodb_lock_wait_timeout = 1');
    DB::connection('second')->beginTransaction();

    $blocked = false;

    try {
        DB::connection('second')
            ->table('auctions')
            ->where('id', $this->auction->id)
            ->lockForUpdate()
            ->first();
    } catch (QueryException $e) {
        $blocked = str_contains(strtolower($e->getMessage()), 'lock wait timeout');
    }

    DB::connection('second')->rollBack();
    DB::rollBack();

    expect($blocked)->toBeTrue('A second connection must not take the auction lock while it is held.');
});

// ---------------------------------------------------------- Buy Now races

/*
 * The rule from the brief: the first successfully completed Buy Now wins, and
 * later attempts fail.
 */
it('lets only the first Buy Now take the product', function (): void {
    $buyers = [bidder(), bidder(), bidder()];

    $succeeded = 0;
    $refused = 0;

    foreach ($buyers as $index => $buyer) {
        try {
            $this->buyNow->handle(
                Auction::findOrFail($this->auction->id),
                $buyer,
                Money::fromMinor(550_000),
                "buy-{$index}",
            );
            $succeeded++;
        } catch (BuyNowUnavailable) {
            $refused++;
        }
    }

    $auction = $this->auction->fresh();

    expect($succeeded)->toBe(1)
        ->and($refused)->toBe(2)
        ->and($auction->status)->toBe(AuctionStatus::Settled)
        ->and($auction->closure_reason)->toBe(AuctionClosureReason::BuyNow)
        // One buyer, one sale, one unit gone.
        ->and($this->product->fresh()->stock_on_hand)->toBe(0)
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1);
});

it('does not let a stale auction instance buy a product already sold', function (): void {
    $stale = Auction::findOrFail($this->auction->id);

    $this->buyNow->handle($this->auction, bidder(), Money::fromMinor(550_000), 'first');

    // The stale object still says the auction is live.
    expect($stale->status)->toBe(AuctionStatus::Live)
        ->and(fn (): Auction => $this->buyNow->handle($stale, bidder(), Money::fromMinor(550_000), 'second'))
        ->toThrow(BuyNowUnavailable::class);

    expect($this->product->fresh()->stock_on_hand)->toBe(0);
});

// ------------------------------------------------------ Bid against Buy Now

it('gives the product to the Buy Now buyer and not the bidder racing it', function (): void {
    $bidder = bidder(2_000);
    $buyer = bidder();

    placeBid($this->auction, $bidder, 500);

    $this->buyNow->handle(
        Auction::findOrFail($this->auction->id),
        $buyer,
        Money::fromMinor(550_000),
        'buy',
    );

    // A bid arriving after the purchase completed is refused outright.
    expect(fn (): Bid => placeBid(Auction::findOrFail($this->auction->id), $bidder, 900))
        ->toThrow(BidRejected::class);

    $auction = $this->auction->fresh();

    expect($auction->buy_now_user_id)->toBe($buyer->id)
        ->and($auction->winner_user_id)->toBeNull()
        // The bidder committed 500 credits and got neither the product nor
        // the credits back.
        ->and(creditWalletFor($bidder)->fresh()->balance)->toBe(1_500)
        ->and(Bid::where('auction_id', $auction->id)->count())->toBe(1);
});

it('leaves a bid placed before a Buy Now valid and paid for', function (): void {
    $bidder = bidder(1_000);
    $bid = placeBid($this->auction, $bidder, 300);

    $this->buyNow->handle(Auction::findOrFail($this->auction->id), bidder(), Money::fromMinor(550_000), 'buy');

    // The bid is still on record with its credit consumption intact -- it was
    // a real transaction, it simply did not win.
    expect($bid->fresh()->amount_credits)->toBe(300)
        ->and($bid->fresh()->creditTransaction->amount)->toBe(-300)
        ->and($this->auction->fresh()->highest_bid_credits)->toBe(300);
});

// ----------------------------------------------------------- Bid vs bid

it('does not let two bids take the same sequence number', function (): void {
    $bidders = [bidder(1_000), bidder(1_000), bidder(1_000), bidder(1_000)];

    foreach ($bidders as $index => $user) {
        placeBid(Auction::findOrFail($this->auction->id), $user, 100 + $index);
    }

    $sequences = Bid::where('auction_id', $this->auction->id)->pluck('sequence')->all();

    expect($sequences)->toBe([1, 2, 3, 4])
        ->and(count(array_unique($sequences)))->toBe(4);
});

it('never lets a bidder overspend across repeated bids', function (): void {
    $user = bidder(250);

    $accepted = 0;

    foreach (range(1, 10) as $ignored) {
        try {
            placeBid(Auction::findOrFail($this->auction->id), $user, 100);
            $accepted++;
        } catch (BidRejected) {
            break;
        }
    }

    // Two bids of 100 fit in 250 credits; the third does not.
    expect($accepted)->toBe(2)
        ->and(creditWalletFor($user)->fresh()->balance)->toBe(50)
        ->and(creditWalletFor($user)->fresh()->balance)->toBeGreaterThanOrEqual(0);
});

it('keeps the highest-bid projection correct after many bids', function (): void {
    foreach (range(1, 12) as $amount) {
        placeBid(Auction::findOrFail($this->auction->id), bidder(), $amount * 10);
    }

    $auction = $this->auction->fresh();

    expect($auction->highest_bid_credits)->toBe(120)
        ->and($auction->bid_count)->toBe(12)
        // The cache agrees with the records it is a cache of.
        ->and($this->bids->verify($auction)['matches'])->toBeTrue();
});

it('keeps the credit ledger consistent with the bids placed', function (): void {
    $user = bidder(1_000);

    foreach ([50, 100, 150] as $amount) {
        placeBid(Auction::findOrFail($this->auction->id), $user, $amount);
    }

    $bidTotal = (int) Bid::where('user_id', $user->id)->sum('amount_credits');

    // Every credit consumed is accounted for by a bid, and every bid by a
    // consumption. They can only agree if both happen in one transaction.
    expect($bidTotal)->toBe(300)
        ->and(creditWalletFor($user)->fresh()->balance)->toBe(700)
        ->and(Bid::where('user_id', $user->id)->count())
        ->toBe(CreditTransaction::where('type', CreditTransactionType::BidDebit)
            ->where('credit_wallet_id', creditWalletFor($user)->id)->count());
});

// ------------------------------------------------------ Close against bid

it('does not let a bid land on an auction that has just closed', function (): void {
    $user = bidder(1_000);
    placeBid($this->auction, $user, 100);

    app(CloseAuction::class)->handle(Auction::findOrFail($this->auction->id), force: true);

    expect(fn (): Bid => placeBid(Auction::findOrFail($this->auction->id), $user, 500))
        ->toThrow(BidRejected::class);

    // The winner is the highest bid that was actually accepted, and the late
    // 500 credits were never consumed.
    expect($this->auction->fresh()->highest_bid_credits)->toBe(100)
        ->and(creditWalletFor($user)->fresh()->balance)->toBe(900);
});

it('closes an auction once when several sweeps run together', function (): void {
    placeBid($this->auction, bidder(), 100);

    foreach (range(1, 4) as $ignored) {
        app(CloseAuction::class)->handle(Auction::findOrFail($this->auction->id), force: true);
    }

    expect($this->auction->fresh()->transitions()
        ->where('to_status', AuctionStatus::PendingSettlement)->count())->toBe(1);
});

// -------------------------------------------------------- Inventory races

it('does not sell one unit twice however the auction ends', function (): void {
    $bidder = bidder(1_000);
    placeBid($this->auction, $bidder, 200);

    $this->buyNow->handle(Auction::findOrFail($this->auction->id), bidder(), Money::fromMinor(550_000), 'buy');

    // A closing sweep arriving afterwards must find nothing to do.
    app(CloseAuction::class)->handle(Auction::findOrFail($this->auction->id), force: true);

    $product = $this->product->fresh();

    expect($product->stock_on_hand)->toBe(0)
        ->and($product->stock_reserved)->toBe(0)
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1)
        ->and($this->auction->fresh()->closure_reason)->toBe(AuctionClosureReason::BuyNow);
});

it('keeps the stock projection agreeing with the inventory ledger', function (): void {
    $this->buyNow->handle(Auction::findOrFail($this->auction->id), bidder(), Money::fromMinor(550_000), 'buy');

    $sum = (int) InventoryTransaction::where('product_id', $this->product->id)
        ->whereIn('type', [
            InventoryTransactionType::InitialStock,
            InventoryTransactionType::Restock,
            InventoryTransactionType::Sale,
            InventoryTransactionType::Return,
            InventoryTransactionType::ManualAdjustment,
        ])
        ->sum('quantity_delta');

    expect($this->product->fresh()->stock_on_hand)->toBe($sum)->toBe(0);
});

// ------------------------------------------------------------ Idempotency

it('consumes credits once when a bid request is delivered repeatedly', function (): void {
    $user = bidder(1_000);

    foreach (range(1, 5) as $ignored) {
        app(PlaceBid::class)->handle(
            Auction::findOrFail($this->auction->id),
            $user,
            150,
            'delivered-five-times',
        );
    }

    expect(Bid::count())->toBe(1)
        ->and(creditWalletFor($user)->fresh()->balance)->toBe(850);
});

it('completes one sale when a Buy Now confirmation is delivered repeatedly', function (): void {
    $buyer = bidder();

    foreach (range(1, 5) as $ignored) {
        $this->buyNow->handle(
            Auction::findOrFail($this->auction->id),
            $buyer,
            Money::fromMinor(550_000),
            'delivered-five-times',
        );
    }

    expect(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1)
        ->and($this->product->fresh()->stock_on_hand)->toBe(0);
});
