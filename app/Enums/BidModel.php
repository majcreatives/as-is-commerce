<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which bidding model an auction was created under, and therefore how it names
 * its winner.
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
 * THE SECOND CASE IS NOT HERE YET, DELIBERATELY. The cumulative model arrives
 * with the engine that can honour it. An enum case for a model nothing can
 * rank would let an auction be created that the resolver would quietly rank as
 * a single-highest one -- a wrong winner with no error. Until the engine
 * exists, a stored snapshot naming anything but this case is refused rather
 * than reinterpreted, and adding the case later forces every `match` on this
 * enum to be revisited.
 */
enum BidModel: string
{
    /**
     * The original model. The largest single bid ranks first, and a bid is
     * validated against that bid: a minimum, an optional lower-bound increment
     * over the leader, and an optional bar on a leader raising their own bid.
     *
     * Every auction created before this enum existed was made under it.
     */
    case SingleHighest = 'single_highest';

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
        };
    }

    /**
     * What staff see when reading an auction's rules.
     */
    public function label(): string
    {
        return match ($this) {
            self::SingleHighest => 'Single highest bid',
        };
    }
}
