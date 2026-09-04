<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Auction\Actions\CompleteBuyNow;
use App\Domain\Auction\Exceptions\BidRejected;
use App\Domain\Auction\Exceptions\BuyNowUnavailable;
use App\Domain\Auction\Services\AuctionLifecycle;
use App\Domain\Auction\Services\BuyNowPricer;
use App\Domain\Catalog\Exceptions\InvalidStockMovement;
use App\Domain\Shared\Money\Money;
use App\Enums\AuctionClosureReason;
use App\Enums\AuctionStatus;
use App\Enums\CreditTransactionType;
use App\Enums\InventoryTransactionType;
use App\Models\Auction;
use App\Models\AuctionRuleset;
use App\Models\InventoryTransaction;
use App\Models\Product;
use Illuminate\Database\QueryException;

/*
 * Buying the product outright: what it costs, and what it does to the auction.
 */

beforeEach(function (): void {
    $this->pricer = app(BuyNowPricer::class);
    $this->buyNow = app(CompleteBuyNow::class);
    $this->lifecycle = app(AuctionLifecycle::class);
});

// ------------------------------------------------------------ The discount

/*
 * The worked example from the brief, end to end.
 */
it('takes one cedi off Buy Now for each consumed bid credit', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $user = bidder(1_000);

    placeBid($auction, $user, 150);

    $quote = $this->pricer->quote($auction->fresh(), $user);

    expect($quote->listPrice->toDecimalString())->toBe('5500.00')
        ->and($quote->eligibleCredits)->toBe(150)
        ->and($quote->discount->toDecimalString())->toBe('150.00')
        ->and($quote->payable->toDecimalString())->toBe('5350.00');
});

it('adds up several bids on the same auction', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $user = bidder(1_000);

    placeBid($auction, $user, 100);
    placeBid($auction, $user, 200);

    expect($this->pricer->quote($auction->fresh(), $user)->payable->toDecimalString())
        ->toBe('5200.00');
});

it('offers no discount to someone who has not bid', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    $quote = $this->pricer->quote($auction, bidder(1_000));

    expect($quote->eligibleCredits)->toBe(0)
        ->and($quote->discount->isZero())->toBeTrue()
        ->and($quote->payable->toDecimalString())->toBe('5500.00');
});

/*
 * Which credits count, and -- more importantly -- which do not.
 */
it('ignores unused credits sitting in the wallet', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    // A large balance, one small bid.
    $user = bidder(5_000);
    placeBid($auction, $user, 10);

    $quote = $this->pricer->quote($auction->fresh(), $user);

    expect(creditWalletFor($user)->fresh()->balance)->toBe(4_990)
        // Ten credits were consumed. The other 4,990 buy nothing.
        ->and($quote->eligibleCredits)->toBe(10)
        ->and($quote->discount->toDecimalString())->toBe('10.00');
});

it('ignores credits consumed on a different auction', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $elsewhere = liveAuction();
    $user = bidder(1_000);

    placeBid($elsewhere, $user, 400);
    placeBid($auction, $user, 50);

    expect($this->pricer->quote($auction->fresh(), $user)->eligibleCredits)->toBe(50);
});

it('ignores credits another user consumed on the same auction', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $buyer = bidder(1_000);
    $other = bidder(1_000);

    placeBid($auction, $other, 400);
    placeBid($auction, $buyer, 50);

    expect($this->pricer->quote($auction->fresh(), $buyer)->eligibleCredits)->toBe(50);
});

it('ignores promotional credits that were never bid', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $user = bidder(100);

    grantCredits($user, 900, CreditTransactionType::PromotionalCredit);
    placeBid($auction, $user, 25);

    expect(creditWalletFor($user)->fresh()->balance)->toBe(975)
        ->and($this->pricer->quote($auction->fresh(), $user)->eligibleCredits)->toBe(25);
});

it('offers no discount when the rules switch it off', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()
        ->create(['buy_now_credit_discount_enabled' => false]);

    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product, ruleset: $ruleset);
    $user = bidder(1_000);

    placeBid($auction, $user, 150);

    expect($this->pricer->quote($auction->fresh(), $user)->payable->toDecimalString())
        ->toBe('5500.00');
});

it('never lets the discount exceed the price', function (): void {
    $product = Product::factory()->active()->pricedAt(5_000)->create();
    $auction = liveAuction(product: $product);
    $user = bidder(1_000);

    // 900 credits would be GH 900 off a GH 50 product.
    placeBid($auction, $user, 900);

    $quote = $this->pricer->quote($auction->fresh(), $user);

    expect($quote->payable->minor)->toBe(0)
        ->and($quote->payable->isNegative())->toBeFalse();
});

