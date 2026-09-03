<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What happens when a winner does not complete checkout before the deadline.
 *
 * The behaviour itself belongs to the settlement stage; this enum only fixes
 * the vocabulary so the decision is configuration rather than a constant
 * buried in a service. New policies can be added without touching call sites
 * that only store and display the value.
 */
enum ForfeitPolicy: string
{
    /** The win lapses. The product is not re-offered automatically. */
    case Forfeit = 'forfeit';

    /** The win lapses and the product returns to auction. */
    case Relist = 'relist';

    /** The win lapses and the credits spent by the winner are returned. */
    case RefundCredits = 'refund_credits';

    /** The win lapses and is offered to the next eligible leader. */
    case OfferRunnerUp = 'offer_runner_up';

    public function label(): string
    {
        return match ($this) {
            self::Forfeit => 'Forfeit',
            self::Relist => 'Relist the product',
            self::RefundCredits => 'Refund the winner credits',
            self::OfferRunnerUp => 'Offer to the runner-up',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Forfeit => 'The win lapses and the product is not re-offered automatically.',
            self::Relist => 'The win lapses and the product is returned to auction.',
            self::RefundCredits => 'The win lapses and the credits the winner spent are returned to them.',
            self::OfferRunnerUp => 'The win lapses and is offered to the next eligible leader.',
        };
    }
}
