<?php

declare(strict_types=1);

namespace App\Domain\Auction\ValueObjects;

use App\Domain\Auction\Exceptions\InvalidAuctionRules;
use App\Domain\Shared\Money\Money;
use App\Enums\ForfeitPolicy;
use JsonSerializable;

/**
 * The complete, self-contained rule set governing a single auction.
 *
 * THE WINNER RULE. The participant holding the highest valid credit bid at
 * normal closure wins. Not the last bidder, not the most frequent bidder, not
 * the one who held the lead longest -- simply the highest valid bid. A
 * participant who bid early, was overtaken, and later bid higher still wins on
 * that highest bid.
 *
 * The one thing that overrides it: a successful Buy Now purchase ends the
 * auction immediately, and the standing highest bidder does not win.
 *
 * WHAT IS DELIBERATELY ABSENT. There is no settlement price here. What a
 * normal auction winner ultimately pays has not been decided, and encoding an
 * amount would be inventing that decision. The auction engine must not assume
 * one.
 *
 * This is a value object rather than an Eloquent model because an auction
 * freezes its rules at creation: it stores a serialized copy of this object,
 * not a foreign key to a mutable row. An administrator editing configuration
 * tomorrow cannot alter how an auction already running behaves, or how a
 * closed one is explained months later.
 *
 * Every value is an integer, a boolean or an integer-backed Money. Nothing is
 * a float, and no duration is a formatted string.
 */