it('uses the discount rate frozen into the auction, not the live one', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()->create([
        'buy_now_credit_discount_minor_per_credit' => 100,
    ]);

    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product, ruleset: $ruleset);
    $user = bidder(1_000);
    placeBid($auction, $user, 150);

    $ruleset->update(['buy_now_credit_discount_minor_per_credit' => 500]);

    // Still GH 1 per credit, not GH 5.
    expect($this->pricer->quote($auction->fresh(), $user)->discount->toDecimalString())
        ->toBe('150.00');
});

it('leaves the credits consumed after a discount is applied', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $user = bidder(1_000);

    placeBid($auction, $user, 150);
    $this->buyNow->handle($auction->fresh(), $user, Money::fromMinor(535_000), 'buy-1');

    // A discount is not a refund. The credits stay gone.
    expect(creditWalletFor($user)->fresh()->balance)->toBe(850);
});

it('leaves the product price untouched', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $user = bidder(1_000);

    placeBid($auction, $user, 150);
    $this->pricer->quote($auction->fresh(), $user);

    // The discount is a calculation against the price, not a repricing of it.
    expect($product->fresh()->buy_now_price_minor)->toBe(550_000);
});

// ------------------------------------------------------ Ending the auction

it('ends the auction immediately when a purchase completes', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $buyer = bidder(1_000);

    $ended = $this->buyNow->handle($auction, $buyer, Money::fromMinor(550_000), 'buy-1');

    expect($ended->status)->toBe(AuctionStatus::Settled)
        ->and($ended->closure_reason)->toBe(AuctionClosureReason::BuyNow)
        ->and($ended->buy_now_user_id)->toBe($buyer->id)
        ->and($ended->buy_now_ended_at)->not->toBeNull();
});

/*
 * The rule that surprises people: however far ahead the leading bidder is,
 * a completed Buy Now takes the product and they get nothing.
 */
it('does not make the standing highest bidder the winner', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $leader = bidder(2_000);
    $buyer = bidder(1_000);

    placeBid($auction, $leader, 900);

    $ended = $this->buyNow->handle($auction->fresh(), $buyer, Money::fromMinor(550_000), 'buy-1');

    expect($ended->winner_user_id)->toBeNull()
        ->and($ended->winning_bid_id)->toBeNull()
        ->and($ended->buy_now_user_id)->toBe($buyer->id)
        // The leader's 900 credits are still gone.
        ->and(creditWalletFor($leader)->fresh()->balance)->toBe(1_100)
        ->and($ended->highest_bid_credits)->toBe(900);
});

it('is recorded distinctly from a normal highest-bid settlement', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    placeBid($auction, bidder(), 100);

    $ended = $this->buyNow->handle($auction->fresh(), bidder(), Money::fromMinor(550_000), 'buy-1');

    expect($ended->endedByBuyNow())->toBeTrue()
        ->and($ended->hasBidWinner())->toBeFalse()
        ->and($ended->closure_reason?->producedBidWinner())->toBeFalse();
});

it('refuses at the database level to record both a buyer and a bid winner', function (): void {
    $auction = liveAuction();
    $buyer = bidder();

    $this->buyNow->handle($auction, $buyer, Money::fromMinor($auction->product->buy_now_price_minor), 'buy-1');

    expect(fn () => DB::table('auctions')->where('id', $auction->id)->update([
        'winner_user_id' => $buyer->id,
        'winning_bid_id' => 1,
    ]))->toThrow(QueryException::class);
});

it('refuses a second Buy Now once the first has completed', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    $this->buyNow->handle($auction, bidder(), Money::fromMinor(550_000), 'buy-1');

    expect(fn (): Auction => $this->buyNow->handle(
        $auction->fresh(), bidder(), Money::fromMinor(550_000), 'buy-2'
    ))->toThrow(BuyNowUnavailable::class, 'already been bought');
});

it('refuses a bid once the product has been bought outright', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    $this->buyNow->handle($auction, bidder(), Money::fromMinor(550_000), 'buy-1');

    expect(fn () => placeBid($auction->fresh(), bidder(), 100))
        ->toThrow(BidRejected::class, 'bought outright');
});

it('refuses Buy Now on an auction that already closed', function (): void {
    $auction = liveAuction();
    app(CloseAuction::class)->handle($auction, force: true);

    expect(fn (): Auction => $this->buyNow->handle(
        $auction->fresh(), bidder(), Money::fromMinor($auction->product->buy_now_price_minor), 'buy-1'
    ))->toThrow(BuyNowUnavailable::class, 'already ended');
});

