<?php

declare(strict_types=1);

namespace App\Domain\Auction\Services;

use App\Domain\Auction\Actions\CompleteBuyNow;
use App\Domain\Auction\ValueObjects\BuyNowQuote;
use App\Domain\Shared\Money\Money;
use App\Models\Auction;
use App\Models\User;

/**
 * What one user would pay to buy an auction's product outright.
 *
 * THE ONE SANCTIONED CONVERSION. Each credit that user has already consumed
 * bidding on *this* auction takes a fixed amount off the Buy Now price -- one
 * cedi per credit, at the rate frozen in the auction's snapshot. That rate is
 * historical: changing the ruleset tomorrow does not change what a running
 * auction offers.
 *
 * WHICH CREDITS QUALIFY, and equally which do not:
 *
 *   count        credits consumed by accepted bids by this user on this
 *                auction, summed from the bid records
 *
 *   do not       unused credits sitting in the wallet
 *   count        credits bought but never bid
 *                bonus or promotional credits never bid
 *                credits consumed bidding on a different auction
 *                credits consumed by a different user
 *
 * The eligible figure comes from the bid records rather than from a wallet
 * balance, and that is deliberate: a balance changes with everything else the
 * user does, so a discount computed from it would drift. Bids are historical
 * facts chained to the credit transactions that paid for them.
 *
 * NOT A REFUND. The credits stay consumed whether or not the user buys. They
 * are not returned, not withdrawn, not converted to cash, and not transferred.
 * What they earn is a reduction in a separate purchase price on this auction
 * alone.
 *
 * Integer arithmetic throughout, on minor units. No float touches a price.
 */
class BuyNowPricer
{
    public function __construct(
        private readonly HighestBidResolver $bids,
    ) {}

    /**
     * Price this auction's product for this user, right now.
     */
    public function quote(Auction $auction, ?User $user = null): BuyNowQuote
    {
        $rules = $auction->rules();
        $listPrice = $auction->product->buyNowPrice();

        $eligible = $user === null
            ? 0
            : $this->eligibleCredits($auction, $user);

        $discount = $rules->buyNowDiscountFor($eligible);

        // A discount can never exceed the price. Capping rather than allowing
        // a negative payable: buying something cannot pay the buyer, and a
        // discount bigger than the item is a windfall nobody agreed to.
        if ($discount->minor > $listPrice->minor) {
            $discount = $listPrice;
        }

        [$available, $reason] = $this->availability($auction);

        return new BuyNowQuote(
            listPrice: $listPrice,
            eligibleCredits: $eligible,
            discount: $discount,
            payable: $listPrice->minus($discount),
            available: $available,
            unavailableReason: $reason,
        );
    }

    /**
     * Credits this user consumed bidding on this auction.
     *
     * Never the wallet balance, and never credits from anywhere else.
     */
    public function eligibleCredits(Auction $auction, User $user): int
    {
        if (! $auction->rules()->buyNowCreditDiscountEnabled) {
            return 0;
        }

        return $this->bids->consumedCreditsBy($auction, $user->id);
    }

    /**
     * Whether Buy Now can be completed on this auction at this moment.
     *
     * Advisory only, for rendering. The binding check happens inside
     * {@see CompleteBuyNow}, under the auction row
     * lock, because between rendering a page and acting on it somebody else
     * may already have bought the product.
     *
     * @return array{bool, string|null}
     */
    private function availability(Auction $auction): array
    {
        if (! $auction->rules()->buyNowEnabled) {
            return [false, 'Buy Now is not available on this auction.'];
        }

        if ($auction->endedByBuyNow()) {
            return [false, 'This product has already been bought outright.'];
        }

        if (! $auction->status->acceptsBuyNow()) {
            return [false, 'This auction is not open.'];
        }

        // Stock on hand, deliberately not available stock. An open auction is
        // itself holding one unit in reserve -- that reserved unit is exactly
        // what Buy Now would sell -- so measuring against availability would
        // report every auction as out of stock. What matters is that the unit
        // still physically exists.
        if ($auction->product->stock_on_hand < 1) {
            return [false, 'This product is not available right now.'];
        }

        return [true, null];
    }

    /**
     * The amount to charge, given a quote.
     *
     * A named method rather than reaching into the quote, so the rule that the
     * charge is the discounted figure -- never the list price -- is stated in
     * one place.
     */
    public function payable(BuyNowQuote $quote): Money
    {
        return $quote->payable;
    }
}
