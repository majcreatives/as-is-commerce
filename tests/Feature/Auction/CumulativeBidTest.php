<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\ActivateRuleset;
use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Auction\Exceptions\BidRejected;
use App\Domain\Auction\Exceptions\InvalidAuctionRules;
use App\Domain\Auction\Services\BidValidator;
use App\Domain\Auction\Services\HighestBidResolver;
use App\Domain\Credit\ValueObjects\CreditAmount;
use App\Enums\AuctionStatus;
use App\Enums\BidModel;
use App\Enums\RulesetStatus;
use App\Models\AuctionRuleset;
use App\Models\Bid;
use App\Models\CreditTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The cumulative bidding model: a bidder's position is the total credits they
 * have consumed on the auction, and the SERVER works out the one bid that
 * lands them exactly one step ahead of the leader.
 *
 * The worked example below is the business owner's own, and every figure in it
 * is theirs: A opens with 1; B must bid 2; A must bid 2 to reach 3; C must bid
 * 4; B must bid 3 to reach 5. If any test here disagrees with it, the test is
 * wrong.
 *
 * The rules being held, beyond "it computes the right number":
 *
 *   the bidder never chooses the amount, and the server refuses any other
 *   a leader has no bid to place until somebody overtakes them
 *   a refused bid consumes nothing and leaves no trace
 *   the largest TOTAL wins, whatever the largest single bid was
 *   nothing that decides a winner is trusted from the browser
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    $this->bids = app(HighestBidResolver::class);
    $this->validator = app(BidValidator::class);
});

// ----------------------------------------------------- The owner's example

it('follows the owner\'s worked example, figure for figure', function (): void {
    $auction = cumulativeAuction(minimum: 1, increment: 1);
    $a = bidder(500);
    $b = bidder(500);
    $c = bidder(500);

    $placed = [
        catchUp($auction, $a),  // A opens with 1
        catchUp($auction, $b),  // B can only bid 2: A leads by 1
        catchUp($auction, $a),  // A can only bid 2 to be on top: 1 + 2 = 3
        catchUp($auction, $c),  // C has to bid straight 4 to take part
        catchUp($auction, $b),  // B needs 3 more: 2 + 3 = 5
    ];

    // What each bid CONSUMED, and where it left its bidder.
    expect(collect($placed)->map->amount_credits->all())->toBe([1, 2, 2, 4, 3])
        ->and(collect($placed)->map->cumulative_credits->all())->toBe([1, 2, 3, 4, 5]);

    // B leads with 5. The projection, which listing pages read, says the same.
    expect($this->bids->highestBid($auction)->user_id)->toBe($b->id)
        ->and($this->bids->highestAmount($auction))->toBe(5)
        ->and($auction->fresh()->highest_bid_credits)->toBe(5);

    // Credits are consumed exactly as bid: 3 + 5 + 4 = 12.
    expect($this->bids->consumedCreditsBy($auction, $a->id))->toBe(3)
        ->and($this->bids->consumedCreditsBy($auction, $b->id))->toBe(5)
        ->and($this->bids->consumedCreditsBy($auction, $c->id))->toBe(4)
        ->and(creditWalletFor($a)->fresh()->balance)->toBe(497)
        ->and(creditWalletFor($b)->fresh()->balance)->toBe(495)
        ->and(creditWalletFor($c)->fresh()->balance)->toBe(496);
});

it('follows a larger step from a higher opening bid', function (): void {
    // Minimum 5, step 2: the standing goes 5, 7, 9, 11.
    $auction = cumulativeAuction(minimum: 5, increment: 2);
    $a = bidder(500);
    $b = bidder(500);

    $placed = [
        catchUp($auction, $a),  // 5
        catchUp($auction, $b),  // 5 + 2 - 0 = 7
        catchUp($auction, $a),  // 7 + 2 - 5 = 4  -> 9
        catchUp($auction, $b),  // 9 + 2 - 7 = 4  -> 11
    ];

    expect(collect($placed)->map->amount_credits->all())->toBe([5, 7, 4, 4])
        ->and(collect($placed)->map->cumulative_credits->all())->toBe([5, 7, 9, 11]);
});

