<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Queries;

use App\Domain\Credit\Services\CreditLedgerService;
use App\Domain\Shared\Money\Money;
use App\Enums\AuctionStatus;
use App\Enums\DeliveryStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Auction;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What one customer's account looks like right now.
 *
 * READ ONLY, AND NO SECOND SOURCE OF TRUTH. Every figure here is read from the
 * table that already owns it: the credit wallet for a balance, the bid records
 * for bidding, the auctions for wins, the orders for what is owed, the
 * deliveries for where things are. Nothing is cached, nothing is recomputed a
 * second way, and nothing is stored.
 *
 * TWO CREDIT FIGURES, AND THEY ARE NEVER ADDED TOGETHER:
 *
 *   Available credits   what is in the wallet, ready to bid with.
 *   Credits committed   what has been consumed on bids, and is gone.
 *
 * Summing them would produce a number that means nothing -- part of it is
 * spendable and part of it is spent. The dashboard shows both, labelled, and
 * the second one says plainly that it is not coming back.
 *
 * SCOPED TO ONE CUSTOMER, EVERY QUERY. The user is a constructor-free
 * parameter on every method rather than something set once and remembered,
 * so there is no path by which a stale subject could leak another person's
 * orders into a page.
 */
class CustomerDashboardQuery
{
    public function __construct(
        private readonly CreditLedgerService $credits,
    ) {}

    /**
     * Credits in the wallet, ready to bid with.
     *
     * Through the ledger service's own accessor rather than the relation, so
     * a customer who has never bought credits gets a wallet and a zero rather
     * than a null this class would have to invent a meaning for. Nothing here
     * sums transactions independently: that would be a second opinion on a
     * figure the credit domain already owns.
     */
    public function availableCredits(User $user): int
    {
        return $this->credits->walletFor($user)->spendableBalance();
    }

    /**
     * Credits this customer has consumed on bids, ever.
     *
     * Spent, and not coming back. Summed from the bid records rather than the
     * ledger because a bid is the thing being counted -- credits bought and
     * never bid are not part of this number, and neither is anything else the
     * ledger records.
     */
    public function creditsCommitted(User $user): int
    {
        return (int) $user->bids()->sum('amount_credits');
    }

    /**
     * Auctions this customer has bid on and which are still running.
     *
     * @return Collection<int, Auction>
     */
    public function activeBids(User $user, int $limit = 5): Collection
    {
        return Auction::query()
            ->publiclyVisible()
            ->with(['product.brand', 'highestBid'])
            ->whereIn('status', [AuctionStatus::Live, AuctionStatus::Closing])
            ->whereHas('bids', fn ($q) => $q->where('user_id', $user->id))
            ->orderByRaw('ends_at IS NULL, ends_at ASC')
            ->limit($limit)
            ->get();
    }

    public function activeBidCount(User $user): int
    {
        return Auction::query()
            ->publiclyVisible()
            ->whereIn('status', [AuctionStatus::Live, AuctionStatus::Closing])
            ->whereHas('bids', fn ($q) => $q->where('user_id', $user->id))
            ->count();
    }

    /**
     * Whether this customer is the standing highest bidder on an auction.
     *
     * From the auction's own projection, which is what the listing shows.
     * Nothing is decided from it: who actually wins is resolved from the bid
     * records when the auction closes.
     */
    public function isLeading(User $user, Auction $auction): bool
    {
        return $auction->highestBid?->user_id === $user->id;
    }

    public function auctionsWon(User $user): int
    {
        return Auction::query()->where('winner_user_id', $user->id)->count();
    }

    /**
     * Auctions won and not yet paid for.
     *
     * The most urgent thing on the page: a settlement deadline runs, and a
     * winner who misses it forfeits.
     *
     * @return Collection<int, Order>
     */
    public function awaitingSettlement(User $user): Collection
    {
        return Order::query()
            ->where('user_id', $user->id)
            ->where('source', OrderSource::AuctionWin)
            ->awaitingPayment()
            ->with(['items', 'auction.product'])
            ->orderBy('payment_due_at')
            ->get();
    }

    /**
     * Anything the customer still owes money on, settlement or otherwise.
     */
    public function unpaidOrderCount(User $user): int
    {
        return Order::query()->where('user_id', $user->id)->awaitingPayment()->count();
    }

    /**
     * @return Collection<int, Order>
     */
    public function recentOrders(User $user, int $limit = 5): Collection
    {
        return Order::query()
            ->where('user_id', $user->id)
            ->with(['items', 'delivery'])
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Packages on their way to this customer.
     *
     * @return Collection<int, Delivery>
     */
    public function activeDeliveries(User $user, int $limit = 5): Collection
    {
        return Delivery::query()
            ->where('user_id', $user->id)
            ->outstanding()
            ->with(['order.items'])
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Deliveries the customer has to act on before anything can move.
     *
     * An auction winner's order is created by the closing sweep while nobody
     * is at a keyboard, so their package legitimately begins with nowhere to
     * go. Until they say where, it sits.
     */
    public function deliveriesAwaitingAddress(User $user): int
    {
        return Delivery::query()
            ->where('user_id', $user->id)
            ->where('status', DeliveryStatus::Pending)
            ->whereNull('address_line')
            ->count();
    }

    /**
     * What this customer has actually spent, in GHS.
     *
     * Paid orders only. An outstanding obligation is not money that has moved,
     * and counting it would overstate what somebody has spent with us.
     */
    public function totalSpent(User $user): Money
    {
        $total = Order::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [
                OrderStatus::Paid,
                OrderStatus::Processing,
                OrderStatus::Fulfilled,
            ])
            ->sum('total_minor');

        return Money::fromMinor((int) $total);
    }
}
