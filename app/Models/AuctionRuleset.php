<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Auction\RulesetResolver;
use App\Domain\Auction\ValueObjects\AuctionRules;
use App\Domain\Shared\Money\Money;
use App\Enums\BidModel;
use App\Enums\ForfeitPolicy;
use App\Enums\RulesetStatus;
use Database\Factories\AuctionRulesetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A named, versioned set of auction defaults.
 *
 * This model is mutable configuration. It is never read directly by the
 * auction engine -- an auction takes an immutable {@see AuctionRules} snapshot
 * at creation time via {@see self::toRules()}, so editing or archiving a
 * ruleset later cannot rewrite the behaviour of auctions already created
 * from it.
 *
 * @property int $id
 * @property string $name
 * @property int $version
 * @property string|null $description
 * @property RulesetStatus $status
 * @property int|null $minimum_bid_credits
 * @property int|null $minimum_bid_increment_credits
 * @property bool|null $allow_bid_increase
 * @property BidModel $bid_model
 * @property int $minimum_bid_interval_ms
 * @property int $base_duration_seconds
 * @property int $closing_window_seconds
 * @property int $extension_seconds
 * @property int $max_extensions
 * @property int $max_extension_total_seconds
 * @property int $checkout_deadline_minutes
 * @property ForfeitPolicy $forfeit_policy
 * @property bool $buy_now_enabled
 * @property bool $buy_now_credit_discount_enabled
 * @property int $delivery_fee_minor
 * @property string $currency
 * @property int $tax_bps
 * @property bool $is_default
 * @property Carbon|null $activated_at
 * @property Carbon|null $archived_at
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class AuctionRuleset extends Model
{
    /** @use HasFactory<AuctionRulesetFactory> */
    use HasFactory, LogsActivity;

    /**
     * Columns MySQL computes itself, which back the partial-unique indexes
     * enforcing "one active version per name" and "one global default".
     *
     * They are selected like any other column, so they must be excluded from
     * anything that writes a full attribute set -- replication in particular,
     * since MySQL rejects an INSERT that supplies a generated column's value.
     *
     * @var list<string>
     */
    public const GENERATED_COLUMNS = ['active_name', 'default_marker'];

    /**
     * A ruleset built in code carries the model the column defaults to, so
     * reading it back never trips over an attribute that was never loaded.
     * Existing rulesets are single-highest and stay so.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'bid_model' => 'single_highest',
    ];

    /**
     * Status, versioning and audit columns are excluded on purpose: they are
     * lifecycle state, changed only by the dedicated actions, never by a mass
     * assignment from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        // `bid_model` is deliberately not fillable. Which bidding model a
        // ruleset produces is chosen by code that means it, never by a form
        // field or a request, so a crafted payload cannot flip a ruleset into
        // producing a different kind of auction.
        'minimum_bid_credits',
        'minimum_bid_increment_credits',
        'allow_bid_increase',
        'minimum_bid_interval_ms',
        'base_duration_seconds',
        'closing_window_seconds',
        'extension_seconds',
        'max_extensions',
        'max_extension_total_seconds',
        'checkout_deadline_minutes',
        'forfeit_policy',
        'buy_now_enabled',
        'buy_now_credit_discount_enabled',
        'delivery_fee_minor',
        'currency',
        'tax_bps',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RulesetStatus::class,
            'bid_model' => BidModel::class,
            'forfeit_policy' => ForfeitPolicy::class,
            'is_default' => 'boolean',
            'allow_bid_increase' => 'boolean',
            'buy_now_enabled' => 'boolean',
            'buy_now_credit_discount_enabled' => 'boolean',
            'version' => 'integer',
            'minimum_bid_credits' => 'integer',
            'minimum_bid_increment_credits' => 'integer',
            'minimum_bid_interval_ms' => 'integer',
            'base_duration_seconds' => 'integer',
            'closing_window_seconds' => 'integer',
            'extension_seconds' => 'integer',
            'max_extensions' => 'integer',
            'max_extension_total_seconds' => 'integer',
            'checkout_deadline_minutes' => 'integer',
            'delivery_fee_minor' => 'integer',
            'tax_bps' => 'integer',
            'activated_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Keep the resolver's cached default in step with the table.
     *
     * Hooked to model events rather than called from each action, so any
     * path that writes a ruleset -- an action, a console command, a future
     * import -- invalidates the cache without having to remember to.
     */
    protected static function booted(): void
    {
        $flush = static function (): void {
            app(RulesetResolver::class)->flush();
        };

        static::saved($flush);
        static::deleted($flush);
    }

    // ---------------------------------------------------------------- Money

    public function deliveryFee(): Money
    {
        return Money::fromMinor($this->delivery_fee_minor, $this->currency);
    }

    // ------------------------------------------------------------- Snapshot

    /**
     * Produce the immutable rules an auction will be created with.
     *
     * Takes no arguments. It previously required a checkout price, which was
     * the amount a winner paid -- but what a normal auction winner pays has
     * deliberately not been decided, so the rules carry no settlement amount
     * at all and the engine must not assume one.
     *
     * The returned object shares no state with this model. Changing, archiving
     * or deleting the ruleset afterwards has no effect on it.
     */
    public function toRules(): AuctionRules
    {
        return new AuctionRules(
            minimumBidCredits: $this->minimum_bid_credits,
            minimumBidIncrementCredits: $this->minimum_bid_increment_credits,
            allowBidIncrease: $this->allow_bid_increase,
            minimumBidIntervalMs: $this->minimum_bid_interval_ms,

            baseDurationSeconds: $this->base_duration_seconds,
            closingWindowSeconds: $this->closing_window_seconds,
            extensionSeconds: $this->extension_seconds,
            maxExtensions: $this->max_extensions,
            maxExtensionTotalSeconds: $this->max_extension_total_seconds,

            buyNowEnabled: $this->buy_now_enabled,
            buyNowCreditDiscountEnabled: $this->buy_now_credit_discount_enabled,

            checkoutDeadlineMinutes: $this->checkout_deadline_minutes,
            forfeitPolicy: $this->forfeit_policy,

            deliveryFee: $this->deliveryFee(),
            taxBps: $this->tax_bps,

            rulesetId: $this->id,
            rulesetName: $this->name,
            rulesetVersion: $this->version,

            bidModel: $this->bid_model,
        );
    }

    // --------------------------------------------------------------- State

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    // ------------------------------------------------------------- Scopes

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RulesetStatus::Active);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', RulesetStatus::Draft);
    }

    // ------------------------------------------------------- Relationships

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ----------------------------------------------------------- Audit log

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('auction_ruleset')
            ->logOnly([
                'name',
                'version',
                'status',
                'minimum_bid_credits',
                'minimum_bid_increment_credits',
                'allow_bid_increase',
                'bid_model',
                'minimum_bid_interval_ms',
                'base_duration_seconds',
                'closing_window_seconds',
                'extension_seconds',
                'max_extensions',
                'max_extension_total_seconds',
                'checkout_deadline_minutes',
                'forfeit_policy',
                'buy_now_enabled',
                'buy_now_credit_discount_enabled',
                'delivery_fee_minor',
                'currency',
                'tax_bps',
                'is_default',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        return "Ruleset [{$this->name} v{$this->version}] was {$eventName}";
    }
}
