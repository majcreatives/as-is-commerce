<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BidStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * One bid: a number of credits a user committed to an auction.
 *
 * VARIABLE AMOUNTS. `amount_credits` is what this bid committed -- 20, 50,
 * 100, 150. There is no fixed cost per bid anywhere in the system, and one
 * bid never means one credit. Under the single-highest model the bidder
 * chooses the amount, subject to the auction's frozen bid rules. Under the
 * cumulative model the SERVER works out the one valid amount -- the credits
 * that put the bidder exactly one step ahead of the leader -- and the bidder
 * confirms it. Either way this is what the bid CONSUMED; where it leaves the
 * bidder is `cumulative_credits`, a separate fact.
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
 * @property int|null $cumulative_credits Where this bid left its bidder: the credits
 *                                        that bidder had consumed on this auction, this bid
 *                                        included. Written once at insert, never updated.
 *                                        Null for every bid placed under the single-highest
 *                                        model, which ranks by `amount_credits` and never
 *                                        needed a running total.
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
            'cumulative_credits' => 'integer',
            'sequence' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The figure this bid is ranked by, under the model it was placed in.
     *
     * The total it left its bidder on, when the auction ranks by total, and
     * the amount it committed when it ranks by the largest single bid. This is
     * the number to show as "where this bid stands"; `amount_credits` stays
     * what this bid CONSUMED, which is a different fact and never changes
     * meaning. Mixing the two up is how a customer is shown "highest bid: 22"
     * beside a history row that says 2.
     *
     * A bid placed under the single-highest model has no total (the column is
     * null, deliberately), so its amount is what ranked it.
     */
    public function rankingValue(): int
    {
        return $this->cumulative_credits ?? $this->amount_credits;
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
        return $this->scopeLeadingFirstBy($query, 'amount_credits');
    }

    /**
     * Leading first, by whichever column the auction's model ranks on.
     *
     * The tie-break is the same either way. Under the cumulative model it never
     * decides anything -- an exact step means no two bidders share a total --
     * but it stays, so the order is total and the same query always returns the
     * same row whatever the data.
     *
     * `$column` is one of the two fixed strings {@see BidModel::rankColumn()}
     * returns, never anything from a request, because it is interpolated into
     * an ORDER BY. A value outside that pair is refused rather than trusted.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLeadingFirstBy(Builder $query, string $column): Builder
    {
        if (! in_array($column, ['amount_credits', 'cumulative_credits'], true)) {
            throw new InvalidArgumentException("Cannot rank bids by [{$column}].");
        }

        return $query->orderByDesc($column)->orderBy('sequence');
    }
}
