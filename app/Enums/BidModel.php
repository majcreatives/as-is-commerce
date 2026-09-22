<?php

declare(strict_types=1);

namespace App\Enums;

use App\Domain\Credit\ValueObjects\CreditAmount;

/**
 * Which bidding model an auction was created under, and therefore how it ranks
 * bidders, validates a bid, and names its winner.
 *
 * ONE DISCRIMINATOR, NOT TWO FIELDS. How a bidder is ranked and how a bid is
 * validated are decided together: a model that ranks by the largest single bid
 * validates bids against that bid, and one that ranks by a running total
 * validates them against the total. Recording them as separate values would
 * make an inconsistent pair expressible -- ranking by total while validating a
 * single amount -- and every reader would have to wonder which half was true.
 * One value derives both, so the pair cannot disagree.
 *
 * FROZEN INTO THE SNAPSHOT. An auction copies this when it is created and reads
 * it back from there, never from the ruleset it came from. Editing or
 * archiving a ruleset afterwards changes nothing about how a past auction is
 * ranked, which is what keeps a disputed result explainable.
 *
 * EVERY `match` ON THIS ENUM IS EXHAUSTIVE, on purpose. Adding a model forces
 * each decision that depends on it to be revisited rather than defaulting to
 * whichever behaviour happened to come first.
 */
enum BidModel: string
{
    /**
     * The original model. The largest single bid ranks first, and a bid is
     * validated against that bid: a minimum, an optional lower-bound increment
     * over the leader, and an optional bar on a leader raising their own bid.
     * The bidder chooses the amount.
     *
     * Every auction created before the cumulative model existed was made under
     * it, and it stays in the engine for exactly those auctions.
     */
    case SingleHighest = 'single_highest';

    /**
     * A bidder's position is the total credits they have consumed on the
     * auction. To overtake the leader a bidder must consume enough more to land
     * exactly one increment ahead, and the SERVER works out that amount -- the
     * bidder does not choose it.
     *
     *   opening bid     the minimum bid
     *   catch-up bid    leader's total + increment - your total
     *   winner          whoever holds the largest total when the auction closes
     *
     * A leader has no bid to place until somebody overtakes them, so no two
     * bidders can ever hold the same total.
     */
    case CumulativeStep = 'cumulative_step';

    /**
     * The name of the rule that picks this model's winner.
     *
     * Recorded in every snapshot as `winner_rule`, but DERIVED from the model
     * rather than stored independently, so a snapshot cannot say one thing in
     * `bid_model` and another in `winner_rule`.
     */
    public function winnerRule(): string
    {
        return match ($this) {
            self::SingleHighest => 'highest_valid_credit_bid',
            self::CumulativeStep => 'highest_cumulative_credits',
        };
    }

    public function isCumulative(): bool
    {
        return $this === self::CumulativeStep;
    }

    /**
     * The `bids` column that ranks bidders under this model.
     *
     * A fixed string chosen here, never taken from input, because it is
     * interpolated into an ORDER BY.
     */
    public function rankColumn(): string
    {
        return match ($this) {
            self::SingleHighest => 'amount_credits',
            self::CumulativeStep => 'cumulative_credits',
        };
    }

    /**
     * What staff see when reading an auction's rules.
     */
    public function label(): string
    {
        return match ($this) {
            self::SingleHighest => 'Single highest bid',
            self::CumulativeStep => 'Cumulative step',
        };
    }

    // ----------------------------------------------------- Customer wording
    //
    // Chosen per auction, from the model frozen into it, so no view hard-codes
    // a rule that is true of one model and false of the other. Credits are a
    // count throughout: never money, never a currency symbol.

    /**
     * The label above the figure that leads. The unit is in the label, so the
     * number beside it cannot be read as a price.
     *
     * "Highest Bid" is true only while a bid IS the figure that ranks. Under the
     * cumulative model a bid is the small amount that closes a gap, and the
     * figure that leads is a total; calling it a bid would put "Highest Bid: 22"
     * beside a history row that says 2.
     */
    public function leaderLabel(): string
    {
        return match ($this) {
            self::SingleHighest => 'Highest Bid (Credits)',
            self::CumulativeStep => 'Highest Total (Credits)',
        };
    }

    /**
     * The same figure inside a sentence: "The highest total is now 22 credits."
     */
    public function leaderNoun(): string
    {
        return match ($this) {
            self::SingleHighest => 'highest bid',
            self::CumulativeStep => 'highest total',
        };
    }

    /**
     * The one-line rule, as a customer is told it.
     */
    public function ruleSentence(): string
    {
        return match ($this) {
            self::SingleHighest => 'The highest valid credit bid wins when this auction closes.',
            self::CumulativeStep => 'The largest total of credits committed wins when this auction closes.',
        };
    }

    /**
     * How the result is stated to somebody reading it.
     */
    public function winnerSentence(int $credits): string
    {
        $amount = CreditAmount::fromSubcredits($credits)->format();

        return match ($this) {
            self::SingleHighest => "Won by the highest valid credit bid of {$amount}.",
            self::CumulativeStep => "Won with the highest total: {$amount} committed.",
        };
    }

    /**
     * The same result, told to the person who won it, on their checkout.
     */
    public function youWonSentence(int $credits): string
    {
        $amount = CreditAmount::fromSubcredits($credits)->format();

        return match ($this) {
            self::SingleHighest => "You won this auction with the highest valid credit bid of {$amount}.",
            self::CumulativeStep => "You won this auction with the highest total: {$amount} committed.",
        };
    }

    /**
     * What the winner held when it closed, told on their order.
     */
    public function youHeldSentence(int $credits): string
    {
        $amount = CreditAmount::fromSubcredits($credits)->format();

        return match ($this) {
            self::SingleHighest => "You held the highest valid credit bid of {$amount} when the auction closed.",
            self::CumulativeStep => "You held the highest total, {$amount} committed, when the auction closed.",
        };
    }

    /**
     * What the figure that won is called, for somebody who lost.
     */
    public function winningFigure(): string
    {
        return match ($this) {
            self::SingleHighest => 'winning bid',
            self::CumulativeStep => 'winning total',
        };
    }

    /**
     * The line written to the auction's history when it closes with a winner.
     *
     * Says what actually decided it. "The highest bid of 4 credits" would be
     * false under the cumulative model, where the winner's biggest single bid
     * may be smaller than somebody else's.
     */
    public function closingNote(int $credits): string
    {
        $amount = CreditAmount::fromSubcredits($credits)->format();

        return match ($this) {
            self::SingleHighest => "Closed on the clock. Won by the highest valid credit bid of {$amount}.",
            self::CumulativeStep => "Closed on the clock. Won with the highest total of {$amount} committed.",
        };
    }
}
