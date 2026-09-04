<?php

declare(strict_types=1);

namespace App\Domain\Auction\Contracts;

use App\Models\Auction;

/**
 * How a closing auction hands its winner over to be paid.
 *
 * An interface rather than a direct call, because the checkout layer already
 * depends on the auction layer -- it prices settlements from frozen auction
 * economics -- and calling back the other way would tie the two together in
 * both directions. The auction domain states what it needs; the orders domain
 * provides it, bound in `AppServiceProvider`.
 *
 * WHAT AN IMPLEMENTATION MUST NOT DO. It must not take payment, consume
 * credits, return credits, or move stock. Closing an auction creates an
 * obligation and nothing more: the winner's bid credits are already consumed,
 * and the unit stays reserved until a verified payment turns it into a sale.
 *
 * It must be safe to call twice. Closing is idempotent, and a handoff that
 * created a second order for the same win would leave two payable obligations
 * for one product.
 */
interface SettlementHandoff
{
    /**
     * Open the checkout this auction's winner settles through.
     *
     * Called inside the closing transaction, with the auction row already
     * locked and its status already `PendingSettlement`. Returns the order's
     * id, or null when no order could be opened -- which closing treats as
     * non-fatal, because an auction that closed correctly must not be left
     * open by a downstream failure.
     */
    public function openFor(Auction $auction): ?int;

    /**
     * Close any outstanding settlement obligation for this auction.
     *
     * Called when an auction stops being settleable -- the winner let the
     * deadline pass, or an administrator cancelled it. Without this a
     * forfeited auction would leave a payable checkout behind, and a winner
     * who was too late could still pay for a unit that had already gone back
     * on sale.
     *
     * Only an unpaid obligation is closed. A settlement that was already paid
     * is a completed transaction and is never touched here.
     *
     * @return int|null The order that was closed, if there was one.
     */
    public function closeFor(Auction $auction, string $reason): ?int;
}
