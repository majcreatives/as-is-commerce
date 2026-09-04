<?php

declare(strict_types=1);

namespace App\Domain\Auction\ValueObjects;

use App\Domain\Shared\Money\Money;
use JsonSerializable;

/**
 * What one user would pay to buy one auction's product outright, right now.
 *
 * A calculation, not a promise. It reflects the bids that user had placed on
 * that auction when it was worked out, and it changes as they bid more.
 *
 * THE ONE PLACE CREDITS MEET MONEY. Each eligible credit already consumed
 * bidding on this auction takes a fixed amount off the Buy Now price -- one
 * cedi per credit at the rate the auction was created with. Everywhere else
 * in the system, credits and money are separate quantities that are never
 * converted.
 *
 * WHAT THIS IS NOT. Not a refund, not a withdrawal, not cash back, and not a
 * conversion of a wallet balance into money. The credits stay consumed: they
 * were spent on bids and are gone whatever the buyer decides. What they earn
 * is a reduction in a separate purchase price, and only on the auction they
 * were spent on.
 */
final readonly class BuyNowQuote implements JsonSerializable
{
    public function __construct(
        /** The product's own Buy Now price, untouched. */
        public Money $listPrice,
        /** Credits this user consumed bidding on this auction. */
        public int $eligibleCredits,
        /** What those credits take off the price. */
        public Money $discount,
        /** List price less the discount, never below zero. */
        public Money $payable,
        /** Whether Buy Now can actually be used on this auction now. */
        public bool $available,
        /** Why not, when it cannot. */
        public ?string $unavailableReason = null,
    ) {}

    public function hasDiscount(): bool
    {
        return $this->discount->isPositive();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'list_price_minor' => $this->listPrice->minor,
            'eligible_credits' => $this->eligibleCredits,
            'discount_minor' => $this->discount->minor,
            'payable_minor' => $this->payable->minor,
            'currency' => $this->payable->currency,
            'available' => $this->available,
            'unavailable_reason' => $this->unavailableReason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
