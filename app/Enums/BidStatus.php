<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The state of a recorded bid.
 *
 * There is exactly one case, and that is a statement about the model rather
 * than an oversight.
 *
 * A REJECTED BID HAS NO ROW. Validation happens before anything is written,
 * and the credit consumption and the bid row share one transaction. A bid
 * that fails any check -- too small, too soon, wrong state, not enough
 * credits -- rolls the whole thing back, so nothing is recorded and no
 * credits move. Rejections are audited in the activity log, where they belong;
 * writing them into the bid table would put invalid bids in the same place as
 * valid ones, which is precisely where a highest-bid query must not have to
 * be careful.
 *
 * NOTHING VOIDS A BID. Credits committed to a bid are permanently consumed,
 * for the winner and every loser alike; there is no refund. A "voided" state
 * would imply credits coming back, which the business model does not do. If
 * that ever changes it will be a deliberate decision with its own compensating
 * ledger entries, not a status flipped on an existing row.
 *
 * The column exists because a bid's validity is worth stating rather than
 * implying, and because the highest-bid query filters on it -- so any future
 * state is excluded from winner selection by construction rather than by
 * everyone remembering to.
 */
enum BidStatus: string
{
    /** Valid, paid for, and eligible to win. */
    case Accepted = 'accepted';

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Accepted',
        };
    }

    /**
     * Whether a bid in this state can win the auction.
     *
     * A match rather than a comparison, deliberately: with one case a
     * comparison is trivially true, whereas a match makes a future case a
     * compile-time decision here rather than a silent inclusion in the
     * winner query.
     */
    public function countsTowardsWinning(): bool
    {
        return match ($this) {
            self::Accepted => true,
        };
    }

    /**
     * Whether the credits behind a bid in this state count towards the
     * Buy Now discount.
     */
    public function countsTowardsDiscount(): bool
    {
        return match ($this) {
            self::Accepted => true,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