// -------------------------------------------- Only one amount is valid

it('opens with exactly the minimum bid and nothing else', function (): void {
    $auction = cumulativeAuction(minimum: 5, increment: 2);
    $bidder = bidder(500);

    foreach ([1, 4, 6, 7, 100] as $wrong) {
        expect(fn () => placeBid($auction, $bidder, $wrong))
            ->toThrow(BidRejected::class, 'opening bid on this auction is exactly 5 credits');
    }

    expect(Bid::query()->count())->toBe(0)
        ->and(creditWalletFor($bidder)->fresh()->balance)->toBe(500);

    placeBid($auction, $bidder, 5);

    expect($this->bids->highestAmount($auction))->toBe(5);
});

it('accepts only the next step over the leader, and refuses every other amount', function (): void {
    // The owner's own list: with the leader on 20, only 21 is valid. 22, 30,
    // 100, 20 and 2 are all refused.
    $auction = cumulativeAuction(minimum: 20, increment: 1);
    $leader = bidder(500);
    $challenger = bidder(500);

    placeBid($auction, $leader, 20);
    $bidsBefore = Bid::query()->count();

    foreach ([20, 22, 30, 100, 2] as $wrong) {
        expect(fn () => placeBid($auction, $challenger, $wrong))
            ->toThrow(BidRejected::class, 'you need to add exactly 21 credits');
    }

    // A refused bid leaves nothing: no row, no credits moved.
    expect(Bid::query()->count())->toBe($bidsBefore)
        ->and(creditWalletFor($challenger)->fresh()->balance)->toBe(500)
        ->and($this->bids->consumedCreditsBy($auction, $challenger->id))->toBe(0);

    placeBid($auction, $challenger, 21);

    expect($this->bids->highestAmount($auction))->toBe(21)
        ->and($this->bids->highestBid($auction)->user_id)->toBe($challenger->id);
});

it('measures the catch-up from the bidder\'s own total, not from zero', function (): void {
    $auction = cumulativeAuction();
    $a = bidder(500);
    $b = bidder(500);

    catchUp($auction, $a);  // A = 1
    catchUp($auction, $b);  // B = 2

    // A has 1 and the leader has 2: 2 + 1 - 1 = 2. Not 3, which is what a
    // newcomer would need.
    expect($this->validator->nextBid($auction, $a))->toBe(2)
        ->and($this->validator->nextBid($auction, bidder(500)))->toBe(3);

    expect(fn () => placeBid($auction, $a, 3))->toThrow(BidRejected::class, 'add exactly 2 credits');
});

// --------------------------------------------------------- The leader

it('gives a leader no bid to place until somebody overtakes them', function (): void {
    $auction = cumulativeAuction();
    $leader = bidder(500);

    catchUp($auction, $leader);

    expect($this->validator->nextBid($auction, $leader))->toBeNull();

    foreach ([1, 2, 3] as $amount) {
        expect(fn () => placeBid($auction, $leader, $amount))
            ->toThrow(BidRejected::class, 'already hold the lead with 1 credits');
    }

    // Once overtaken, they can bid again.
    catchUp($auction, bidder(500));

    expect($this->validator->nextBid($auction, $leader))->toBe(2);
});

// ---------------------------------------------- Stale pages, and tampering

