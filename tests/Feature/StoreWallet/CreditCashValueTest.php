<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Auction\Services\BuyNowPricer;
use App\Domain\StoreWallet\Services\AuctionLossCompensator;
use App\Domain\StoreWallet\Services\ConsumedCreditValuation;
use App\Domain\StoreWallet\Services\StoreWalletLedgerService;
use App\Enums\CreditTransactionType;
use App\Enums\StoreWalletTransactionType;
use App\Models\Product;
use App\Models\StoreWalletCreditSource;
use App\Models\StoreWalletTransaction;

/*
 * The economic model: what a consumed credit is worth, and where that value
 * goes.
 *
 * Every figure asserted here is derived from a credit lot's own frozen
 * acquisition economics. Nothing in this file relies on a configured rate,
 * because there no longer is one.
 */

beforeEach(function (): void {
    $this->valuation = app(ConsumedCreditValuation::class);
    $this->wallets = app(StoreWalletLedgerService::class);
    $this->pricer = app(BuyNowPricer::class);
    $this->close = app(CloseAuction::class);
});

// ------------------------------------------------- The required test cases

/*
 * Case 1 from the brief: 100 credits for GH 10, 30 consumed, user loses.
 */
it('issues the cash value of consumed purchased credits to a losing bidder', function (): void {
    $auction = liveAuction();

    $loser = customerWithPurchasedCredits(100, 1_000);   // GH 10.00 / 100
    $winner = bidder(1_000);

    placeBid($auction, $loser, 30);
    placeBid($auction, $winner, 500);

    $this->close->handle($auction->fresh(), force: true);

    // 30 x GH 0.10
    expect(storeWalletBalance($loser)->toDecimalString())->toBe('3.00');
});

/*
 * Case 2: 500 credits for GH 40, 100 consumed, user loses.
 */
it('values a cheaper lot at its own rate', function (): void {
    $auction = liveAuction();

    $loser = customerWithPurchasedCredits(500, 4_000);   // GH 40.00 / 500
    $winner = bidder(1_000);

    placeBid($auction, $loser, 100);
    placeBid($auction, $winner, 500);

    $this->close->handle($auction->fresh(), force: true);

    // 100 x GH 0.08
    expect(storeWalletBalance($loser)->toDecimalString())->toBe('8.00');
});

/*
 * Case 3: purchased and referral credits together. Only the purchased portion
 * has ever cost anybody anything.
 */
it('gives referral credits no cash value', function (): void {
    $auction = liveAuction();

    $loser = customerWithPurchasedCredits(100, 1_000);   // GH 0.10 each
    grantCredits($loser, 50, CreditTransactionType::ReferralCredit);

    $winner = bidder(1_000);

    // 150 credits consumed. The allocator spends referral credits first, so
    // this draws all 50 free ones and 100 purchased ones.
    placeBid($auction, $loser, 150);
    placeBid($auction, $winner, 500);

    $this->close->handle($auction->fresh(), force: true);

    expect(storeWalletBalance($loser)->toDecimalString())->toBe('10.00');

    $issuance = StoreWalletTransaction::where('idempotency_key', "auction-loss:{$auction->id}:{$loser->id}")
        ->firstOrFail();

    $sources = $issuance->creditSources()->get();

    // Both lots are recorded, including the one worth nothing: a breakdown
    // that dropped it could not explain why 150 credits produced the value of
    // 100.
    expect($sources)->toHaveCount(2)
        ->and((int) $sources->sum('credits'))->toBe(150)
        ->and((int) $sources->sum('amount_minor'))->toBe(1_000);
});

/*
 * Case 4: the worked mixed-lot example from the brief.
 *
 *     20 purchased at GH 0.10  =  GH 2.00
 *     10 purchased at GH 0.08  =  GH 0.80
 *     15 referral                 GH 0.00
 *                                 -------
 *                                 GH 2.80
 */
