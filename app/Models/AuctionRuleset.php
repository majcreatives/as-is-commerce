<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Auction\Exceptions\InvalidAuctionRules;
use App\Domain\Auction\RulesetResolver;
use App\Domain\Auction\ValueObjects\AuctionRules;
use App\Domain\Shared\Money\Money;
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
 * @property int $bid_cost_credits
 * @property bool $unique_leader
 * @property int $minimum_bid_interval_ms
 * @property int $base_duration_seconds
 * @property int $closing_window_seconds
 * @property int $extension_seconds
 * @property int $max_extensions
 * @property int $max_extension_total_seconds
 * @property int $checkout_deadline_minutes
 * @property ForfeitPolicy $forfeit_policy
 * @property int|null $default_checkout_price_minor
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
     * Status, versioning and audit columns are excluded on purpose: they are
     * lifecycle state, changed only by the dedicated actions, never by a mass
     * assignment from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'bid_cost_credits',
        'unique_leader',
        'minimum_bid_interval_ms',
        'base_duration_seconds',
        'closing_window_seconds',
        'extension_seconds',
        'max_extensions',
        'max_extension_total_seconds',
        'checkout_deadline_minutes',
        'forfeit_policy',
        'default_checkout_price_minor',
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
            'forfeit_policy' => ForfeitPolicy::class,
            'unique_leader' => 'boolean',
            'is_default' => 'boolean',
            'version' => 'integer',
            'bid_cost_credits' => 'integer',
            'minimum_bid_interval_ms' => 'integer',
            'base_duration_seconds' => 'integer',
            'closing_window_seconds' => 'integer',
            'extension_seconds' => 'integer',
            'max_extensions' => 'integer',
            'max_extension_total_seconds' => 'integer',
            'checkout_deadline_minutes' => 'integer',
            'default_checkout_price_minor' => 'integer',
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

    public function defaultCheckoutPrice(): ?Money
    {
        return $this->default_checkout_price_minor === null
            ? null
            : Money::fromMinor($this->default_checkout_price_minor, $this->currency);
    }

    public function deliveryFee(): Money
    {
        return Money::fromMinor($this->delivery_fee_minor, $this->currency);
    }

    // ------------------------------------------------------------- Snapshot

    /**
     * Produce the immutable rules an auction will be created with.
     *
     * The checkout price is the one value a ruleset cannot supply on its own,
     * because it belongs to the product being auctioned rather than to the
     * rules. Callers pass it explicitly; the ruleset's default is used only
     * when no override is given, and it is an error for both to be absent.
     *
     * The returned object shares no state with this model. Changing, archiving
     * or deleting the ruleset afterwards has no effect on it.
     */
    public function toRules(?Money $checkoutPrice = null): AuctionRules
    {
        $price = $checkoutPrice ?? $this->defaultCheckoutPrice();

        if ($price === null) {
            throw InvalidAuctionRules::because(
                "Ruleset [{$this->name} v{$this->version}] has no default checkout price, "
                .'so an auction created from it must supply one.'
            );
        }

        return new AuctionRules(
            bidCostCredits: $this->bid_cost_credits,
            uniqueLeader: $this->unique_leader,
            minimumBidIntervalMs: $this->minimum_bid_interval_ms,

            baseDurationSeconds: $this->base_duration_seconds,
            closingWindowSeconds: $this->closing_window_seconds,
            extensionSeconds: $this->extension_seconds,
            maxExtensions: $this->max_extensions,
            maxExtensionTotalSeconds: $this->max_extension_total_seconds,

            checkoutDeadlineMinutes: $this->checkout_deadline_minutes,
            forfeitPolicy: $this->forfeit_policy,

            checkoutPrice: $price,
            deliveryFee: Money::fromMinor($this->delivery_fee_minor, $price->currency),
            taxBps: $this->tax_bps,

            rulesetId: $this->id,
            rulesetName: $this->name,
            rulesetVersion: $this->version,
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
                'bid_cost_credits',
                'unique_leader',
                'minimum_bid_interval_ms',
                'base_duration_seconds',
                'closing_window_seconds',
                'extension_seconds',
                'max_extensions',
                'max_extension_total_seconds',
                'checkout_deadline_minutes',
                'forfeit_policy',
                'default_checkout_price_minor',
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
