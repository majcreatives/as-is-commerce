<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Auction\Services\HighestBidResolver;
use App\Domain\Auction\ValueObjects\AuctionRules;
use App\Domain\Auction\ValueObjects\AuctionSnapshot;
use App\Domain\Shared\Money\Money;
use App\Enums\AuctionClosureReason;
use App\Enums\AuctionStatus;
use App\Models\Concerns\GuardsFrozenAuctionConfiguration;
use App\Models\Concerns\GuardsMaterializedHighestBid;
use Database\Factories\AuctionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One auction: a product, offered under a frozen set of terms, for a period.
 *
 * THE WINNER RULE. When an auction closes on the clock, the user holding the
 * highest valid credit bid wins -- not the last bidder, not the most frequent,
 * not whoever held the lead longest. A bidder overtaken and later bidding
 * higher still wins on that highest bid. The rule is written into every
 * snapshot rather than implied by code.
 *
 * BUY NOW OVERRIDES IT. A successful Buy Now ends the auction immediately and
 * the standing highest bidder does not win. That ending is recorded
 * distinctly: `closure_reason` says `buy_now`, the buyer goes in
 * `buy_now_user_id`, and `winner_user_id` and `winning_bid_id` stay null. A
 * database constraint refuses any row claiming both.
 *
 * THREE SEPARATE NUMBERS, none derived from another:
 *
 *   $auction->product->buyNowPrice()   what the product costs outright
 *   $auction->settlementAmount()       what a normal winner pays
 *   $auction->highest_bid_credits      credits committed to the leading bid
 *
 * The credits are a count, not money. The only sanctioned conversion is the
 * Buy Now discount in the frozen snapshot, and it reduces the Buy Now price
 * alone.
 *
 * TIME. `starts_at` and `ends_at` are the sole authority on when this auction
 * runs. No browser clock, JavaScript timer or process lifetime affects it. All
 * timestamps are UTC.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $auction_ruleset_id
 * @property array<string, mixed> $rules_snapshot
 * @property int $snapshot_version
 * @property int $settlement_amount_minor
 * @property string $currency
 * @property AuctionStatus $status
 * @property AuctionClosureReason|null $closure_reason
 * @property Carbon|null $scheduled_start_at
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $closing_started_at
 * @property int $extensions_applied
 * @property int $extension_seconds_applied
 * @property int|null $highest_bid_id
 * @property int|null $highest_bid_credits
 * @property int $bid_count
 * @property int|null $winner_user_id
 * @property int|null $winning_bid_id
 * @property Carbon|null $settlement_due_at
 * @property Carbon|null $settled_at
 * @property Carbon|null $buy_now_ended_at
 * @property int|null $buy_now_user_id
 * @property int|null $buy_now_eligible_credits
 * @property int|null $buy_now_discount_minor
 * @property int|null $buy_now_payable_minor
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $forfeited_at
 */
class Auction extends Model
{
    /** @use HasFactory<AuctionFactory> */
    use GuardsFrozenAuctionConfiguration, GuardsMaterializedHighestBid, HasFactory, LogsActivity;

    /**
     * Nothing is mass assignable.
     *
     * Every column here is either frozen configuration, lifecycle state
     * written by the lifecycle service, or a projection of the bid records.
     * None of them may be set from request input, and an empty fillable list
     * says so more reliably than remembering to leave each one out.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rules_snapshot' => 'array',
            'status' => AuctionStatus::class,
            'closure_reason' => AuctionClosureReason::class,
            'snapshot_version' => 'integer',
            'settlement_amount_minor' => 'integer',
            'extensions_applied' => 'integer',
            'extension_seconds_applied' => 'integer',
            'highest_bid_credits' => 'integer',
            'bid_count' => 'integer',
            'buy_now_eligible_credits' => 'integer',
            'buy_now_discount_minor' => 'integer',
            'buy_now_payable_minor' => 'integer',
            'scheduled_start_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'closing_started_at' => 'datetime',
            'settlement_due_at' => 'datetime',
            'settled_at' => 'datetime',
            'buy_now_ended_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'forfeited_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------ Snapshot

    /**
     * The frozen terms this auction runs under.
     *
     * Rebuilt from the stored JSON, never from the ruleset row. An
     * administrator editing or archiving that ruleset has no effect here.
     */
    public function snapshot(): AuctionSnapshot
    {
        return AuctionSnapshot::fromArray($this->rules_snapshot);
    }

