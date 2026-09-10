<?php

declare(strict_types=1);

namespace App\Domain\Orders\Services;

use App\Domain\Auction\Services\BuyNowPricer;
use App\Domain\Orders\Exceptions\InvalidCheckout;
use App\Domain\Orders\ValueObjects\CheckoutPricing;
use App\Domain\Shared\Money\Money;
use App\Domain\StoreWallet\Services\StoreWalletCheckout;
use App\Enums\OrderSource;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\Product;
use App\Models\User;

/**
 * The one place a customer's total is worked out.
 *
 * EVERY INPUT IS READ ON THE SERVER. A product's price comes from the product
 * row, a settlement amount from the auction's frozen snapshot, a discount from
 * the bid records, delivery and tax from frozen rules or from settings an
 * administrator owns. Nothing a browser sends reaches any of it -- a request
 * names a product or an auction, and that is the whole of its influence.
 *
 * THE TWO PATHS OWE DIFFERENT THINGS, and this class is where that difference
 * lives:
 *
 *   Buy Now      subtotal = the product's own Buy Now price
 *                discount = the actual cash value of the credits this buyer
 *                           consumed bidding on this auction, each credit
 *                           valued at what its own lot was bought for (never
 *                           at a system-wide rate)
 *
 *   Auction win  subtotal = the auction's own settlement amount, a low figure
 *                           chosen per auction
 *                discount = nothing. A winner's consumed credits bought them
 *                           the win; they do not also reduce the settlement.
 *
 * THE STORE WALLET PORTION. An ordinary catalogue purchase -- no auction
 * behind it -- may commit Store Wallet value toward the bill. That value is
 * taken at checkout (in {@see StartBuyNowCheckout}), frozen on the order here,
 * and what is left over, `payable`, is the figure a provider ever verifies. An
 * auction-linked purchase carries none: the auction's price already reflects
 * the bidder's credit discount, and its unit was reserved at publish.
 *
 * WHAT IS NEVER DONE HERE. A settlement amount is never derived from a
 * product's Buy Now price, from a percentage of it, or from a winning bid. A
 * credit balance is never consulted. No credit is charged: they were consumed
 * when the bids were placed.
 *
 * Integer arithmetic throughout, on minor units. No float touches a price.
 */
class CheckoutPricer
{
    public function __construct(
        private readonly BuyNowPricer $buyNow,
        private readonly StoreWalletCheckout $storeWallet,
    ) {}

    /**
     * Price buying a product outright.
     *
     * The auction is optional. With one, the buyer's consumed bid credits on
     * that auction earn their discount and the auction's frozen delivery and
     * tax terms apply; no Store Wallet value is applied, because the auction
     * already sells its unit for the price that carries the bidder's credit
     * discount. Without one, this is an ordinary catalog purchase: the
     * charges come from settings and the buyer's Store Wallet may cover part
     * of the bill.
     */
    public function forBuyNow(Product $product, User $buyer, ?Auction $auction = null): CheckoutPricing
    {
        $subtotal = $product->buyNowPrice();

        if (! $subtotal->isPositive()) {
            throw InvalidCheckout::because('This product has no price.');
        }

        [$discount, $credits, $valuation] = $auction === null
            // No auction, so no bids, so nothing has been consumed that could
            // earn a discount. Not an omission -- the discount exists only for
            // credits spent bidding on the auction being bought out of.
            ? [Money::zero($subtotal->currency), 0, null]
            : $this->auctionDiscount($auction, $buyer, $subtotal);

        [$delivery, $taxBps] = $auction === null
            ? $this->settingsCharges($subtotal->currency)
            : [$auction->rules()->deliveryFee, $auction->rules()->taxBps];

        // First pass prices the bill with no Store Wallet portion, so the
        // total is known before deciding what the wallet may cover.
        $base = $this->assemble(
            source: OrderSource::BuyNow,
            subtotal: $subtotal,
            discount: $discount,
            delivery: $delivery,
            taxBps: $taxBps,
            discountCredits: $credits,
            valuation: $valuation,
        );

        if ($auction === null) {
            // An ordinary catalogue purchase. The wallet may cover part of
            // this bill -- the whole balance, capped at the total -- but never
            // all of it, and never on any other path.
            $applied = $this->storeWallet->applicable($buyer, $base->total);

            $this->storeWallet->assertEligible(
                source: OrderSource::BuyNow,
                auctionId: null,
                applied: $applied,
                total: $base->total,
            );

            if ($applied->isPositive()) {
                return $this->assemble(
                    source: OrderSource::BuyNow,
                    subtotal: $subtotal,
                    discount: $discount,
                    delivery: $delivery,
                    taxBps: $taxBps,
                    discountCredits: $credits,
                    valuation: $valuation,
                    storeWalletApplied: $applied,
                );
            }
        }

        return $base;
    }