it('values each lot at its own rate rather than at a blended one', function (): void {
    $auction = liveAuction();

    $loser = customerWithPurchasedCredits(100, 1_000);   // lot A: GH 0.10
    grantPurchasedCredits($loser, 500, 4_000);           // lot B: GH 0.08
    grantCredits($loser, 15, CreditTransactionType::ReferralCredit);

    $winner = bidder(1_000);

    // 45 credits: the 15 free ones first, then lot A, then lot B. Bidding in
    // two goes so the draw crosses the lot boundary the way a real bidder's
    // would.
    placeBid($auction, $loser, 35);
    placeBid($auction, $loser, 10);
    placeBid($auction, $winner, 500);

    $this->close->handle($auction->fresh(), force: true);

    expect(storeWalletBalance($loser)->toDecimalString())->toBe('2.80');
});

/*
 * Case 5: winning is not losing.
 */
it('issues nothing to the auction winner', function (): void {
    $auction = liveAuction();

    $winner = customerWithPurchasedCredits(100, 1_000);
    $loser = customerWithPurchasedCredits(100, 1_000);

    placeBid($auction, $loser, 30);
    placeBid($auction, $winner, 90);

    $this->close->handle($auction->fresh(), force: true);

    expect(storeWalletBalance($winner)->isZero())->toBeTrue()
        ->and(storeWalletBalance($loser)->toDecimalString())->toBe('3.00');
});

/*
 * Case 6: an active-auction Buy Now is reduced by what the credits cost, not
 * by a cedi apiece.
 */
it('reduces Buy Now by the actual cash value of consumed credits', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    $buyer = customerWithPurchasedCredits(500, 4_000);   // GH 0.08 each

    placeBid($auction, $buyer, 100);

    $quote = $this->pricer->quote($auction->fresh(), $buyer);

    // 100 x GH 0.08 = GH 8.00 off GH 5,500.00
    expect($quote->eligibleCredits)->toBe(100)
        ->and($quote->discount->toDecimalString())->toBe('8.00')
        ->and($quote->payable->toDecimalString())->toBe('5492.00');
});

/*
 * Case 7: the same value is never handed over twice.
 */
it('issues no Store Wallet value to a Buy Now buyer', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    $buyer = customerWithPurchasedCredits(500, 4_000);
    $loser = customerWithPurchasedCredits(100, 1_000);

    placeBid($auction, $buyer, 100);
    placeBid($auction, $loser, 30);

    completeBuyNow($auction->fresh(), $buyer);

    expect(storeWalletBalance($buyer)->isZero())->toBeTrue()
        // The other bidder still lost, and the product is still gone.
        ->and(storeWalletBalance($loser)->toDecimalString())->toBe('3.00');
});

/*
 * Case 8: everyone who bid and did not get the product is compensated once.
 */
it('issues to every losing bidder exactly once', function (): void {
    $auction = liveAuction();

    $first = customerWithPurchasedCredits(100, 1_000);
    $second = customerWithPurchasedCredits(500, 4_000);
    $winner = bidder(1_000);

    placeBid($auction, $first, 30);
    placeBid($auction, $second, 100);
    placeBid($auction, $winner, 500);

    $this->close->handle($auction->fresh(), force: true);

    expect(storeWalletBalance($first)->toDecimalString())->toBe('3.00')
        ->and(storeWalletBalance($second)->toDecimalString())->toBe('8.00')
        ->and(StoreWalletTransaction::ofType(StoreWalletTransactionType::AuctionLossCompensation)->count())
        ->toBe(2);
});

/*
 * Case 9: closing is idempotent, and so is the issuance that follows from it.
 */
