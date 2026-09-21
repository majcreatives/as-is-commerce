<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CreateRulesetVersion;
use App\Enums\BidModel;
use App\Models\AuctionRuleset;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Which bidding model an auction follows, and where that is recorded.
 *
 * Nothing here changes how anybody bids. Every existing ruleset and every
 * existing auction is single-highest and stays so; this holds that the model is
 * RECORDED -- on the ruleset, and frozen into each auction's snapshot -- and
 * that the two new columns exist with the guarantees they were given.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();
});

// -------------------------------------------------------------- The ruleset

it('produces single-highest auctions unless a ruleset says otherwise', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->create();

    expect($ruleset->fresh()->bid_model)->toBe(BidModel::SingleHighest)
        ->and($ruleset->fresh()->toRules()->bidModel)->toBe(BidModel::SingleHighest);
});

it('moves a ruleset to the cumulative model when a new version is drafted', function (): void {
    // The deliberate step by which an older ruleset adopts the new rules. The
    // version it was copied from is untouched and keeps producing what it did.
    $source = AuctionRuleset::factory()->active()->withBidRules(minimum: 5, increment: 3)
        ->create(['allow_bid_increase' => true]);

    $draft = app(CreateRulesetVersion::class)->handle($source)->fresh();

    expect($draft->bid_model)->toBe(BidModel::CumulativeStep)
        // The earlier rule's fields do not carry over: they have no meaning
        // under the new model, and the database refuses them beside it.
        ->and($draft->minimum_bid_increment_credits)->toBeNull()
        ->and($draft->allow_bid_increase)->toBeNull()
        // The opening bid carries over. The step is NOT invented: it stays
        // empty, so the draft cannot be activated until somebody chooses one.
        ->and($draft->minimum_bid_credits)->toBe(5)
        ->and($draft->bid_increment_credits)->toBeNull();

    expect($source->fresh()->bid_model)->toBe(BidModel::SingleHighest)
        ->and($source->fresh()->minimum_bid_increment_credits)->toBe(3);
});

it('carries a cumulative ruleset\'s step into its next version', function (): void {
    $source = AuctionRuleset::factory()->active()->cumulative(minimum: 5, increment: 2)->create();

    $draft = app(CreateRulesetVersion::class)->handle($source)->fresh();

    expect($draft->bid_model)->toBe(BidModel::CumulativeStep)
        ->and($draft->minimum_bid_credits)->toBe(5)
        ->and($draft->bid_increment_credits)->toBe(2);
});

it('cannot have its model set through mass assignment', function (): void {
    // Which model a ruleset produces is chosen by code that means it. A
    // crafted request must not be able to flip a ruleset into producing a
    // different kind of auction by adding a field to a payload.
    //
    // The model does not quietly ignore the field, it refuses it outright.
    expect(fn () => new AuctionRuleset(['name' => 'Crafted', 'bid_model' => 'cumulative_step']))
        ->toThrow(MassAssignmentException::class);

    // And a ruleset built without one carries the default, so it can be read
    // back without an attribute that was never loaded.
    expect((new AuctionRuleset(['name' => 'Plain']))->bid_model)->toBe(BidModel::SingleHighest);
});

it('refuses a model the database does not know about', function (): void {
    $ruleset = AuctionRuleset::factory()->create();

    expect(fn () => DB::table('auction_rulesets')
        ->where('id', $ruleset->id)
        ->update(['bid_model' => 'nonsense']))
        ->toThrow(QueryException::class);
});

// ------------------------------------------------------ Frozen into the auction

it('freezes the model into every auction it creates', function (): void {
    $auction = liveAuction();

    expect($auction->rules_snapshot['rules']['bid_model'])->toBe('single_highest')
        ->and($auction->rules()->bidModel)->toBe(BidModel::SingleHighest)
        ->and($auction->winnerRule())->toBe('highest_valid_credit_bid')
        // The label written beside it is the one the model derives.
        ->and($auction->rules_snapshot['rules']['winner_rule'])->toBe('highest_valid_credit_bid');
});

it('does not let editing the ruleset afterwards change an auction that already exists', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->create();
    $auction = liveAuction(ruleset: $ruleset);

    // Whatever happens to the ruleset row -- even a change made by hand --
    // the auction reads the model from its own snapshot. (The values a
    // complete cumulative ruleset needs go with it: the database refuses an
    // active one that lacks them.)
    DB::table('auction_rulesets')->where('id', $ruleset->id)->update([
        'bid_model' => 'cumulative_step',
        'minimum_bid_credits' => 1,
        'bid_increment_credits' => 1,
    ]);

    expect($auction->fresh()->rules()->bidModel)->toBe(BidModel::SingleHighest);
});

// -------------------------------------------------------------- The new columns

it('leaves the standing empty on a bid placed under the single-highest model', function (): void {
    // That model ranks by `amount_credits`. A running total is a fact nobody
    // needed under it, and writing one would record something the rules never
    // used.
    $auction = liveAuction();
    $bid = placeBid($auction, bidder(500), 25);

    expect($bid->fresh()->amount_credits)->toBe(25)
        ->and($bid->fresh()->cumulative_credits)->toBeNull();
});

it('has the standing index the cumulative model will rank with', function (): void {
    $index = DB::select("SHOW INDEX FROM bids WHERE Key_name = 'bids_standing_index'");

    expect(collect($index)->pluck('Column_name')->all())
        ->toBe(['auction_id', 'cumulative_credits', 'sequence']);
});

it('states that a standing can never be less than the bid that produced it', function (): void {
    // Checked by asking the schema, not by inserting an impossible row: a bid
    // cannot be written without the credit consumption that paid for it, and a
    // test that conjured one would be manufacturing exactly the state the
    // engine guarantees cannot exist.
    $constraint = DB::selectOne(
        'SELECT CHECK_CLAUSE AS clause FROM information_schema.CHECK_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?',
        ['chk_bids_standing_covers_bid'],
    );

    expect($constraint)->not->toBeNull()
        ->and((string) $constraint->clause)->toContain('cumulative_credits')
        ->and((string) $constraint->clause)->toContain('amount_credits');
});
