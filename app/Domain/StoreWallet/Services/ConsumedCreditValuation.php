<?php

declare(strict_types=1);

namespace App\Domain\StoreWallet\Services;

use App\Domain\StoreWallet\ValueObjects\CreditValuation;
use App\Domain\StoreWallet\ValueObjects\LotValuation;
use App\Enums\BidStatus;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\CreditLot;
use App\Models\CreditLotConsumption;
use Illuminate\Support\Collection;

/**
 * What a user's consumed bid credits on one auction actually cost them.
 *
 * THE AUDIT CHAIN THIS WALKS, and why every link is necessary:
 *
 *     bids                     an accepted bid by this user on this auction
 *       -> credit_transaction  the debit that paid for it, NOT NULL on the bid
 *       -> credit_lot_consumptions
 *                              exactly which lots that debit drew from, and
 *                              how many credits from each
 *       -> credit_lots         what each of those lots cost and how many
 *                              credits that money bought
 *
 * Every one of those rows is immutable. The bid cannot be edited, the credit
 * transaction cannot be edited, the consumption cannot be edited, and a lot's
 * acquisition figures are frozen by a database trigger. So this returns a
 * historical fact, and asking the same question a year later gives the same
 * answer.
 *
 * WHAT IT IS NEVER ALLOWED TO READ. Not a wallet balance -- that moves with
 * everything else the user does. Not a credit package's current price -- a
 * package repriced tomorrow must not revalue credits bought today. Not a
 * global credit rate -- there is no such thing, and inventing one would erase
 * the difference between a GH0.10 credit and a GH0.08 one. Not the product's
 * price, and not the bid amount.
 *
 * WHICH CREDITS QUALIFY. Credits consumed by accepted bids by THIS user on
 * THIS auction. Not credits bought and never bid, not credits bid on another
 * auction, and not another user's credits. That is the same narrow definition
 * the Buy Now discount has always used; what has changed is what the credits
 * are worth, not which ones count.
 *
 * FREE CREDITS ARE WORTH NOTHING. A promotional, referral or adjustment lot
 * cost the platform's customer no money, so consuming it creates no cash
 * value. Its line appears in the breakdown at zero rather than being dropped,
 * so the answer explains itself.
 */
class ConsumedCreditValuation
{
    /**
     * Value what this user has consumed bidding on this auction.
     */
    public function forAuction(Auction $auction, int $userId, ?string $currency = null): CreditValuation
    {
        $currency ??= $auction->currency ?? 'GHS';

        $consumed = $this->consumptionByLot($auction, $userId);

        if ($consumed === []) {
            return CreditValuation::empty($currency);
        }

        /** @var Collection<int, CreditLot> $lots */
        $lots = CreditLot::query()
            ->whereIn('id', array_keys($consumed))
            // Deterministic order, so the breakdown reads the same every time
            // it is produced and two runs cannot disagree about line order.
            ->orderBy('id')
            ->get();

        $lines = $lots->map(
            fn (CreditLot $lot): LotValuation => LotValuation::forLot(
                $lot,
                $consumed[(int) $lot->getKey()] ?? 0,
                $currency,
            )
        );

        return CreditValuation::of($lines, $currency);
    }

    /**
     * Credits this user drew from each lot, bidding on this auction.
     *
     * One grouped query rather than a walk over the bids: a busy bidder may
     * have dozens of bids, each drawing from several lots, and the answer is
     * the same either way.
     *
     * @return array<int, int>  Lot id => credits consumed from it.
     */
    public function consumptionByLot(Auction $auction, int $userId): array
    {
        $rows = CreditLotConsumption::query()
            ->join('bids', 'bids.credit_transaction_id', '=', 'credit_lot_consumptions.credit_transaction_id')
            ->where('bids.auction_id', $auction->getKey())
            ->where('bids.user_id', $userId)
            // Belt and braces: a rejected bid has no row at all, so this
            // filters nothing today. It is here so that adding a second bid
            // status later cannot silently start valuing bids that were never
            // accepted.
            ->where('bids.status', BidStatus::Accepted->value)
            ->groupBy('credit_lot_consumptions.credit_lot_id')
            ->selectRaw('credit_lot_consumptions.credit_lot_id as lot_id, SUM(credit_lot_consumptions.amount) as credits')
            ->pluck('credits', 'lot_id');

        /** @var array<int, int> $result */
        $result = [];

        foreach ($rows as $lotId => $credits) {
            $result[(int) $lotId] = (int) $credits;
        }

        return $result;
    }

    /**
     * Every user who bid on this auction, with their bid totals.
     *
     * Used when an auction ends and everyone who did not acquire the product
     * has to be valued. Ordered by user id so two runs process the same
     * wallets in the same order -- which is what keeps concurrent closures
     * queueing rather than deadlocking.
     *
     * @return Collection<int, int>  User ids, ascending.
     */
    public function biddersOn(Auction $auction): Collection
    {
        return Bid::query()
            ->where('auction_id', $auction->getKey())
            ->counting()
            ->distinct()
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->values();
    }
}