it('does not issue twice when a closure is re-run', function (): void {
    $auction = liveAuction();

    $loser = customerWithPurchasedCredits(100, 1_000);
    $winner = bidder(1_000);

    placeBid($auction, $loser, 30);
    placeBid($auction, $winner, 500);

    $this->close->handle($auction->fresh(), force: true);
    $this->close->handle($auction->fresh(), force: true);
    $this->close->handle($auction->fresh(), force: true);

    expect(storeWalletBalance($loser)->toDecimalString())->toBe('3.00')
        ->and(StoreWalletTransaction::ofType(StoreWalletTransactionType::AuctionLossCompensation)->count())
        ->toBe(1);
});

/*
 * The compensator itself, called directly and repeatedly. Closing guards this
 * already; the guarantee has to hold without that guard too, because a worker
 * retry or an administrator re-running a closure reaches it the same way.
 */
it('is idempotent when the compensator is called directly', function (): void {
    $auction = liveAuction();

    $loser = customerWithPurchasedCredits(100, 1_000);
    $winner = bidder(1_000);

    placeBid($auction, $loser, 30);
    placeBid($auction, $winner, 500);

    $closed = $this->close->handle($auction->fresh(), force: true);

    $compensator = app(AuctionLossCompensator::class);
    $compensator->compensateLosers($closed, $winner->id);
    $compensator->compensateLosers($closed, $winner->id);

    expect(storeWalletBalance($loser)->toDecimalString())->toBe('3.00')
        ->and(StoreWalletTransaction::where('idempotency_key', "auction-loss:{$auction->id}:{$loser->id}")->count())
        ->toBe(1);
});

// ------------------------------------------- What the value is, and is not

it('does not give bidding credits back', function (): void {
    $auction = liveAuction();

    $loser = customerWithPurchasedCredits(100, 1_000);
    $winner = bidder(1_000);

    placeBid($auction, $loser, 30);
    placeBid($auction, $winner, 500);

    $before = creditWalletFor($loser)->fresh()->balance;

    $this->close->handle($auction->fresh(), force: true);

    // The credits stay consumed. The Store Wallet value is a separate grant,
    // not a reversal of the bid.
    expect(creditWalletFor($loser)->fresh()->balance)->toBe($before)
        ->and($before)->toBe(70)
        ->and(storeWalletBalance($loser)->toDecimalString())->toBe('3.00');
});

it('issues nothing to a bidder who used only free credits', function (): void {
    $auction = liveAuction();

    $loser = userWithRole('customer');
    grantCredits($loser, 200, CreditTransactionType::PromotionalCredit);

    $winner = bidder(1_000);

    placeBid($auction, $loser, 50);
    placeBid($auction, $winner, 500);

    $this->close->handle($auction->fresh(), force: true);

    // Burned, exactly as before. A gift that came back as cash value would
    // make giving credits away an expensive thing for the platform to do.
    expect(storeWalletBalance($loser)->isZero())->toBeTrue()
        ->and(StoreWalletTransaction::count())->toBe(0);
});

it('ignores credits bought but never bid', function (): void {
    $auction = liveAuction();

    $loser = customerWithPurchasedCredits(1_000, 10_000);
    $winner = bidder(2_000);

    placeBid($auction, $loser, 10);
    placeBid($auction, $winner, 500);

    $this->close->handle($auction->fresh(), force: true);

    // 10 consumed, not the 990 still sitting in the wallet.
    expect(storeWalletBalance($loser)->toDecimalString())->toBe('1.00');
});

it('ignores credits consumed on a different auction', function (): void {
    $first = liveAuction();
    $second = liveAuction(product: Product::factory()->active()->create());

    $loser = customerWithPurchasedCredits(1_000, 10_000);
    $winner = bidder(2_000);

    placeBid($first, $loser, 40);
    placeBid($second, $loser, 500);
    placeBid($first, $winner, 900);

    $this->close->handle($first->fresh(), force: true);

    // Only the 40 spent on the auction that closed.
    expect(storeWalletBalance($loser)->toDecimalString())->toBe('4.00');
});

it('issues nothing when an auction closes with no bids', function (): void {
    $auction = liveAuction();

    $this->close->handle($auction->fresh(), force: true);

    expect(StoreWalletTransaction::count())->toBe(0);
});

