<?php

declare(strict_types=1);

namespace App\Domain\Orders\ValueObjects;

use App\Domain\Orders\Exceptions\InvalidCheckout;
use App\Domain\Shared\Money\Money;
use App\Enums\OrderSource;
use JsonSerializable;

/**
 * What a customer owes, worked out once and frozen.
 *
 * Every figure here is computed on the server from server-side reads. Nothing
 * a browser sends contributes to any of them -- a request names a product or
 * an auction, never a price, a discount, a delivery charge or a total.
 *
 * THE COMPONENTS STAY SEPARATE:
 *
 *     subtotal - discount + delivery + tax = total
 *
 * Delivery is never folded into a product price and a discount is never folded
 * into a delivery charge, so a customer disputing a total can be shown exactly
 * which part they are disputing. The identity is asserted here and again by a
 * database CHECK constraint.
 *
 * THE TWO PATHS OWE DIFFERENT THINGS:
 *
 *   Buy Now      subtotal is the product's own Buy Now price, and the
 *                discount is the cedis earned by credits this buyer already
 *                consumed bidding on this auction -- one cedi per credit, at
 *                the rate frozen into the auction.
 *
 *   Auction win  subtotal is the auction's own settlement amount, a low GHS
 *                figure chosen per auction. There is no discount: a winner's
 *                consumed credits bought them the win, and do not also reduce
 *                what they settle.
 *
 * WHAT IS NOT HERE. No credit is converted into money except through the Buy
 * Now discount, and no credit is charged: the credits were consumed when the
 * bids were placed and are not spent again at checkout. `discountCredits` is a
 * count kept as evidence for the discount, never an amount.
 */
final readonly class CheckoutPricing implements JsonSerializable
{
    /**
     * Incremented if the serialized shape changes, so a stored snapshot can
     * be recognised -- or refused -- after a schema evolution.
     */
    public const SNAPSHOT_VERSION = 1;

    public function __construct(
        public OrderSource $source,
        /** The product's Buy Now price, or the auction's settlement amount. */
        public Money $subtotal,
        /** Cedis taken off by consumed bid credits. Zero on a settlement. */
        public Money $discount,
        public Money $delivery,
        public Money $tax,
        public Money $total,
        /** Credits that earned the discount. A count, not money. */
        public int $discountCredits = 0,
        /** Pesewas per credit, as frozen into the auction. */
        public int $discountRateMinorPerCredit = 0,
        /** The rate tax was computed at, in basis points. */
        public int $taxBps = 0,
        /** The winning bid's credit amount, on a settlement order. */
        public ?int $winningBidCredits = null,
    ) {
        $this->assertValid();
    }

    public function currency(): string
    {
        return $this->total->currency;
    }

    public function hasDiscount(): bool
    {
        return $this->discount->isPositive();
    }

    /**
     * Subtotal less discount: what the goods themselves come to.
     *
     * Named separately from the total because a customer reads the two
     * differently -- this is the item, the total is the bill.
     */
    public function goodsTotal(): Money
    {
        return $this->subtotal->minus($this->discount);
    }

    // ------------------------------------------------------------ Snapshot

    /**
     * The complete derivation, as persisted on the order.
     *
     * Stored whole so a disputed total can be re-explained from what was
     * actually agreed, rather than re-derived from a product or ruleset that
     * has since changed.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'source' => $this->source->value,
            'currency' => $this->total->currency,

            'subtotal_minor' => $this->subtotal->minor,
            'discount_minor' => $this->discount->minor,
            'delivery_minor' => $this->delivery->minor,
            'tax_minor' => $this->tax->minor,
            'total_minor' => $this->total->minor,

            // How the discount was arrived at: a count of credits and the rate
            // they were worth. Both frozen, so the arithmetic can be checked
            // years later against neither of them having moved.
            'discount_credits' => $this->discountCredits,
            'discount_rate_minor_per_credit' => $this->discountRateMinorPerCredit,

            'tax_bps' => $this->taxBps,
            'winning_bid_credits' => $this->winningBidCredits,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $version = (int) ($data['snapshot_version'] ?? 0);

        if ($version !== self::SNAPSHOT_VERSION) {
            throw InvalidCheckout::because(
                "Checkout pricing snapshot version [{$version}] is not supported by this application version."
            );
        }

        $currency = (string) ($data['currency'] ?? 'GHS');
        $money = fn (string $key): Money => Money::fromMinor((int) ($data[$key] ?? 0), $currency);

        return new self(
            source: OrderSource::from((string) $data['source']),
            subtotal: $money('subtotal_minor'),
            discount: $money('discount_minor'),
            delivery: $money('delivery_minor'),
            tax: $money('tax_minor'),
            total: $money('total_minor'),
            discountCredits: (int) ($data['discount_credits'] ?? 0),
            discountRateMinorPerCredit: (int) ($data['discount_rate_minor_per_credit'] ?? 0),
            taxBps: (int) ($data['tax_bps'] ?? 0),
            winningBidCredits: isset($data['winning_bid_credits'])
                ? (int) $data['winning_bid_credits']
                : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function assertValid(): void
    {
        foreach (['subtotal', 'discount', 'delivery', 'tax', 'total'] as $component) {
            if ($this->{$component}->isNegative()) {
                throw InvalidCheckout::because("A checkout {$component} cannot be negative.");
            }

            if ($this->{$component}->currency !== $this->total->currency) {
                throw InvalidCheckout::because(
                    "Every checkout component must be in one currency; {$component} is not."
                );
            }
        }

        if (! $this->subtotal->isPositive()) {
            throw InvalidCheckout::because('A checkout subtotal must be greater than zero.');
        }

        if ($this->discount->minor > $this->subtotal->minor) {
            throw InvalidCheckout::because('A discount cannot exceed what is being bought.');
        }

        // The identity that makes the total explainable. Asserted rather than
        // computed, so a caller that builds the parts inconsistently is caught
        // here rather than storing a total nobody can account for.
        $expected = $this->subtotal->minus($this->discount)
            ->plus($this->delivery)
            ->plus($this->tax);

        if (! $this->total->equals($expected)) {
            throw InvalidCheckout::because(
                "The total {$this->total->format()} does not equal subtotal less discount plus "
                ."delivery and tax ({$expected->format()})."
            );
        }

        if (! $this->total->isPositive()) {
            throw InvalidCheckout::because('A checkout total must be greater than zero.');
        }

        if ($this->discountCredits < 0) {
            throw InvalidCheckout::because('A credit count cannot be negative.');
        }

        // The rule that keeps the two paths apart. A winner's consumed credits
        // bought them the win; they do not also reduce the settlement.
        if ($this->source === OrderSource::AuctionWin
            && ($this->discount->isPositive() || $this->discountCredits > 0)) {
            throw InvalidCheckout::because(
                'An auction settlement carries no credit discount: consumed bid credits are not '
                .'applied against what a winner settles.'
            );
        }
    }
}
