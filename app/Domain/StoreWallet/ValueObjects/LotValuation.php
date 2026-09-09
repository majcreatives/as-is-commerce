<?php

declare(strict_types=1);

namespace App\Domain\StoreWallet\ValueObjects;

use App\Domain\Shared\Money\Money;
use App\Enums\CreditLotSource;
use App\Models\CreditLot;

/**
 * What one credit lot's consumed credits were actually worth in cash.
 *
 * One of these per lot a user drew from. The lot is named explicitly rather
 * than folded into a total, because the whole point of lot-based valuation is
 * that two lots bought at different prices are worth different amounts and
 * must never be averaged into one rate.
 *
 * THE ARITHMETIC, and why it is shaped this way:
 *
 *     value = floor(credits * lot.acquisition_amount_minor / lot.original_amount)
 *
 * The lot's total cash cost and its total credits are both stored, and the
 * division happens once, here, on integers. A per-credit rate is never stored
 * and never rounded on its own: a package of 300 credits for GH25 works out
 * at 8.33 pesewas a credit, and storing that rounded to 8 would lose a
 * pesewa on every third credit. Multiplying first and dividing once keeps the
 * whole amount exact up to a single final truncation.
 *
 * TRUNCATION, NOT ROUNDING. The remainder is discarded rather than rounded up,
 * so a valuation can never exceed the money that was actually taken for those
 * credits. `remainderMinor` records what was dropped, so the choice is visible
 * in the record rather than silently absorbed.
 */
final readonly class LotValuation
{
    public function __construct(
        public int $lotId,
        public CreditLotSource $source,
        /** Credits this user consumed from this lot on the auction in question. */
        public int $credits,
        /** What the whole lot cost, in minor units. Zero for a free lot. */
        public int $lotAcquisitionMinor,
        /** How many credits that cash bought. */
        public int $lotOriginalAmount,
        /** The consumed credits' cash value, truncated to whole minor units. */
        public Money $value,
        /** Sub-minor-unit remainder discarded by the truncation, as a numerator over `lotOriginalAmount`. */
        public int $remainderNumerator,
    ) {}

    /**
     * Value a quantity of credits drawn from a lot.
     *
     * Free credits -- promotional, referral, adjustment -- cost nothing, so
     * they are worth nothing. That is not a penalty: issuing cash value
     * against credits the platform gave away would create a cash liability out
     * of a marketing gesture.
     */
    public static function forLot(CreditLot $lot, int $credits, string $currency = 'GHS'): self
    {
        $acquisition = $lot->acquisition_amount_minor;
        $original = $lot->original_amount;

        // A free lot, or -- defensively -- a lot with no credits in it, which
        // would make the division undefined.
        if ($acquisition <= 0 || $original <= 0 || $credits <= 0) {
            return new self(
                lotId: (int) $lot->getKey(),
                source: $lot->source_type,
                credits: max(0, $credits),
                lotAcquisitionMinor: max(0, $acquisition),
                lotOriginalAmount: $original,
                value: Money::zero($currency),
                remainderNumerator: 0,
            );
        }

        // Multiply before dividing, and divide exactly once. Integer
        // arithmetic throughout: no float touches this.
        $numerator = $credits * $acquisition;

        return new self(
            lotId: (int) $lot->getKey(),
            source: $lot->source_type,
            credits: $credits,
            lotAcquisitionMinor: $acquisition,
            lotOriginalAmount: $original,
            value: Money::fromMinor(intdiv($numerator, $original), $currency),
            remainderNumerator: $numerator % $original,
        );
    }

    /**
     * Whether these credits were paid for with real money.
     */
    public function isPaid(): bool
    {
        return $this->source->isPaid() && $this->lotAcquisitionMinor > 0;
    }

    /**
     * The record of this line, for the issuance's audit trail.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'credit_lot_id' => $this->lotId,
            'source' => $this->source->value,
            'credits' => $this->credits,
            'lot_acquisition_amount_minor' => $this->lotAcquisitionMinor,
            'lot_original_amount' => $this->lotOriginalAmount,
            'amount_minor' => $this->value->minor,
            'remainder_numerator' => $this->remainderNumerator,
        ];
    }
}