it('refuses Buy Now when the rules switch it off', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()->withoutBuyNow()->create();
    $auction = liveAuction(ruleset: $ruleset);

    expect(fn (): Auction => $this->buyNow->handle(
        $auction, bidder(), Money::fromMinor($auction->product->buy_now_price_minor), 'buy-1'
    ))->toThrow(BuyNowUnavailable::class, 'not available');
});

it('refuses a payment that does not match the quote', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    // Client-supplied price, ignored: the server re-prices from its own data.
    expect(fn (): Auction => $this->buyNow->handle(
        $auction, bidder(), Money::fromMinor(1), 'buy-1'
    ))->toThrow(BuyNowUnavailable::class, 'does not match');
});

it('prices the purchase from the buyer own bids at the moment of completion', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $buyer = bidder(1_000);

    placeBid($auction, $buyer, 150);

    $ended = $this->buyNow->handle($auction->fresh(), $buyer, Money::fromMinor(535_000), 'buy-1');

    expect($ended->buy_now_eligible_credits)->toBe(150)
        ->and($ended->buy_now_discount_minor)->toBe(15_000)
        ->and($ended->buy_now_payable_minor)->toBe(535_000);
});

// ------------------------------------------------------------- Inventory

it('sells the unit the auction was holding', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    expect($product->fresh()->stock_reserved)->toBe(1);

    $this->buyNow->handle($auction, bidder(), Money::fromMinor(550_000), 'buy-1');

    $product->refresh();

    // One unit left, not two: the sale nets against the reservation.
    expect($product->stock_on_hand)->toBe(0)
        ->and($product->stock_reserved)->toBe(0);
});

it('records the sale against the auction in the inventory ledger', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    $this->buyNow->handle($auction, bidder(), Money::fromMinor(550_000), 'buy-1');

    $sale = InventoryTransaction::where('product_id', $product->id)
        ->where('type', InventoryTransactionType::Sale)
        ->first();

    expect($sale)->not->toBeNull()
        ->and($sale->reference_type)->toBe(Auction::class)
        ->and($sale->reference_id)->toBe($auction->id)
        ->and($sale->quantity_delta)->toBe(-1);
});

it('cannot sell a product that has none left', function (): void {
    $product = Product::factory()->active()->create();
    $first = liveAuction(product: $product, stock: 1);

    // A second auction on the same one-unit product cannot even publish.
    expect(fn (): Auction => liveAuction(product: $product->fresh()))
        ->toThrow(InvalidStockMovement::class);

    expect($product->fresh()->availableStock())->toBe(0)
        ->and($first->fresh()->status)->toBe(AuctionStatus::Live);
});

// ------------------------------------------------------------ Idempotency

it('completes one purchase however many times the confirmation arrives', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $buyer = bidder();

    $first = $this->buyNow->handle($auction, $buyer, Money::fromMinor(550_000), 'same-key');
    $second = $this->buyNow->handle($auction->fresh(), $buyer, Money::fromMinor(550_000), 'same-key');

    expect($second->id)->toBe($first->id)
        // One sale, not two.
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1)
        ->and($product->fresh()->stock_on_hand)->toBe(0);
});

// ------------------------------------------------------------ Availability

it('reports Buy Now as unavailable once the auction has ended', function (): void {
    $auction = liveAuction();
    app(CloseAuction::class)->handle($auction, force: true);

    $quote = $this->pricer->quote($auction->fresh(), bidder());

    expect($quote->available)->toBeFalse()
        ->and($quote->unavailableReason)->toContain('not open');
});

it('reports Buy Now as available on an open auction', function (): void {
    expect($this->pricer->quote(liveAuction(), bidder())->available)->toBeTrue();
});

/*
 * An open auction is holding its own unit in reserve, so the product's
 * *available* stock is zero by design. Measuring availability that way would
 * report every auction as out of stock and hide Buy Now completely. The unit
 * held in reserve is precisely the one Buy Now sells.
 */
it('reports Buy Now as available even though the auction reserved the only unit', function (): void {
    $product = Product::factory()->active()->create();
    $auction = liveAuction(product: $product, stock: 1);

    // Reloaded, so the relation reflects the reservation rather than a copy
    // cached before it was taken.
    $reloaded = Auction::with('product')->findOrFail($auction->id);

    expect($reloaded->product->availableStock())->toBe(0)
        ->and($reloaded->product->stock_on_hand)->toBe(1)
        ->and($this->pricer->quote($reloaded, bidder())->available)->toBeTrue();
});