it('refuses a bid that stopped being the catch-up bid while the page was open', function (): void {
    $auction = cumulativeAuction();
    $a = bidder(500);
    $b = bidder(500);
    $c = bidder(500);

    catchUp($auction, $a);                                 // A = 1

    // B is shown 2 and considers it.
    $shownToB = $this->validator->nextBid($auction, $b);
    expect($shownToB)->toBe(2);

    // C bids first and takes the lead on 2.
    placeBid($auction, $c, 2);

    $before = creditWalletFor($b)->fresh()->balance;

    // B's confirmation arrives. The right bid is now 3, and B is NOT quietly
    // given it: that would spend more than they agreed to.
    expect(fn () => placeBid($auction, $b, $shownToB))
        ->toThrow(BidRejected::class, 'add exactly 3 credits (the leader has 2 and you have 0)');

    expect(creditWalletFor($b)->fresh()->balance)->toBe($before)
        ->and($this->bids->consumedCreditsBy($auction, $b->id))->toBe(0);

    // The retry with the figure the message named goes through.
    placeBid($auction, $b, 3);

    expect($this->bids->highestAmount($auction))->toBe(3);
});

it('does not take a bid it has already accepted twice', function (): void {
    // The same idempotency key, delivered twice: one bid, one consumption.
    $auction = cumulativeAuction();
    $bidder = bidder(500);

    $first = placeBid($auction, $bidder, 1, 'the-same-key');
    $again = placeBid($auction, $bidder, 1, 'the-same-key');

    expect($again->id)->toBe($first->id)
        ->and(Bid::query()->count())->toBe(1)
        ->and(creditWalletFor($bidder)->fresh()->balance)->toBe(499);
});

// ------------------------------------------------------------- Affordability

it('refuses a bid the bidder cannot afford and consumes nothing', function (): void {
    $auction = cumulativeAuction();
    $a = bidder(500);
    $b = bidder(500);
    $poor = bidder(3);

    catchUp($auction, $a);   // A = 1
    catchUp($auction, $b);   // B = 2
    catchUp($auction, $a);   // A = 3

    // The newcomer needs 3 + 1 = 4 and holds 3.
    expect($this->validator->nextBid($auction, $poor))->toBe(4);

    expect(fn () => placeBid($auction, $poor, 4))
        ->toThrow(BidRejected::class, 'needs 4 credits and your balance is 3');

    expect(creditWalletFor($poor)->fresh()->balance)->toBe(3)
        ->and($this->bids->consumedCreditsBy($auction, $poor->id))->toBe(0);
});

// ----------------------------------------------------- What decides a winner

it('is won by the largest total, not the largest single bid', function (): void {
    // The twin of the single-highest test that says the opposite. Here C places
    // the largest single bid of all (4), and B wins with two smaller ones
    // (2 and 3) that total 5. Minimum and step scaled by the redenomination
    // factor -- the engine's arithmetic is scale-invariant, so the sequence of
    // catch-up amounts is unchanged, and the closing note (a formatted,
    // customer-facing figure) reads "5" only if the stored total is 5 * factor.
    $factor = CreditAmount::SUBCREDITS_PER_CREDIT;
    $auction = cumulativeAuction($factor, $factor);
    $a = bidder(500 * $factor);
    $b = bidder(500 * $factor);
    $c = bidder(500 * $factor);

    catchUp($auction, $a);
    catchUp($auction, $b);
    catchUp($auction, $a);
    $cBid = catchUp($auction, $c);
    $bLast = catchUp($auction, $b);

    expect(Bid::query()->where('auction_id', $auction->id)->max('amount_credits'))->toBe(4 * $factor)
        ->and($cBid->amount_credits)->toBe(4 * $factor);

    $closed = app(CloseAuction::class)->handle($auction->fresh(), force: true);

    expect($closed->status)->toBe(AuctionStatus::PendingSettlement)
        ->and($closed->winner_user_id)->toBe($b->id)
        ->and($closed->winning_bid_id)->toBe($bLast->id)
        ->and($closed->winnerRule())->toBe('highest_cumulative_credits');

    // The history says what actually decided it.
    $note = $closed->transitions()->where('to_status', AuctionStatus::PendingSettlement)->value('reason');

    expect($note)->toContain('highest total of 5 credits committed');
});

