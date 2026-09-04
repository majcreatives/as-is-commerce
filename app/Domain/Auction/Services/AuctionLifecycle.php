<?php

declare(strict_types=1);

namespace App\Domain\Auction\Services;

use App\Domain\Auction\Exceptions\InvalidAuctionTransition;
use App\Domain\Catalog\Services\InventoryService;
use App\Enums\AuctionClosureReason;
use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\AuctionTransition;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The auction state machine, and the inventory that follows it.
 *
 * Every move is checked against {@see AuctionStatus::allowedTransitions()},
 * recorded in `auction_transitions`, and applied in one transaction with
 * whatever else it implies. Nothing else in the application may write
 * `auctions.status`.
 *
 * INVENTORY FOLLOWS THE LIFECYCLE. This is how the platform avoids selling the
 * same item twice without inventing a second inventory system:
 *
 *   publishing        reserves one unit
 *   Buy Now / settle  turns that reservation into a sale
 *   cancel / unsold   gives it back
 *   forfeit           gives it back
 *
 * A reservation is what makes the races safe. Two auctions can run on the same
 * product only while stock covers them, because the second publish has to
 * reserve a unit of its own and will be refused if none is available. And
 * because the reservation is taken under the product row lock, a Buy Now and a
 * settlement cannot both convert the same unit.
 *
 * Stock is never written here directly. Every movement goes through
 * {@see InventoryService}, which records it in the append-only inventory
 * ledger with the auction as its reference.
 *
 * LOCK ORDER. Auction row, then product row, then wallet, then credit lots.
 * Observed by every operation in this stage, so concurrent work queues rather
 * than deadlocks. The credit ledger's own order -- wallet, then lots by id --
 * continues underneath it unchanged.
 */