final readonly class AuctionRules implements JsonSerializable
{
    /**
     * Incremented when the serialized shape changes, so a historical snapshot
     * can still be recognised -- or refused -- after a schema evolution.
     *
     * Version 2 corrects the model from Last Bidder Standing to highest valid
     * credit bid. No version 1 snapshot exists anywhere: no auction has ever
     * been created, because the auction engine does not exist yet.
     */
    public const SNAPSHOT_VERSION = 2;

    /**
     * How the winner is determined, stated rather than implied.
     *
     * Recorded in every snapshot so the future engine reads its winner rule
     * from the auction's own frozen configuration instead of inferring it from
     * whatever the code happens to do that week.
     */
    public const WINNER_RULE = 'highest_valid_credit_bid';

    public function __construct(
        // ---- Bidding ------------------------------------------------------
        //
        // Bids carry their own amounts; there is no fixed cost per bid. These
        // constrain what amount is acceptable, and each is nullable because
        // its business value has not been decided. Null means "no rule",
        // which is honestly different from any particular number.
        public ?int $minimumBidCredits,
        public ?int $minimumBidIncrementCredits,
        public ?bool $allowBidIncrease,
        public int $minimumBidIntervalMs,

        // ---- Timing (integer seconds; the engine is server-authoritative) --
        //
        // Late-bid extension is anti-sniping. Under this model it is entirely
        // independent of who wins: extending the clock gives others a chance
        // to bid higher, it does not change how the winner is chosen.
        public int $baseDurationSeconds,
        public int $closingWindowSeconds,
        public int $extensionSeconds,
        public int $maxExtensions,
        public int $maxExtensionTotalSeconds,

        // ---- Buy Now ------------------------------------------------------
        public bool $buyNowEnabled,
        public bool $buyNowCreditDiscountEnabled,
        public int $buyNowCreditDiscountMinorPerCredit,

        // ---- Post-win obligations -----------------------------------------
        //
        // A time limit and a lapse policy, both independent of the amount a
        // winner pays -- which remains undecided.
        public int $checkoutDeadlineMinutes,
        public ForfeitPolicy $forfeitPolicy,

        // ---- Transaction economics (integer minor units) -------------------
        public Money $deliveryFee,
        public int $taxBps,

        // Provenance: which ruleset row and version this was taken from.
        public ?int $rulesetId = null,
        public ?string $rulesetName = null,
        public ?int $rulesetVersion = null,
    ) {
        $this->assertValid();
    }

    public function currency(): string
    {
        return $this->deliveryFee->currency;
    }

    /**
     * The winner rule, for a caller that would otherwise guess.
     */
    public function winnerRule(): string
    {
        return self::WINNER_RULE;
    }

    // ------------------------------------------------------------ Bid rules

    public function hasMinimumBid(): bool
    {
        return $this->minimumBidCredits !== null;
    }

    public function hasMinimumIncrement(): bool
    {
        return $this->minimumBidIncrementCredits !== null;
    }

    /**
     * The smallest bid that could be valid given a standing highest bid.
     *
     * Returns null when neither rule is configured, which means the engine
     * must not invent a floor of its own -- an unset rule is not the same as
     * a rule of one.
     */
    public function smallestValidBid(?int $currentHighestBid = null): ?int
    {
        $floors = [];

        if ($this->minimumBidCredits !== null) {
            $floors[] = $this->minimumBidCredits;
        }

        if ($currentHighestBid !== null && $this->minimumBidIncrementCredits !== null) {
            $floors[] = $currentHighestBid + $this->minimumBidIncrementCredits;
        }

        return $floors === [] ? null : max($floors);
    }

    // ------------------------------------------------------------- Buy Now

    /**
     * The GHS a given number of consumed bid credits takes off Buy Now.
     *
     * Integer arithmetic on minor units: one credit maps to a fixed number of
     * pesewas, so the discount is exact however many credits are involved.
     *
     * This is the only place credits relate to money anywhere in the system,
     * and it applies solely to the Buy Now path. It does not give the credits
     * back -- they stay consumed -- it reduces a separate purchase price.
     *
     * The future Buy Now engine calls this with credits it has established
     * were actually consumed by that user on that auction. Deciding which
     * credits qualify is that engine's job, not this object's.
     */
    public function buyNowDiscountFor(int $consumedBidCredits): Money
    {
        if (! $this->buyNowCreditDiscountEnabled || $consumedBidCredits <= 0) {
            return Money::zero($this->currency());
        }

        return Money::fromMinor(
            $consumedBidCredits * $this->buyNowCreditDiscountMinorPerCredit,
            $this->currency(),
        );
    }

    // -------------------------------------------------------------- Timing

    /**
     * Whether a late bid can extend this auction.
     *
     * False when switched off by any of the limits, which lets the engine skip
     * the extension path entirely. Extensions are optional: an auction that
     * simply ends at its scheduled time is a valid configuration.
     */
    public function extensionsEnabled(): bool
    {
        return $this->maxExtensions > 0
            && $this->extensionSeconds > 0
            && $this->maxExtensionTotalSeconds > 0;
    }

    /**
     * Longest the auction can run if every permitted extension is used.
     *
     * Both limits apply, so the smaller governs.
     */
    public function maximumPossibleDurationSeconds(): int
    {
        if (! $this->extensionsEnabled()) {
            return $this->baseDurationSeconds;
        }

        $byCount = $this->maxExtensions * $this->extensionSeconds;

        return $this->baseDurationSeconds + min($byCount, $this->maxExtensionTotalSeconds);
    }

    // ------------------------------------------------------------ Snapshot

    /**
     * Serialized form, for persistence in a future auction's snapshot column.
     *
     * Money is written as minor units plus currency so it survives a round
     * trip with no precision loss and no locale dependence.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'winner_rule' => self::WINNER_RULE,

            'minimum_bid_credits' => $this->minimumBidCredits,
            'minimum_bid_increment_credits' => $this->minimumBidIncrementCredits,
            'allow_bid_increase' => $this->allowBidIncrease,
            'minimum_bid_interval_ms' => $this->minimumBidIntervalMs,

            'base_duration_seconds' => $this->baseDurationSeconds,
            'closing_window_seconds' => $this->closingWindowSeconds,
            'extension_seconds' => $this->extensionSeconds,
            'max_extensions' => $this->maxExtensions,
            'max_extension_total_seconds' => $this->maxExtensionTotalSeconds,

            'buy_now_enabled' => $this->buyNowEnabled,
            'buy_now_credit_discount_enabled' => $this->buyNowCreditDiscountEnabled,
            'buy_now_credit_discount_minor_per_credit' => $this->buyNowCreditDiscountMinorPerCredit,

            'checkout_deadline_minutes' => $this->checkoutDeadlineMinutes,
            'forfeit_policy' => $this->forfeitPolicy->value,

            'delivery_fee_minor' => $this->deliveryFee->minor,
            'currency' => $this->deliveryFee->currency,
            'tax_bps' => $this->taxBps,

            'ruleset_id' => $this->rulesetId,
            'ruleset_name' => $this->rulesetName,
            'ruleset_version' => $this->rulesetVersion,
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

        $currency = (string) ($data['currency'] ?? 'GHS');

        return new self(
            minimumBidCredits: isset($data['minimum_bid_credits']) ? (int) $data['minimum_bid_credits'] : null,
            minimumBidIncrementCredits: isset($data['minimum_bid_increment_credits'])
                ? (int) $data['minimum_bid_increment_credits']
                : null,
            allowBidIncrease: isset($data['allow_bid_increase']) ? (bool) $data['allow_bid_increase'] : null,
            minimumBidIntervalMs: (int) $data['minimum_bid_interval_ms'],

            baseDurationSeconds: (int) $data['base_duration_seconds'],
            closingWindowSeconds: (int) $data['closing_window_seconds'],
            extensionSeconds: (int) $data['extension_seconds'],
            maxExtensions: (int) $data['max_extensions'],
            maxExtensionTotalSeconds: (int) $data['max_extension_total_seconds'],

            buyNowEnabled: (bool) $data['buy_now_enabled'],
            buyNowCreditDiscountEnabled: (bool) $data['buy_now_credit_discount_enabled'],
            buyNowCreditDiscountMinorPerCredit: (int) $data['buy_now_credit_discount_minor_per_credit'],

            checkoutDeadlineMinutes: (int) $data['checkout_deadline_minutes'],
            forfeitPolicy: ForfeitPolicy::from((string) $data['forfeit_policy']),

            deliveryFee: Money::fromMinor((int) $data['delivery_fee_minor'], $currency),
            taxBps: (int) $data['tax_bps'],

            rulesetId: isset($data['ruleset_id']) ? (int) $data['ruleset_id'] : null,
            rulesetName: isset($data['ruleset_name']) ? (string) $data['ruleset_name'] : null,
            rulesetVersion: isset($data['ruleset_version']) ? (int) $data['ruleset_version'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Structural invariants that must hold for any rule set, however built.
     */
    private function assertValid(): void
    {
        if ($this->minimumBidCredits !== null && $this->minimumBidCredits < 1) {
            throw InvalidAuctionRules::because('A minimum bid, if set, must be at least 1 credit.');
        }

        if ($this->minimumBidIncrementCredits !== null && $this->minimumBidIncrementCredits < 1) {
            throw InvalidAuctionRules::because('A minimum bid increment, if set, must be at least 1 credit.');
        }

        if ($this->minimumBidIntervalMs < 0) {
            throw InvalidAuctionRules::because('Minimum bid interval cannot be negative.');
        }

        if ($this->baseDurationSeconds < 1) {
            throw InvalidAuctionRules::because('Base duration must be greater than zero.');
        }

        if ($this->closingWindowSeconds < 0) {
            throw InvalidAuctionRules::because('Closing window cannot be negative.');
        }

        if ($this->extensionSeconds < 0) {
            throw InvalidAuctionRules::because('Extension seconds cannot be negative.');
        }

        if ($this->maxExtensions < 0) {
            throw InvalidAuctionRules::because('Maximum extensions cannot be negative.');
        }

        if ($this->maxExtensionTotalSeconds < 0) {
            throw InvalidAuctionRules::because('Maximum total extension cannot be negative.');
        }

        if ($this->checkoutDeadlineMinutes < 1) {
            throw InvalidAuctionRules::because('Checkout deadline must be greater than zero.');
        }

        if ($this->buyNowCreditDiscountMinorPerCredit < 1) {
            throw InvalidAuctionRules::because(
                'The Buy Now credit discount rate must be greater than zero when a rate is stored.'
            );
        }

        if ($this->taxBps < 0) {
            throw InvalidAuctionRules::because('Tax cannot be negative.');
        }

        if ($this->taxBps > 10_000) {
            throw InvalidAuctionRules::because('Tax cannot exceed 100% (10000 basis points).');
        }

        if ($this->deliveryFee->isNegative()) {
            throw InvalidAuctionRules::because('Delivery fee cannot be negative.');
        }

        // A closing window longer than the auction itself would put every
        // auction into its extension phase from the moment it opened.
        if ($this->closingWindowSeconds > $this->baseDurationSeconds) {
            throw InvalidAuctionRules::because(
                'Closing window cannot be longer than the base duration.'
            );
        }

        // Only meaningful when extensions are actually switched on: a total
        // budget smaller than one extension could never grant even one.
        if ($this->maxExtensions > 0 && $this->extensionSeconds > 0
            && $this->maxExtensionTotalSeconds < $this->extensionSeconds) {
            throw InvalidAuctionRules::because(
                'Maximum total extension must be at least one extension long, or extensions must be disabled.'
            );
        }

        // Extensions are triggered by bids inside the closing window. With no
        // window there is no trigger, so they could never fire.
        if ($this->maxExtensions > 0 && $this->extensionSeconds > 0
            && $this->closingWindowSeconds === 0) {
            throw InvalidAuctionRules::because(
                'Extensions require a closing window greater than zero, otherwise they can never trigger.'
            );
        }

        // A discount rate with the discount switched off is harmless, but a
        // rate of zero with it switched on would silently give nothing.
        if ($this->buyNowCreditDiscountEnabled && ! $this->buyNowEnabled) {
            throw InvalidAuctionRules::because(
                'A Buy Now credit discount cannot be enabled while Buy Now itself is disabled.'
            );
        }
    }
}