it('records the winner\'s total, not their last bid, on the settlement', function (): void {
    $auction = cumulativeAuction();
    $a = bidder(500);
    $b = bidder(500);

    catchUp($auction, $a);
    catchUp($auction, $b);
    catchUp($auction, $a);   // A = 3, having bid 1 then 2

    $closed = app(CloseAuction::class)->handle($auction->fresh(), force: true);

    // A last bid 2, but what they won with is 3.
    expect($closed->winner_user_id)->toBe($a->id)
        ->and($closed->settlementOrder->pricing()->winningBidCredits)->toBe(3);
});

it('issues Store Wallet value for what the losers consumed, and none to the winner', function (): void {
    // The machinery is unchanged: it values credits consumed, from the lots
    // they came from. Under this model what a loser consumed is their total.
    $a = customerWithPurchasedCredits(500, 4_500);   // 9 pesewas a credit
    $b = customerWithPurchasedCredits(500, 4_500);
    $c = customerWithPurchasedCredits(500, 4_500);

    $auction = cumulativeAuction();

    catchUp($auction, $a);   // A consumes 1
    catchUp($auction, $b);   // B consumes 2
    catchUp($auction, $a);   // A consumes 2  -> 3 in total
    catchUp($auction, $c);   // C consumes 4
    catchUp($auction, $b);   // B consumes 3  -> 5 in total

    app(CloseAuction::class)->handle($auction->fresh(), force: true);

    expect(storeWalletBalance($a)->minor)->toBe(27)   // 3 credits x 9p
        ->and(storeWalletBalance($c)->minor)->toBe(36) // 4 credits x 9p
        ->and(storeWalletBalance($b)->minor)->toBe(0); // the winner
});

// ------------------------------------------------------------ The invariants

it('keeps every bidder\'s total equal to the credits they consumed', function (): void {
    $auction = cumulativeAuction();
    $users = [bidder(500), bidder(500), bidder(500)];

    foreach (range(1, 4) as $round) {
        foreach ($users as $user) {
            if ($this->validator->nextBid($auction, $user) !== null) {
                catchUp($auction, $user);
            }
        }
    }

    foreach ($users as $user) {
        $last = Bid::query()
            ->where('auction_id', $auction->id)
            ->where('user_id', $user->id)
            ->orderByDesc('sequence')
            ->first();

        // Where their last bid left them, what they consumed, and what the Buy
        // Now discount is computed from: all the same number.
        expect($last->cumulative_credits)
            ->toBe($this->bids->standingOf($auction, $user->id))
            ->toBe($this->bids->consumedCreditsBy($auction, $user->id));
    }

    $report = $this->bids->verify($auction->fresh());

    expect($report['matches'])->toBeTrue()
        ->and($report['standing_problems'])->toBe(0);
});

it('never lets two bidders share a total', function (): void {
    $auction = cumulativeAuction();
    $users = [bidder(500), bidder(500), bidder(500)];

    foreach (range(1, 5) as $ignored) {
        foreach ($users as $user) {
            if ($this->validator->nextBid($auction, $user) !== null) {
                catchUp($auction, $user);
            }
        }
    }

    $standings = Bid::query()->where('auction_id', $auction->id)->pluck('cumulative_credits');

    // Every bid put its bidder strictly above everybody, so no total repeats.
    expect($standings->unique()->count())->toBe($standings->count());
});

it('writes where the credits left the bidder on the credit ledger too', function (): void {
    $auction = cumulativeAuction();
    $bidder = bidder(500);

    catchUp($auction, bidder(500));
    $bid = catchUp($auction, $bidder);    // 1 + 1 - 0 = 2

    $transaction = CreditTransaction::query()->findOrFail($bid->credit_transaction_id);

    // An auditor reading the ledger alone can see the position.
    expect($transaction->metadata['standing_credits'])->toBe(2)
        ->and($transaction->metadata['auction_id'])->toBe($auction->id);
});

it('shows a visitor who is not signed in the cost of joining', function (): void {
    $auction = cumulativeAuction();

    // Nobody has bid: the opening bid.
    expect($this->validator->nextBid($auction, null))->toBe(1);

    catchUp($auction, bidder(500));
    catchUp($auction, bidder(500));

    // The leader stands on 2, so a newcomer would have to add 3.
    expect($this->validator->nextBid($auction->fresh(), null))->toBe(3);
});