class AuctionLifecycle
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly HighestBidResolver $bids,
        private readonly AuctionClock $clock,
    ) {}

    /**
     * Publish a draft so it opens at a future time.
     *
     * Reserves the unit now rather than at the start time, so an auction that
     * has been announced cannot fail to open because the stock was sold from
     * under it in the meantime.
     */
    public function schedule(Auction $auction, Carbon $startsAt, ?User $actor = null): Auction
    {
        return DB::transaction(function () use ($auction, $startsAt, $actor): Auction {
            $locked = $this->lock($auction);

            $this->assertCanMove($locked, AuctionStatus::Scheduled);

            $this->reserveUnit($locked, $actor);

            $locked->scheduled_start_at = $startsAt;
            $locked->updated_by = $actor?->id;

            return $this->apply(
                $locked,
                AuctionStatus::Scheduled,
                'Scheduled to open at '.$startsAt->toIso8601String().'.',
                $actor,
            );
        });
    }

    /**
     * Open the auction for bidding.
     *
     * The end time is computed here, from the frozen base duration, and
     * written to the row. From this moment the database is the only authority
     * on when the auction ends.
     */
    public function start(Auction $auction, ?User $actor = null, ?Carbon $now = null): Auction
    {
        $now ??= Carbon::now();

        return DB::transaction(function () use ($auction, $actor, $now): Auction {
            $locked = $this->lock($auction);

            $this->assertCanMove($locked, AuctionStatus::Live);

            // A draft going straight live has not reserved its unit yet.
            if (! $locked->status->holdsInventory()) {
                $this->reserveUnit($locked, $actor);
            }

            $locked->starts_at = $now;
            $locked->ends_at = $this->clock->endFor($locked->rules(), $now);
            $locked->updated_by = $actor?->id;

            return $this->apply(
                $locked,
                AuctionStatus::Live,
                'Opened for bidding until '.$locked->ends_at->toIso8601String().'.',
                $actor,
            );
        });
    }

    /**
     * Mark a live auction as being inside its closing window.
     *
     * Informational only. Bids and Buy Now are accepted in exactly the same
     * way as in Live; this exists so the interface and the audit trail can
     * show that the auction is about to end.
     */
    public function enterClosing(Auction $auction, ?Carbon $now = null): Auction
    {
        return DB::transaction(function () use ($auction, $now): Auction {
            $locked = $this->lock($auction);

            if ($locked->status !== AuctionStatus::Live) {
                return $locked;
            }

            $locked->closing_started_at = $now ?? Carbon::now();

            return $this->apply($locked, AuctionStatus::Closing, 'Entered the closing window.', null);
        });
    }

    /**
     * Record an extension granted by a late bid.
     *
     * Called from inside bid placement, which already holds the auction row,
     * so this does not lock again. Extending the clock cannot change who is
     * winning: it gives everyone a further chance to bid higher, and the
     * winner is still the highest valid credit bid whenever the auction
     * eventually stops.
     */
    public function applyExtension(Auction $auction, int $seconds): Auction
    {
        if ($seconds <= 0 || $auction->ends_at === null) {
            return $auction;
        }

        $auction->ends_at = $auction->ends_at->copy()->addSeconds($seconds);
        $auction->extensions_applied++;
        $auction->extension_seconds_applied += $seconds;
        $auction->save();

        Log::info('Auction extended by a late bid', [
            'operation' => 'auction.extend',
            'auction_id' => $auction->id,
            'seconds' => $seconds,
            'extensions_applied' => $auction->extensions_applied,
            'ends_at' => $auction->ends_at->toIso8601String(),
        ]);

        return $auction;
    }

    /**
     * Stop an auction before it could close.
     *
     * Releases the reserved unit: nobody bought it. Bids already placed keep
     * their consumed credits, because the credits were spent on the attempt
     * and this method does not decide compensation -- that would be a business
     * decision, taken deliberately and posted to the ledger as its own entry.
     */
    public function cancel(Auction $auction, string $reason, ?User $actor = null): Auction
    {
        return DB::transaction(function () use ($auction, $reason, $actor): Auction {
            $locked = $this->lock($auction);

            $this->assertCanMove($locked, AuctionStatus::Cancelled);

            $this->releaseUnit($locked, $actor, 'Auction cancelled.');

            $locked->cancelled_at = Carbon::now();
            $locked->closure_reason = AuctionClosureReason::Cancelled;
            $locked->updated_by = $actor?->id;

            return $this->apply($locked, AuctionStatus::Cancelled, $reason, $actor);
        });
    }

    /**
     * The winner did not settle within the deadline the frozen rules allowed.
     *
     * Releases the unit so it can be sold again. What happens to the winner's
     * consumed credits is governed by the forfeit policy in the snapshot; this
     * method does not invent anything beyond it, and in particular does not
     * refund credits, which the model does not do.
     */
    public function forfeit(Auction $auction, ?User $actor = null): Auction
    {
        return DB::transaction(function () use ($auction, $actor): Auction {
            $locked = $this->lock($auction);

            $this->assertCanMove($locked, AuctionStatus::Forfeited);

            $this->releaseUnit($locked, $actor, 'Winner did not settle in time.');

            $locked->forfeited_at = Carbon::now();
            $locked->closure_reason = AuctionClosureReason::Forfeited;
            $locked->updated_by = $actor?->id;

            return $this->apply(
                $locked,
                AuctionStatus::Forfeited,
                'The winner did not settle before the deadline. Policy: '
                    .$locked->rules()->forfeitPolicy->value.'.',
                $actor,
            );
        });
    }

    /**
     * Complete a normal winner's settlement: the unit is sold to them.
     *
     * NOTHING CALLS THIS YET. Taking the winner's settlement payment belongs
     * to the settlement checkout stage, and this stage does not process
     * payments. The transition and its inventory effect exist so that stage
     * attaches to a guarded path rather than inventing one, and so the state
     * machine is complete and testable now.
     *
     * It must only ever be called once a payment has been confirmed
     * server-side. Calling it without one would record a sale that did not
     * happen.
     */
    public function settle(Auction $auction, ?User $actor = null): Auction
    {
        return DB::transaction(function () use ($auction, $actor): Auction {
            $locked = $this->lock($auction);

            if ($locked->status !== AuctionStatus::PendingSettlement) {
                throw InvalidAuctionTransition::because(
                    'Only an auction awaiting settlement can be settled by its winner.'
                );
            }

            $this->assertCanMove($locked, AuctionStatus::Settled);

            $this->sellUnit($locked, $actor, 'Sold to the auction winner.');

            $locked->settled_at = Carbon::now();
            $locked->updated_by = $actor?->id;

            return $this->apply(
                $locked,
                AuctionStatus::Settled,
                'Winner settled '.$locked->settlementTotal()->format().'.',
                $actor,
            );
        });
    }

    /**
     * Mark a closed auction as superseded by a fresh one.
     */
    public function markRelisted(Auction $auction, int $newAuctionId, ?User $actor = null): Auction
    {
        return DB::transaction(function () use ($auction, $newAuctionId, $actor): Auction {
            $locked = $this->lock($auction);

            $this->assertCanMove($locked, AuctionStatus::Relisted);

            $locked->updated_by = $actor?->id;

            return $this->apply(
                $locked,
                AuctionStatus::Relisted,
                "Relisted as auction #{$newAuctionId}.",
                $actor,
            );
        });
    }

    // ---------------------------------------------------------- Inventory

    /**
     * Set aside one unit for this auction.
     *
     * Refused when nothing is available, which is what stops more auctions
     * being published than there is stock to satisfy.
     */
    public function reserveUnit(Auction $auction, ?User $actor = null): void
    {
        $this->inventory->reserve(
            product: $auction->product,
            quantity: 1,
            reference: $auction,
            reason: "Reserved for auction #{$auction->id}.",
            actor: $actor,
        );
    }

    /**
     * Give the reserved unit back, if this auction is holding one.
     *
     * Checked rather than assumed: releasing a reservation that was never
     * taken would overstate available stock.
     *
     * Public so the closing action, which has more to do in the same
     * transaction, can release a unit for an auction that ended with no bids.
     */
    public function releaseUnit(Auction $auction, ?User $actor, string $reason): void
    {
        if (! $auction->status->holdsInventory()) {
            return;
        }

        $this->inventory->release(
            product: $auction->product,
            quantity: 1,
            reference: $auction,
            reason: $reason,
            actor: $actor,
        );
    }

    /**
     * Turn this auction's reservation into a sale.
     *
     * The inventory service nets the sale against the reservation that
     * preceded it, so one unit leaves rather than two.
     */
    public function sellUnit(Auction $auction, ?User $actor, string $reason): void
    {
        $this->inventory->recordSale(
            product: $auction->product,
            quantity: 1,
            reference: $auction,
            reason: $reason,
            actor: $actor,
        );
    }

    // ---------------------------------------------------------- Internals

    /**
     * Re-read the auction under a row lock.
     *
     * Callers must use the returned instance: the one passed in may be stale,
     * and a lifecycle decision made on a stale status is exactly how an
     * auction gets closed twice.
     */
    public function lock(Auction $auction): Auction
    {
        return Auction::whereKey($auction->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * @throws InvalidAuctionTransition
     */
    public function assertCanMove(Auction $auction, AuctionStatus $target): void
    {
        if (! $auction->status->canTransitionTo($target)) {
            throw InvalidAuctionTransition::between($auction->status, $target);
        }
    }

    /**
     * Write the new status and the record of the change.
     *
     * Public so the closing and Buy Now actions, which have more to do in the
     * same transaction, can reuse it. They are responsible for holding the
     * auction row lock first.
     */
    public function apply(
        Auction $auction,
        AuctionStatus $target,
        ?string $reason,
        ?User $actor,
    ): Auction {
        $this->assertCanMove($auction, $target);

        $from = $auction->status;

        $auction->status = $target;
        $auction->save();

        AuctionTransition::create([
            'auction_id' => $auction->id,
            'from_status' => $from,
            'to_status' => $target,
            'reason' => $reason,
            'caused_by' => $actor?->id,
        ]);

        Log::info('Auction transitioned', [
            'operation' => 'auction.transition',
            'auction_id' => $auction->id,
            'from' => $from->value,
            'to' => $target->value,
            'reason' => $reason,
            'actor_id' => $actor?->id,
        ]);

        return $auction;
    }

    /**
     * The highest-bid resolver, for callers that already hold this service.
     */
    public function bids(): HighestBidResolver
    {
        return $this->bids;
    }
}