    /**
     * Price what an auction winner owes.
     *
     * The subtotal is the auction's own `settlement_amount_minor`, read from
     * the frozen snapshot. Deliberately unrelated to what the product sells
     * for and to what the winner bid: a GH₵5,500 product won with 180 credits
     * may settle at GH₵100. No Store Wallet value applies to a settlement.
     */
    public function forSettlement(Auction $auction, Bid $winningBid): CheckoutPricing
    {
        $rules = $auction->rules();

        return $this->assemble(
            source: OrderSource::AuctionWin,
            // From the auction, never from the product.
            subtotal: $auction->settlementAmount(),
            // None. The winner's credits are already consumed and are not
            // applied against this.
            discount: Money::zero($auction->currency),
            delivery: $rules->deliveryFee,
            taxBps: $rules->taxBps,
            discountCredits: 0,
            winningBidCredits: $winningBid->amount_credits,
        );
    }

    // ------------------------------------------------------------ Internals

    /**
     * The discount this buyer's consumed bid credits earn on this auction.
     *
     * Delegates to the Stage 6 pricer, which sums the bid records rather than
     * reading a wallet balance -- a balance moves with everything else the
     * user does, so a discount computed from one would drift.
     *
     * @return array{Money, int, array<string, mixed>|null}
     */
    private function auctionDiscount(Auction $auction, User $buyer, Money $subtotal): array
    {
        $quote = $this->buyNow->quote($auction, $buyer);

        if ($quote->listPrice->minor !== $subtotal->minor) {
            // The quote prices the auction's own product. If it disagrees with
            // the product being bought, the caller has paired the wrong two
            // things together and the checkout must not be built.
            throw InvalidCheckout::because(
                'This auction is for a different product than the one being bought.'
            );
        }

        // The valuation evidence that produced the discount, frozen alongside
        // it so the arithmetic can be re-explained without either a ruleset or
        // a lot having moved.
        $valuation = $this->buyNow->valuationFor($auction, $buyer);

        return [
            $quote->discount,
            $quote->eligibleCredits,
            $valuation->isZero() ? null : $valuation->toArray(),
        ];
    }

    /**
     * Delivery and tax for a purchase with no auction behind it.
     *
     * Both come from settings an administrator owns, and both are seeded at
     * zero. Nothing here invents a delivery charge or a tax rate.
     *
     * @return array{Money, int}
     */
    private function settingsCharges(string $currency): array
    {
        // Both accessors are nullable, and a missing setting must mean "no
        // charge" rather than a fatal -- a checkout should not fail because an
        // optional charge has not been configured.
        return [
            settings()->getMoney('delivery_fee_minor', Money::zero($currency)) ?? Money::zero($currency),
            settings()->getInt('checkout_tax_bps', 0) ?? 0,
        ];
    }

    /**
     * Put the components together and compute the total.
     *
     * Tax applies to the goods plus delivery -- a charge on the transaction
     * rather than on part of it -- and is computed in basis points by integer
     * arithmetic, so it is exact and deterministic. The Store Wallet portion
     * reduces the total to a payable that is what the provider verifies.
     *
     * @param  array<string, mixed>|null  $valuation
     */
    private function assemble(
        OrderSource $source,
        Money $subtotal,
        Money $discount,
        Money $delivery,
        int $taxBps,
        int $discountCredits,
        ?array $valuation = null,
        ?int $winningBidCredits = null,
        ?Money $storeWalletApplied = null,
    ): CheckoutPricing {
        $goods = $subtotal->minus($discount);
        $taxable = $goods->plus($delivery);
        $tax = $taxable->percentageBps($taxBps);
        $total = $taxable->plus($tax);

        $applied = $storeWalletApplied ?? Money::zero($total->currency);

        return new CheckoutPricing(
            source: $source,
            subtotal: $subtotal,
            discount: $discount,
            delivery: $delivery,
            tax: $tax,
            total: $total,
            discountCredits: $discountCredits,
            valuation: $valuation,
            storeWalletApplied: $applied,
            payable: $total->minus($applied),
            taxBps: $taxBps,
            winningBidCredits: $winningBidCredits,
        );
    }
}
