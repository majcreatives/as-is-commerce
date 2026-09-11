<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Money\Money;
use App\Enums\CreditPurchaseStatus;
use App\Enums\PaymentProvider;
use Database\Factories\CreditPurchaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * One customer's purchase of one credit package.
 *
 * Carries an immutable snapshot of what was bought, taken when the transaction
 * was opened. Fulfilment reads the snapshot and never the package record: an
 * administrator repricing a package tomorrow must not change how many credits
 * a purchase made today grants, or what it was worth.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $credit_package_id
 * @property string $package_name_snapshot
 * @property int $credit_amount
 * @property int $amount_minor
 * @property string $currency
 * @property CreditPurchaseStatus $status
 * @property PaymentProvider $payment_provider
 * @property string $provider_reference
 * @property int|null $provider_transaction_id
 * @property string|null $provider_channel
 * @property string $idempotency_key
 * @property Carbon|null $paid_at
 * @property Carbon|null $fulfilled_at
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $metadata
 */
class CreditPurchase extends Model
{
    /** @use HasFactory<CreditPurchaseFactory> */
    use HasFactory;

    /**
     * Status and provider fields are excluded on purpose: they are lifecycle
     * state, moved only by the purchase actions through guarded transitions,
     * never by a mass assignment from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'credit_package_id',
        'package_name_snapshot',
        'credit_amount',
        'amount_minor',
        'currency',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CreditPurchaseStatus::class,
            'payment_provider' => PaymentProvider::class,
            'credit_amount' => 'integer',
            'amount_minor' => 'integer',
            'provider_transaction_id' => 'integer',
            'paid_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * The amount the customer agreed to pay, from the snapshot.
     */
    public function amount(): Money
    {
        return Money::fromMinor($this->amount_minor, $this->currency);
    }

    /**
     * Whether credits have been granted against this purchase.
     *
     * @phpstan-impure  The answer is whatever the row currently holds. Two
     *                  reads of the same object can legitimately differ, and
     *                  the state machine's writers re-read under a lock before
     *                  trusting the figure.
     */
    public function isFulfilled(): bool
    {
        return $this->status === CreditPurchaseStatus::Fulfilled;
    }

    public function isAwaitingPayment(): bool
    {
        return in_array(
            $this->status,
            [CreditPurchaseStatus::Pending, CreditPurchaseStatus::PaymentProcessing],
            true,
        );
    }

    // --------------------------------------------------------- Relationships

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<CreditPackage, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(CreditPackage::class, 'credit_package_id');
    }

    /**
     * @return HasMany<CreditPurchaseTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(CreditPurchaseTransition::class);
    }

    /**
     * @return HasMany<PaymentWebhookEvent, $this>
     */
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(PaymentWebhookEvent::class);
    }

    /**
     * The credit ledger entries this purchase produced.
     *
     * Uses the polymorphic reference established in the ledger stage, so a
     * purchase and the credits it granted are linked in both directions.
     *
     * @return MorphMany<CreditTransaction, $this>
     */
    public function creditTransactions(): MorphMany
    {
        return $this->morphMany(CreditTransaction::class, 'reference');
    }

    /**
     * @return MorphMany<CashTransaction, $this>
     */
    public function cashTransactions(): MorphMany
    {
        return $this->morphMany(CashTransaction::class, 'reference');
    }

    // ---------------------------------------------------------------- Scopes

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFulfilled(Builder $query): Builder
    {
        return $query->where('status', CreditPurchaseStatus::Fulfilled);
    }

    public function getRouteKeyName(): string
    {
        return 'provider_reference';
    }
}