    /**
     * The rule half of the snapshot, which is what most callers want.
     */
    public function rules(): AuctionRules
    {
        return $this->snapshot()->rules;
    }

    /**
     * How this auction picks its winner, read from its own frozen record.
     */
    public function winnerRule(): string
    {
        return $this->snapshot()->winnerRule();
    }

    // --------------------------------------------------------------- Money

    /**
     * What a normal highest-bid winner owes, before delivery and tax.
     *
     * Never the product's Buy Now price, and never a function of the winning
     * bid. A winner who committed 180 credits owes this figure -- not GH 180.
     */
    public function settlementAmount(): Money
    {
        return Money::fromMinor($this->settlement_amount_minor, $this->currency);
    }

    /**
     * Settlement plus the charges the frozen rules attach to it.
     */
    public function settlementTotal(): Money
    {
        return $this->snapshot()->settlementTotal();
    }

    // --------------------------------------------------------------- State

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function hasEnded(): bool
    {
        return ! $this->status->isOpen() && $this->status !== AuctionStatus::Draft
            && $this->status !== AuctionStatus::Scheduled;
    }

    /**
     * Whether this auction ended because someone bought the product outright.
     *
     * Read from the recorded reason, not inferred from the presence of a
     * buyer or from the absence of a winner.
     */
    public function endedByBuyNow(): bool
    {
        return $this->closure_reason === AuctionClosureReason::BuyNow;
    }

    /**
     * Whether this auction produced a highest-bid winner.
     */
    public function hasBidWinner(): bool
    {
        return $this->closure_reason?->producedBidWinner() === true
            && $this->winner_user_id !== null;
    }

    /**
     * Whether Buy Now is switched on for this auction at all.
     *
     * A frozen decision, separate from whether it can be used right now --
     * which also depends on the auction still being open.
     */
    public function buyNowEnabled(): bool
    {
        return $this->rules()->buyNowEnabled;
    }

    // -------------------------------------------------------- Relationships

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The ruleset this auction was created from. Lineage only.
     *
     * The engine never reads it: behaviour comes from {@see self::snapshot()}.
     *
     * @return BelongsTo<AuctionRuleset, $this>
     */
    public function ruleset(): BelongsTo
    {
        return $this->belongsTo(AuctionRuleset::class, 'auction_ruleset_id');
    }

    /**
     * @return HasMany<Bid, $this>
     */
    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    /**
     * @return HasMany<AuctionTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(AuctionTransition::class);
    }

    /**
     * The projected leading bid.
     *
     * Convenient, but not authoritative: {@see HighestBidResolver::highestBid()}
     * is, and it reads the bid records.
     *
     * @return BelongsTo<Bid, $this>
     */
    public function highestBid(): BelongsTo
    {
        return $this->belongsTo(Bid::class, 'highest_bid_id');
    }

    /**
     * @return BelongsTo<Bid, $this>
     */
    public function winningBid(): BelongsTo
    {
        return $this->belongsTo(Bid::class, 'winning_bid_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function buyNowBuyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buy_now_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // -------------------------------------------------------------- Scopes

    /**
     * Auctions the public may see. Drafts never appear.
     *
     * A single scope rather than a status check repeated at each call site,
     * so an unpublished auction cannot leak through a query someone forgot to
     * filter.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->whereIn('status', AuctionStatus::publiclyVisibleCases());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [AuctionStatus::Live, AuctionStatus::Closing]);
    }

    /**
     * Auctions whose clock has run out and which are still open.
     *
     * The work queue for the closing sweep.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDueToClose(Builder $query): Builder
    {
        return $query->open()->where('ends_at', '<=', now());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDueToStart(Builder $query): Builder
    {
        return $query->where('status', AuctionStatus::Scheduled)
            ->where('scheduled_start_at', '<=', now());
    }

    // ----------------------------------------------------------- Audit log

    public function getActivitylogOptions(): LogOptions
    {
        // Deliberately narrow. The lifecycle and the bids have their own
        // dedicated records; duplicating them here would store the same
        // evidence twice without making it any more useful.
        return LogOptions::defaults()
            ->useLogName('auction')
            ->logOnly([
                'status',
                'closure_reason',
                'starts_at',
                'ends_at',
                'winner_user_id',
                'winning_bid_id',
                'buy_now_user_id',
                'settlement_amount_minor',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        return "Auction #{$this->id} was {$eventName}";
    }
}
