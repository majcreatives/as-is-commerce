<?php

declare(strict_types=1);

namespace App\Domain\Auction\Actions;

use App\Domain\Auction\Exceptions\InvalidAuctionTransition;
use App\Domain\Auction\Services\AuctionLifecycle;
use App\Domain\Shared\Money\Money;
use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\AuctionRuleset;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Offer a product again after an auction ended without selling it.
 *
 * A NEW AUCTION, NOT A REOPENED ONE. The old auction keeps its own record --
 * its bids, its consumed credits, its history -- and is marked Relisted so the
 * chain from one to the next is explicit. Reopening the original instead would
 * mean bids placed under the old terms competing with bids placed under new
 * ones, and a closed auction quietly becoming open again.
 *
 * The replacement takes a fresh snapshot, so it may run under a newer ruleset
 * and a different settlement amount. Nothing is inherited implicitly.
 *
 * Credits consumed on the old auction stay consumed and do not carry over.
 * They bought bids in that auction; they are not a balance held against the
 * product. For the same reason they earn no discount on the new auction's
 * Buy Now price -- only credits spent on that auction do.
 */
final class RelistAuction
{
    public function __construct(
        private readonly CreateAuction $create,
        private readonly AuctionLifecycle $lifecycle,
    ) {}

    public function handle(
        Auction $original,
        AuctionRuleset $ruleset,
        Money $settlementAmount,
        ?User $actor = null,
    ): Auction {
        if (! $original->status->canTransitionTo(AuctionStatus::Relisted)) {
            throw InvalidAuctionTransition::because(
                "An auction that is [{$original->status->value}] cannot be relisted: only one that "
                .'closed without selling can be.'
            );
        }

        return DB::transaction(function () use ($original, $ruleset, $settlementAmount, $actor): Auction {
            $replacement = $this->create->handle(
                product: $original->product,
                ruleset: $ruleset,
                settlementAmount: $settlementAmount,
                actor: $actor,
            );

            $this->lifecycle->markRelisted($original, $replacement->id, $actor);

            return $replacement;
        });
    }
}