it('offers no catch-up bid on a single-highest auction', function (): void {
    $auction = liveAuction();

    expect($this->validator->nextBid($auction, bidder(500)))->toBeNull()
        ->and($this->validator->smallestValidBid(cumulativeAuction()))->toBeNull();
});

// ------------------------------------------------ A ruleset must be complete

it('will not activate a cumulative ruleset that has no step', function (): void {
    $ruleset = AuctionRuleset::factory()->create([
        'bid_model' => BidModel::CumulativeStep,
        'minimum_bid_credits' => 1,
        'bid_increment_credits' => null,
    ]);

    expect(fn () => app(ActivateRuleset::class)->handle($ruleset))
        ->toThrow(InvalidAuctionRules::class, 'needs a bid increment');

    expect($ruleset->fresh()->status)->toBe(RulesetStatus::Draft);
});

it('will not activate a cumulative ruleset that has no opening bid', function (): void {
    $ruleset = AuctionRuleset::factory()->create([
        'bid_model' => BidModel::CumulativeStep,
        'minimum_bid_credits' => null,
        'bid_increment_credits' => 1,
    ]);

    expect(fn () => app(ActivateRuleset::class)->handle($ruleset))
        ->toThrow(InvalidAuctionRules::class, 'needs a minimum bid');
});

it('activates a complete cumulative ruleset', function (): void {
    $ruleset = AuctionRuleset::factory()->cumulative(minimum: 1, increment: 2)->create();

    $active = app(ActivateRuleset::class)->handle($ruleset);

    expect($active->status)->toBe(RulesetStatus::Active)
        ->and($active->toRules()->bidModel)->toBe(BidModel::CumulativeStep);
});

it('has the database refuse an active cumulative ruleset that is incomplete', function (): void {
    $draft = AuctionRuleset::factory()->create([
        'bid_model' => BidModel::CumulativeStep,
        'minimum_bid_credits' => 1,
        'bid_increment_credits' => null,
    ]);

    expect(fn () => DB::table('auction_rulesets')->where('id', $draft->id)->update(['status' => 'active']))
        ->toThrow(QueryException::class);
});

it('has the database refuse the two models\' fields appearing together', function (): void {
    $cumulative = AuctionRuleset::factory()->cumulative()->create();
    $single = AuctionRuleset::factory()->create();

    // A lower-bound increment beside an exact step.
    expect(fn () => DB::table('auction_rulesets')->where('id', $cumulative->id)
        ->update(['minimum_bid_increment_credits' => 5]))->toThrow(QueryException::class);

    // An option to raise your own bid, on a model where a leader cannot bid.
    expect(fn () => DB::table('auction_rulesets')->where('id', $cumulative->id)
        ->update(['allow_bid_increase' => 1]))->toThrow(QueryException::class);

    // An exact step on a model that ignores it.
    expect(fn () => DB::table('auction_rulesets')->where('id', $single->id)
        ->update(['bid_increment_credits' => 3]))->toThrow(QueryException::class);

    // And a step is at least one credit.
    expect(fn () => DB::table('auction_rulesets')->where('id', $cumulative->id)
        ->update(['bid_increment_credits' => 0]))->toThrow(QueryException::class);
});

it('freezes the step into the auction, so editing the ruleset changes nothing', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()->cumulative(minimum: 1, increment: 3)->create();
    $auction = liveAuction(ruleset: $ruleset);

    DB::table('auction_rulesets')->where('id', $ruleset->id)->update(['bid_increment_credits' => 9]);

    $rules = $auction->fresh()->rules();

    expect($rules->bidIncrementCredits)->toBe(3)
        ->and($auction->fresh()->rules_snapshot['rules']['bid_increment_credits'])->toBe(3)
        ->and($rules->bidModel)->toBe(BidModel::CumulativeStep);
});
