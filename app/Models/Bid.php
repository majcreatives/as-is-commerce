<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BidStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One bid: a number of credits a user committed to an auction.
 *
 * VARIABLE AMOUNTS. `amount_credits` is what this bid committed -- 20, 50,
 * 100, 150. There is no fixed cost per bid anywhere in the system, and one
 * bid never means one credit. The bidder chooses the amount, subject to the
 * auction's frozen bid rules.
 *
 * CREDITS ARE GONE. Those credits were consumed when the bid was accepted,
 * permanently, whether this bid wins or loses. Losing bidders get nothing
 * back, and neither does the winner. The one thing consumed credits earn is a
 * reduction of this auction's Buy Now price, at the rate frozen in the
 * auction's snapshot -- and that is a discount on a separate purchase, not a
 * refund.
 *
 * NOT MONEY. 150 credits is not GH 150. Nothing on this model converts an
 * amount into a price, and this table has no monetary column at all.
 *
 * APPEND-ONLY. A bid is never updated or deleted -- database triggers refuse
 * both. It paid for itself with credits that no longer exist, and its amount
 * decides who wins; a bid that could be edited afterwards would be evidence of
 * nothing.
 *
 * @property int $id
 * @property int $auction_id
 * @property int $user_id
 * @property int $amount_credits
 * @property int $sequence
 * @property BidStatus $status
 * @property int $credit_transaction_id
 * @property string|null $idempotency_key
 * @property Carbon $created_at
 */
class Bid extends Model
{
    /**
     * There is no updated_at: a bid is written once and never changes.
     */
    public const UPDATED_AT = null;

    /*
     * There is deliberately no factory.
     *
     * A bid cannot exist without the credit consumption that paid for it --
     * the foreign key to it is NOT NULL -- so a factory that conjured bid rows
     * would be manufacturing exactly the state the engine guarantees is
     * impossible, and tests written against it would prove nothing. Tests
     * place bids through PlaceBid, like everything else does.
     */

    /**
     * Millisecond precision, matching the column.
     *
     * The minimum bid interval is expressed in milliseconds; writing this
     * timestamp at whole-second resolution would quietly round every bid to
     * the nearest second and make that rule unenforceable near its limit.
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * Nothing is mass assignable. A bid is written by exactly one action,
     * inside the transaction that consumes the credits paying for it, and
     * never from request input.
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
            'status' => BidStatus::class,
            'amount_credits' => 'integer',
            'sequence' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Whether this bid is eligible to win.
     */
    public function counts(): bool
    {
        return $this->status->countsTowardsWinning();
    }

    // -------------------------------------------------------- Relationships

    /**
     * @return BelongsTo<Auction, $this>
     */
    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The credit consumption that paid for this bid.
     *
     * The audit chain runs bid -> credit transaction -> lot consumptions ->
     * lots, which is how an auditor answers exactly which credits a user spent
     * bidding on a given auction. It is never reconstructed from a wallet
     * balance, which changes with everything else the user does.
     *
     * @return BelongsTo<CreditTransaction, $this>
     */
    public function creditTransaction(): BelongsTo
    {
        return $this->belongsTo(CreditTransaction::class);
    }

    // -------------------------------------------------------------- Scopes

    /**
     * Bids eligible to win.
     *
     * The single definition, used by the highest-bid query and by the
     * eligible-credit sum, so the two cannot drift into disagreeing about
     * which bids count.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCounting(Builder $query): Builder
    {
        return $query->where('status', BidStatus::Accepted);
    }

    /**
     * Highest first, earliest first among equals.
     *
     * The tie-break is the whole point: two users may commit the same highest
     * amount, and "highest bid wins" has to name one of them. The earlier bid
     * at that amount leads, so the order is total and the same query always
     * returns the same answer.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLeadingFirst(Builder $query): Builder
    {
        return $query->orderByDesc('amount_credits')->orderBy('sequence');
    }
}
