<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReferralStatus;
use Database\Factories\ReferralFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One customer introduced another, and what came of it.
 *
 * THE RELATIONSHIP IS PERMANENT. Who introduced whom is a historical fact: a
 * database trigger refuses any change to the pair or to the code that was
 * used, and a unique index on `referred_user_id` means a customer has one
 * referrer for ever. Re-attribution is not something an administrator does
 * carefully -- it is something no code path can do at all.
 *
 * ATTRIBUTION IS NOT A REWARD. A row here means somebody registered through a
 * link. No credits exist until the referred customer makes a qualifying
 * purchase, and even then the reward is a separate, recorded act.
 *
 * THE REWARD LIVES HERE, AND IN THE LEDGER. `reward_credits` is a snapshot of
 * what was granted, and `credit_transaction_id` points at the ledger row the
 * credits actually came in on. The ledger is authoritative: this is the
 * explanation, not the balance. There is no referral wallet anywhere on this
 * platform, and no column here could hold one.
 *
 * NOTHING IS EVER CLAWED BACK. If a qualifying purchase is later refunded, or
 * an administrator decides a referral was fraudulent, the credits stay where
 * they are -- possibly already spent on bids that cannot be unwound. The
 * decision is recorded; the ledger is not rewritten.
 *
 * @property int $id
 * @property int $referrer_user_id
 * @property int $referred_user_id
 * @property string $code_used
 * @property ReferralStatus $status
 * @property int|null $qualifying_order_id
 * @property int|null $reward_credits
 * @property int|null $credit_transaction_id
 * @property string|null $invalidation_reason
 * @property int|null $invalidated_by
 * @property Carbon $attributed_at
 * @property Carbon|null $qualified_at
 * @property Carbon|null $rewarded_at
 * @property Carbon|null $invalidated_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 */
class Referral extends Model
{
    /** @use HasFactory<ReferralFactory> */
    use HasFactory, LogsActivity;

    /**
     * Nothing is mass assignable.
     *
     * The referrer especially: it is resolved from a code on the server, never
     * taken from request input. A fillable referrer is how somebody credits
     * themselves with an introduction they did not make.
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
            'status' => ReferralStatus::class,
            'reward_credits' => 'integer',
            'metadata' => 'array',
            'attributed_at' => 'datetime',
            'qualified_at' => 'datetime',
            'rewarded_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------- State

    public function isRewarded(): bool
    {
        return $this->status->isRewarded();
    }

    public function isOutstanding(): bool
    {
        return $this->status->isOutstanding();
    }

    /**
     * Whether this referral is waiting for its credits to be issued.
     *
     * The retry queue: something qualified and the reward did not land, which
     * is a recoverable state rather than a lost one.
     */
    public function awaitsReward(): bool
    {
        return $this->status === ReferralStatus::Qualified;
    }

    // -------------------------------------------------------- Relationships

    /**
     * @return BelongsTo<User, $this>
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    /**
     * The order whose verified payment qualified this referral.
     *
     * @return BelongsTo<Order, $this>
     */
    public function qualifyingOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'qualifying_order_id');
    }

    /**
     * The ledger row the reward credits arrived on.
     *
     * The explanation for a balance change, and the thing an auditor follows
     * from a referral to the credit lot the customer actually spends.
     *
     * @return BelongsTo<CreditTransaction, $this>
     */
    public function creditTransaction(): BelongsTo
    {
        return $this->belongsTo(CreditTransaction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invalidatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invalidated_by');
    }

    // -------------------------------------------------------------- Scopes

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRewarded(Builder $query): Builder
    {
        return $query->where('status', ReferralStatus::Rewarded);
    }

    /**
     * Referrals that qualified and have not been paid.
     *
     * The work queue for `referrals:reconcile`.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingReward(Builder $query): Builder
    {
        return $query->where('status', ReferralStatus::Qualified);
    }

    // ----------------------------------------------------------- Audit log

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('referral')
            ->logOnly(['status', 'reward_credits', 'qualifying_order_id', 'credit_transaction_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        return "Referral [{$this->id}] was {$eventName}";
    }
}
