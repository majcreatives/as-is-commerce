<?php

declare(strict_types=1);

namespace App\Domain\Auction\ValueObjects;

use App\Domain\Auction\Exceptions\InvalidAuctionRules;
use App\Domain\Shared\Money\Money;
use App\Models\AuctionRuleset;
use JsonSerializable;

/**
 * Everything an auction was created under, frozen at creation.
 *
 * Two parts, deliberately kept apart:
 *
 *   rules       the ruleset's terms -- bid validity, timing, Buy Now, the
 *               discount rate. Shared configuration, taken from a versioned
 *               {@see AuctionRuleset}.
 *
 *   settlement  what a normal highest-bid winner pays for this particular
 *               auction. An auction-level figure, chosen per auction, and not
 *               a property of any ruleset.
 *
 * WHY THE SETTLEMENT AMOUNT IS NOT IN THE RULES. Two auctions on the same
 * product under the same ruleset may settle at GH 50 and GH 150. It varies per
 * auction, so it belongs to the auction. Putting it in the ruleset would force
 * a new ruleset version for every auction with different economics, and would
 * reintroduce exactly the shared-checkout-price field that the correction
 * stage removed.
 *
 * WHAT THE SETTLEMENT AMOUNT IS NOT. Not the product's Buy Now price, not
 * derived from it, and not derived from anybody's bid. A winner who committed
 * 180 credits owes this amount plus applicable charges -- not GH 180, and not
 * the GH 5,500 the product sells for outright. Those are three separate
 * numbers and this object keeps them separate.
 *
 * Immutable, and shares no state with the ruleset it came from. Editing,
 * archiving or deleting that ruleset afterwards has no effect on an auction
 * already created.
 */
final readonly class AuctionSnapshot implements JsonSerializable
{
    /**
     * Incremented when the serialized shape changes, so a stored snapshot can
     * be recognised -- or refused -- after a schema evolution.
     *
     * Version 1 is the first shape that exists: no auction has ever been
     * created before this stage.
     */
    public const SNAPSHOT_VERSION = 1;

    public function __construct(
        public AuctionRules $rules,
        public Money $settlementAmount,
    ) {
        $this->assertValid();
    }

    public function currency(): string
    {
        return $this->settlementAmount->currency;
    }

    /**
     * How this auction picks its winner, read from the frozen rules rather
     * than from whatever the code does today.
     */
    public function winnerRule(): string
    {
        return $this->rules->winnerRule();
    }

    /**
     * What a normal highest-bid winner owes, before delivery and tax.
     *
     * The credits they committed are gone and are not part of this figure.
     * They bought the right to win, not a share of the price.
     */
    public function settlementAmount(): Money
    {
        return $this->settlementAmount;
    }

    /**
     * The full amount a normal winner owes: settlement plus the charges the
     * frozen rules attach to it.
     *
     * Integer arithmetic throughout. Tax applies to the settlement and the
     * delivery fee together, which is the ordinary reading of a charge on the
     * transaction rather than on part of it.
     */
    public function settlementTotal(): Money
    {
        $subtotal = $this->settlementAmount->plus($this->rules->deliveryFee);

        return $subtotal->plus($subtotal->percentageBps($this->rules->taxBps));
    }

    // ------------------------------------------------------------ Snapshot

    /**
     * Serialized form, as persisted in `auctions.rules_snapshot`.
     *
     * Nested rather than flattened so the two halves stay visibly distinct: a
     * reader can see at a glance that the settlement amount is the auction's
     * own figure and not one of the ruleset's terms.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'rules' => $this->rules->toArray(),
            'auction' => [
                'settlement_amount_minor' => $this->settlementAmount->minor,
                'currency' => $this->settlementAmount->currency,
            ],
        ];
    }

    /**
     * Rebuild from a stored snapshot.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $version = (int) ($data['snapshot_version'] ?? 0);

        if ($version !== self::SNAPSHOT_VERSION) {
            throw InvalidAuctionRules::unsupportedSnapshotVersion($version);
        }

        /** @var array<string, mixed> $rules */
        $rules = $data['rules'] ?? [];

        /** @var array<string, mixed> $auction */
        $auction = $data['auction'] ?? [];

        return new self(
            rules: AuctionRules::fromArray($rules),
            settlementAmount: Money::fromMinor(
                (int) ($auction['settlement_amount_minor'] ?? 0),
                (string) ($auction['currency'] ?? 'GHS'),
            ),
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
        if (! $this->settlementAmount->isPositive()) {
            throw InvalidAuctionRules::because(
                'An auction settlement amount must be greater than zero.'
            );
        }

        // One currency per auction. A settlement in cedis under rules whose
        // delivery fee is in something else could not be totalled at all.
        if ($this->settlementAmount->currency !== $this->rules->currency()) {
            throw InvalidAuctionRules::because(
                "The settlement amount is in {$this->settlementAmount->currency} but the rules "
                ."are in {$this->rules->currency()}."
            );
        }
    }
}