// -------------------------------------------------------- The audit trail

it('records which lots produced an issuance and what each contributed', function (): void {
    $auction = liveAuction();

    $loser = customerWithPurchasedCredits(100, 1_000);
    $winner = bidder(1_000);

    placeBid($auction, $loser, 30);
    placeBid($auction, $winner, 500);

    $this->close->handle($auction->fresh(), force: true);

    $issuance = StoreWalletTransaction::ofType(StoreWalletTransactionType::AuctionLossCompensation)
        ->firstOrFail();

    expect($issuance->reference_type)->toBe(App\Models\Auction::class)
        ->and($issuance->reference_id)->toBe($auction->id)
        ->and($issuance->amount_minor)->toBe(300)
        ->and($issuance->balance_after_minor)->toBe(300);

    $source = $issuance->creditSources()->firstOrFail();

    // Enough to recompute the figure from this row alone.
    expect($source->credits)->toBe(30)
        ->and($source->lot_acquisition_amount_minor)->toBe(1_000)
        ->and($source->lot_original_amount)->toBe(100)
        ->and($source->amount_minor)->toBe(300)
        ->and($source->remainder_numerator)->toBe(0);
});

it('keeps the ledger and the projected balance in step', function (): void {
    $auction = liveAuction();

    $loser = customerWithPurchasedCredits(100, 1_000);
    $winner = bidder(1_000);

    placeBid($auction, $loser, 30);
    placeBid($auction, $winner, 500);

    $this->close->handle($auction->fresh(), force: true);

    $wallet = $this->wallets->walletFor($loser->fresh());

    expect($this->wallets->verify($wallet))
        ->toMatchArray(['matches' => true, 'projected_minor' => 300, 'ledger_minor' => 300]);
});

// ------------------------------------------------------------- Arithmetic

it('truncates a fractional pesewa rather than rounding it up', function (): void {
    $auction = liveAuction();

    // 300 credits for GH 25.00 is 8.333... pesewas each. Ten of them are
    // worth 83.33 pesewas, which has to become 83 -- never 84, which would be
    // more than the credits cost.
    $loser = customerWithPurchasedCredits(300, 2_500);
    $winner = bidder(1_000);

    placeBid($auction, $loser, 10);
    placeBid($auction, $winner, 500);

    $this->close->handle($auction->fresh(), force: true);

    expect(storeWalletBalance($loser)->minor)->toBe(83);

    $source = StoreWalletCreditSource::firstOrFail();

    // The discarded third of a pesewa is on the record rather than absorbed.
    expect($source->remainder_numerator)->toBe(100);
});

it('never values consumed credits above what the whole lot cost', function (): void {
    $auction = liveAuction();

    $loser = customerWithPurchasedCredits(300, 2_500);
    $winner = bidder(1_000);

    placeBid($auction, $loser, 300);
    placeBid($auction, $winner, 500);

    $this->close->handle($auction->fresh(), force: true);

    // The entire lot consumed comes back to exactly what was paid for it.
    expect(storeWalletBalance($loser)->minor)->toBe(2_500);
});

// --------------------------------------------------- The valuation service

it('values nothing for a user who never bid', function (): void {
    $auction = liveAuction();
    $stranger = bidder(1_000);

    expect($this->valuation->forAuction($auction, $stranger->id)->isZero())->toBeTrue();
});

it('does not mix one bidder into another bidder valuation', function (): void {
    $auction = liveAuction();

    $first = customerWithPurchasedCredits(100, 1_000);
    $second = customerWithPurchasedCredits(100, 1_000);

    placeBid($auction, $first, 30);
    placeBid($auction, $second, 70);

    expect($this->valuation->forAuction($auction, $first->id)->total->toDecimalString())->toBe('3.00')
        ->and($this->valuation->forAuction($auction, $second->id)->total->toDecimalString())->toBe('7.00');
});
