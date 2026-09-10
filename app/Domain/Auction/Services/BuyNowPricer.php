<?php

declare(strict_types=1);

namespace App\Domain\Auction\Services;

use App\Domain\Auction\Actions\CompleteBuyNow;
use App\Domain\Auction\ValueObjects\BuyNowQuote;
use App\Domain\Shared\Money\Money;
use App\Domain\StoreWallet\Services\ConsumedCreditValuation;
use App\Domain\StoreWallet\ValueObjects\CreditValuation;
use App\Models\Auction;
use App\Models\User;

/**
 * What one user would pay to buy an auction's product outright.
 *
 * THE ONE SANCTIONED CONVERSION. Each credit that user has already consumed
 * bidding on *this* auction is valued at what that exact credit actually cost
 * -- its lot's own acquisition price, frozen when the lot was created -- and
 * that value comes off the Buy Now price. Credits bought at GH0.10 reduce the
 * price by GH0.10 per credit; promotional credits reduce it by nothing.
 *
 * NEVER A SYSTEM-WIDE RATE. There is no global "how much is a credit worth"
 * figure. Two lots bought on different days at different prices are worth
 * different amounts, and blending them into one rate would over- or under-value
 * someone's credits the moment they bought at anything but the average. The
 * valuation here is the same one the Store Wallet uses when an auction is won
 * by somebody else, so the two paths can never disagree about what a credit
 * was worth.
 *
 * WHICH CREDITS QUALIFY, and equally which do not:
 *
 *   count        credits consumed by accepted bids by this user on this
 *                auction, summed from the bid records, walked back through
 *                the credit transactions to the exact lots they came from
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
        private readonly ConsumedCreditValuation $valuation,
    ) {}

    /**
     * Price this auction's product for this user, right now.
     */
    public function quote(Auction $auction, ?User $user = null): BuyNowQuote
    {
        $listPrice = $auction->product->buyNowPrice();

        $valuation = $user === null
            ? CreditValuation::empty($listPrice->currency)
            : $this->valuationFor($auction, $user);

        $discount = $valuation->total;

        // A discount can never exceed the price. Capping rather than allowing
        // a negative payable: buying something cannot pay the buyer, and a
        // discount bigger than the item is a windfall nobody agreed to.
        if ($discount->minor > $listPrice->minor) {
            $discount = $listPrice;
        }

        [$available, $reason] = $this->availability($auction);

        return new BuyNowQuote(
            listPrice: $listPrice,
            eligibleCredits: $valuation->totalCredits,
            discount: $discount,
            payable: $listPrice->minus($discount),
            available: $available,
            unavailableReason: $reason,
        );
    }

    /**
     * The cash value of this user's consumed credits on this auction.
     *
     * The same valuation the Store Wallet issues when somebody else wins, so a
     * credit's worth never differs between the two paths.
     *
     * When the buy-now credit discount is switched off, the credits are still
     * valueless here -- the switch says the consumed credits' value does not
     * come off the price, and an empty valuation honours that without the
     * customer's spend disappearing from the calculation.
     */
    public function valuationFor(Auction $auction, User $user): CreditValuation
    {
        if (! $auction->rules()->buyNowCreditDiscountEnabled) {
            return CreditValuation::empty($auction->currency ?? 'GHS');
        }

        return $this->valuation->forAuction($auction, $user->id, $auction->currency ?? 'GHS');
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
