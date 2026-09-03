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
 * This is the object the auction engine will read from, and it is deliberately
 * a value object rather than an Eloquent model. Once an auction is created its
 * rules are frozen: the auction stores a serialized copy of this object, not a
 * foreign key to a mutable `auction_rulesets` row. An administrator editing
 * global defaults tomorrow therefore cannot alter how an auction that is
 * already running behaves, or how a closed auction is explained months later.
 *
 * Every value is an integer or an integer-backed Money. Nothing here is a
 * float, and no duration is a formatted string.
 *
 * Invariants are enforced in the constructor as well as in the form layer.
 * The form layer produces friendly messages for administrators; this layer
 * guarantees that an invalid rule set cannot exist at all, including when
 * constructed from a snapshot, a console command or a future import.
 */
final readonly class AuctionRules implements JsonSerializable
{
    /**
     * Incremented if the serialized shape ever changes, so historical
     * snapshots can still be read after a schema evolution.
     */
    public const SNAPSHOT_VERSION = 1;

    public function __construct(
        // Bidding
        public int $bidCostCredits,
        public bool $uniqueLeader,
        public int $minimumBidIntervalMs,

        // Timing (integer seconds; the engine is server-authoritative)
        public int $baseDurationSeconds,
        public int $closingWindowSeconds,
        public int $extensionSeconds,
        public int $maxExtensions,
        public int $maxExtensionTotalSeconds,

        // Winner and checkout
        public int $checkoutDeadlineMinutes,
        public ForfeitPolicy $forfeitPolicy,

        // Pricing (integer minor units)
        public Money $checkoutPrice,
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
        return $this->checkoutPrice->currency;
    }

    /**
     * Whether the closing window can ever extend this auction.
     *
     * False when extensions are switched off by any of the three limits, which
     * lets the engine skip the extension path entirely.
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
     * Both limits apply, so the smaller of the two governs.
     */
    public function maximumPossibleDurationSeconds(): int
    {
        if (! $this->extensionsEnabled()) {
            return $this->baseDurationSeconds;
        }

        $byCount = $this->maxExtensions * $this->extensionSeconds;

        return $this->baseDurationSeconds + min($byCount, $this->maxExtensionTotalSeconds);
    }

    /**
     * Serialized form for persistence in a future `auctions.rules_snapshot`
     * JSON column.
     *
     * Money is written as minor units plus currency so the value survives a
     * round trip with no precision loss and no locale dependence.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'snapshot_version' => self::SNAPSHOT_VERSION,

            'bid_cost_credits' => $this->bidCostCredits,
            'unique_leader' => $this->uniqueLeader,
            'minimum_bid_interval_ms' => $this->minimumBidIntervalMs,

            'base_duration_seconds' => $this->baseDurationSeconds,
            'closing_window_seconds' => $this->closingWindowSeconds,
            'extension_seconds' => $this->extensionSeconds,
            'max_extensions' => $this->maxExtensions,
            'max_extension_total_seconds' => $this->maxExtensionTotalSeconds,

            'checkout_deadline_minutes' => $this->checkoutDeadlineMinutes,
            'forfeit_policy' => $this->forfeitPolicy->value,

            'checkout_price_minor' => $this->checkoutPrice->minor,
            'delivery_fee_minor' => $this->deliveryFee->minor,
            'currency' => $this->checkoutPrice->currency,
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
            bidCostCredits: (int) $data['bid_cost_credits'],
            uniqueLeader: (bool) $data['unique_leader'],
            minimumBidIntervalMs: (int) $data['minimum_bid_interval_ms'],

            baseDurationSeconds: (int) $data['base_duration_seconds'],
            closingWindowSeconds: (int) $data['closing_window_seconds'],
            extensionSeconds: (int) $data['extension_seconds'],
            maxExtensions: (int) $data['max_extensions'],
            maxExtensionTotalSeconds: (int) $data['max_extension_total_seconds'],

            checkoutDeadlineMinutes: (int) $data['checkout_deadline_minutes'],
            forfeitPolicy: ForfeitPolicy::from((string) $data['forfeit_policy']),

            checkoutPrice: Money::fromMinor((int) $data['checkout_price_minor'], $currency),
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
        if ($this->bidCostCredits < 1) {
            throw InvalidAuctionRules::because('Bid cost must be at least 1 credit.');
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

        if ($this->taxBps < 0) {
            throw InvalidAuctionRules::because('Tax cannot be negative.');
        }

        if ($this->taxBps > 10_000) {
            throw InvalidAuctionRules::because('Tax cannot exceed 100% (10000 basis points).');
        }

        if (! $this->checkoutPrice->isPositive()) {
            throw InvalidAuctionRules::because('Checkout price must be greater than zero.');
        }

        if ($this->deliveryFee->isNegative()) {
            throw InvalidAuctionRules::because('Delivery fee cannot be negative.');
        }

        if ($this->deliveryFee->currency !== $this->checkoutPrice->currency) {
            throw InvalidAuctionRules::because('Delivery fee and checkout price must use the same currency.');
        }

        // A closing window longer than the auction itself would put every
        // auction in its extension phase from the moment it opened.
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

        // Last Bidder Standing needs a closing window to have any extension
        // behaviour at all; extensions configured without one can never fire.
        if ($this->maxExtensions > 0 && $this->extensionSeconds > 0
            && $this->closingWindowSeconds === 0) {
            throw InvalidAuctionRules::because(
                'Extensions require a closing window greater than zero, otherwise they can never trigger.'
            );
        }
    }
}
