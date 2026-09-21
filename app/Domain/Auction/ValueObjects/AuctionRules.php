<?php

declare(strict_types=1);

namespace App\Domain\Auction\ValueObjects;

use App\Domain\Auction\Exceptions\InvalidAuctionRules;
use App\Domain\Shared\Money\Money;
use App\Enums\BidModel;
use App\Enums\ForfeitPolicy;
use JsonSerializable;
use LogicException;

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
     * Version 2 corrected the model from Last Bidder Standing to highest valid
     * credit bid. No version 1 snapshot exists anywhere. Version 3 values the
     * Buy Now credit discount from each credit lot's own acquisition economics
     * rather than from a system-wide rate per credit; the rate column is gone,
     * and a version 2 snapshot is refused rather than reinterpreted.
     *
     * Version 4 records which bidding model the auction follows (`bid_model`),
     * so the winner rule is READ from the frozen snapshot instead of being a
     * constant of the code. Every version 3 snapshot was single-highest, and
     * the migration that rewrites them says so and changes nothing else. A
     * version 3 snapshot is refused rather than reinterpreted.
     */
    public const SNAPSHOT_VERSION = 4;

    /**
     * The name of the single-highest model's winner rule.
     *
     * NOT "the" winner rule of the platform any more: which rule an auction
     * follows is decided by its {@see BidModel}, and asked of
     * {@see self::winnerRule()}. This constant remains as the name the
     * single-highest model has always used, which is what every existing
     * snapshot and test refers to.
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

        // Which bidding model this auction follows. Last, and defaulted, so
        // every existing caller keeps building the single-highest rules it
        // always built; the model is chosen deliberately, never by omission of
        // something else.
        public BidModel $bidModel = BidModel::SingleHighest,

        // The exact step of the cumulative model: how far ahead of the leader a
        // bid must land you. Meaningful ONLY under that model, and null under
        // the other, where `minimumBidIncrementCredits` is the lower bound it
        // has always been. Two fields rather than one reinterpreted, so neither
        // name ever says something the field does not mean.
        public ?int $bidIncrementCredits = null,
    ) {
        $this->assertValid();
    }

    public function currency(): string
    {
        return $this->deliveryFee->currency;
    }

    /**
     * The winner rule, for a caller that would otherwise guess.
     *
     * Derived from the bidding model this auction was frozen with, not a
     * constant: the engine reads how an auction picks its winner from the
     * auction itself.
     */
    public function winnerRule(): string
    {
        return $this->bidModel->winnerRule();
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
        // Under the cumulative model there is no "smallest valid bid": there is
        // exactly one, it depends on who is asking, and it is
        // {@see self::catchUpBid()}. Answering with a floor here would invite a
        // caller to treat a range as valid when only one number is.
        if ($this->bidModel->isCumulative()) {
            return null;
        }

        $floors = [];

        if ($this->minimumBidCredits !== null) {
            $floors[] = $this->minimumBidCredits;
        }

        if ($currentHighestBid !== null && $this->minimumBidIncrementCredits !== null) {
            $floors[] = $currentHighestBid + $this->minimumBidIncrementCredits;
        }

        return $floors === [] ? null : max($floors);
    }

    /**
     * The one bid that is valid for somebody who is NOT leading, under the
     * cumulative model.
     *
     *   nobody has bid yet   the minimum bid: the opening bid, exactly
     *   somebody leads       the leader's total + the step - your own total
     *
     * so that the bidder lands exactly one step ahead. Pure arithmetic on
     * integers, and the single definition: the validator enforces it under the
     * auction lock, and the room displays it, and neither may compute it
     * independently.
     *
     * A leader is not asked. They have no bid to place until somebody overtakes
     * them, which is a fact about who leads and not something arithmetic can
     * express, so the caller decides that before calling.
     *
     * @param  ?int  $leaderTotal  The leader's total, or null when nobody has bid.
     * @param  int  $myTotal  The bidder's own total on this auction; 0 if none.
     *
     * @throws InvalidAuctionRules When called on a model that has no catch-up bid.
     * @throws LogicException When a non-leader's total is not below the leader's --
     *                        an impossible state, which is reported rather than
     *                        turned into a bid.
     */
    public function catchUpBid(?int $leaderTotal, int $myTotal = 0): int
    {
        if (! $this->bidModel->isCumulative()) {
            throw InvalidAuctionRules::because('Only the cumulative model has a catch-up bid.');
        }

        if ($leaderTotal === null) {
            return (int) $this->minimumBidCredits;
        }

        // Totals only ever grow, and a leader is always strictly ahead of
        // everyone else, so a bidder who is not leading is below the leader. A
        // total at or above it means the records disagree with the rule --
        // exactly the case where inventing a bid would compound the damage.
        if ($myTotal >= $leaderTotal) {
            throw new LogicException(
                "A bidder holding {$myTotal} credits is not below the leader's {$leaderTotal}, "
                .'so there is no catch-up bid to compute. The bid records need looking at.'
            );
        }

        return $leaderTotal + (int) $this->bidIncrementCredits - $myTotal;
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

            // The model is the fact; the winner rule is a label derived from
            // it, written so a reader of the raw snapshot sees both -- and
            // checked on the way back in, so the two cannot disagree.
            'bid_model' => $this->bidModel->value,
            'winner_rule' => $this->winnerRule(),

            'minimum_bid_credits' => $this->minimumBidCredits,
            'minimum_bid_increment_credits' => $this->minimumBidIncrementCredits,
            'bid_increment_credits' => $this->bidIncrementCredits,
            'allow_bid_increase' => $this->allowBidIncrease,
            'minimum_bid_interval_ms' => $this->minimumBidIntervalMs,

            'base_duration_seconds' => $this->baseDurationSeconds,
            'closing_window_seconds' => $this->closingWindowSeconds,
            'extension_seconds' => $this->extensionSeconds,
            'max_extensions' => $this->maxExtensions,
            'max_extension_total_seconds' => $this->maxExtensionTotalSeconds,

            'buy_now_enabled' => $this->buyNowEnabled,
            'buy_now_credit_discount_enabled' => $this->buyNowCreditDiscountEnabled,

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

        // A model this engine cannot honour is refused, never ranked as
        // something else. `tryFrom` rather than `from` so the refusal is a
        // domain exception with a reason, not a bare ValueError.
        $storedModel = (string) ($data['bid_model'] ?? '');
        $model = BidModel::tryFrom($storedModel);

        if ($model === null) {
            throw InvalidAuctionRules::because(
                'This snapshot names the bid model "'.$storedModel.'", which this version of '
                .'the engine cannot honour. It is refused rather than ranked as another model.'
            );
        }

        // The winner rule is derived from the model. A snapshot that says one
        // thing in each place is corrupt, and which half to believe is exactly
        // the guess this refuses to make.
        $storedWinnerRule = (string) ($data['winner_rule'] ?? '');

        if ($storedWinnerRule !== $model->winnerRule()) {
            throw InvalidAuctionRules::because(
                'This snapshot records the bid model "'.$model->value.'" but the winner rule '
                .'"'.$storedWinnerRule.'". They disagree, so it is refused rather than guessed at.'
            );
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

            checkoutDeadlineMinutes: (int) $data['checkout_deadline_minutes'],
            forfeitPolicy: ForfeitPolicy::from((string) $data['forfeit_policy']),

            deliveryFee: Money::fromMinor((int) $data['delivery_fee_minor'], $currency),
            taxBps: (int) $data['tax_bps'],

            rulesetId: isset($data['ruleset_id']) ? (int) $data['ruleset_id'] : null,
            rulesetName: isset($data['ruleset_name']) ? (string) $data['ruleset_name'] : null,
            rulesetVersion: isset($data['ruleset_version']) ? (int) $data['ruleset_version'] : null,

            bidModel: $model,
            // Absent from every snapshot written before the cumulative model
            // existed, and null in every single-highest one: read tolerantly,
            // like the other optional rules, so those stay valid untouched.
            bidIncrementCredits: isset($data['bid_increment_credits']) ? (int) $data['bid_increment_credits'] : null,
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

        // A discount switch pointed at a Buy Now that cannot happen is a
        // contradiction. The discount's *value* is computed from the lot
        // records by the pricer, so nothing about the rate is decided here.
        if ($this->buyNowCreditDiscountEnabled && ! $this->buyNowEnabled) {
            throw InvalidAuctionRules::because(
                'A Buy Now credit discount cannot be enabled while Buy Now itself is disabled.'
            );
        }

        $this->assertBidModelFields();
    }

    /**
     * Each model carries its own bid rules and none of the other's.
     *
     * Mixing them would leave a configuration that says two things: a
     * lower-bound increment beside an exact step, or a step on a model that
     * ignores it. Which to believe is the guess this refuses to make.
     */
    private function assertBidModelFields(): void
    {
        if ($this->bidIncrementCredits !== null && $this->bidIncrementCredits < 1) {
            throw InvalidAuctionRules::because('A bid increment, if set, must be at least 1 credit.');
        }

        if (! $this->bidModel->isCumulative()) {
            if ($this->bidIncrementCredits !== null) {
                throw InvalidAuctionRules::because(
                    'A bid increment belongs to the cumulative model. A single-highest auction '
                    .'has a minimum increment instead, and the two mean different things.'
                );
            }

            return;
        }

        // The opening bid is the minimum bid, and nothing else can be: any
        // other figure would be invented, and every later bid is measured
        // from the leader, so this is the only bid with no leader to measure.
        if ($this->minimumBidCredits === null) {
            throw InvalidAuctionRules::because(
                'The cumulative model needs a minimum bid: it is the opening bid.'
            );
        }

        if ($this->bidIncrementCredits === null) {
            throw InvalidAuctionRules::because(
                'The cumulative model needs a bid increment: how far ahead of the leader a bid must land.'
            );
        }

        if ($this->minimumBidIncrementCredits !== null) {
            throw InvalidAuctionRules::because(
                'The cumulative model has a bid increment, not a minimum increment. '
                .'The lower-bound rule belongs to single-highest auctions.'
            );
        }

        // A leader cannot bid under this model, so an option to let them raise
        // their own bid has nothing to switch.
        if ($this->allowBidIncrease !== null) {
            throw InvalidAuctionRules::because(
                'Raising your own bid has no meaning under the cumulative model: a leader has no bid to place.'
            );
        }
    }
}
