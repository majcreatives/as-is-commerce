<?php

declare(strict_types=1);

namespace App\Domain\Auction\Actions;

use App\Domain\Auction\Exceptions\InvalidAuctionRules;
use App\Domain\Auction\ValueObjects\AuctionSnapshot;
use App\Domain\Credit\ValueObjects\CreditAmount;
use App\Domain\Shared\Money\Money;
use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\AuctionRuleset;
use App\Models\AuctionTransition;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Create an auction, freezing everything it will run under.
 *
 * THE SNAPSHOT IS TAKEN HERE AND NEVER AGAIN. The ruleset is read once, right
 * now, and serialized into the auction row. From this point the auction does
 * not know the ruleset exists: editing it, activating a newer version,
 * archiving it or deleting it outright has no effect on this auction's
 * behaviour. That is what lets a closed auction still be explained months
 * later, and what stops an administrator changing the terms people are
 * currently bidding under.
 *
 * THE SETTLEMENT AMOUNT IS PER AUCTION. It is supplied here rather than read
 * from the ruleset, because two auctions on the same product may deliberately
 * settle at GH 50 and GH 150. It is frozen into the snapshot alongside the
 * rules, and it is not the product's Buy Now price, not derived from it, and
 * not a function of anybody's bid.
 *
 * THE POT TARGET IS OPTIONAL, AND PER AUCTION (docs/PLAN_POT_TARGET_BIDDING.md,
 * D-4, D-10). An administrator may set one, or leave it out entirely -- an
 * auction with no target simply closes on its clock, exactly as every auction
 * does today. Denominated in Credits, never derived from the product's price
 * or the settlement amount, and never computed here: whatever `CreditAmount`
 * the caller supplies is what gets stored, in subcredits, or null.
 *
 * The auction starts as a draft. It holds no inventory and is invisible to
 * the public until it is published, which is a separate deliberate act.
 */
final class CreateAuction
{
    /**
     * @param  Money  $settlementAmount  What a normal highest-bid winner will
     *                                   pay, before delivery and tax.
     * @param  CreditAmount|null  $potTarget  When set, this auction can also
     *                                        close early once the sum of
     *                                        every accepted bid reaches it.
     *                                        Null means no second way to
     *                                        close: the clock alone decides.
     */
    public function handle(
        Product $product,
        AuctionRuleset $ruleset,
        Money $settlementAmount,
        ?CreditAmount $potTarget = null,
        ?User $actor = null,
    ): Auction {
        $this->assertProductCanBeAuctioned($product);

        // Built before anything is written, so an invalid combination -- a
        // settlement in the wrong currency, a non-positive amount -- is
        // refused rather than half-persisted.
        $snapshot = new AuctionSnapshot(
            rules: $ruleset->toRules(),
            settlementAmount: $settlementAmount,
        );

        return DB::transaction(function () use ($product, $ruleset, $snapshot, $settlementAmount, $potTarget, $actor): Auction {
            $auction = new Auction;

            $auction->product_id = $product->id;
            $auction->auction_ruleset_id = $ruleset->id;
            $auction->rules_snapshot = $snapshot->toArray();
            $auction->snapshot_version = AuctionSnapshot::SNAPSHOT_VERSION;
            $auction->settlement_amount_minor = $settlementAmount->minor;
            $auction->pot_target_credits = $potTarget?->subcredits;
            $auction->currency = $settlementAmount->currency;
            $auction->status = AuctionStatus::Draft;
            $auction->created_by = $actor?->id;
            $auction->updated_by = $actor?->id;
            $auction->save();

            AuctionTransition::create([
                'auction_id' => $auction->id,
                'from_status' => null,
                'to_status' => AuctionStatus::Draft,
                'reason' => "Created from ruleset [{$ruleset->name} v{$ruleset->version}].",
                'caused_by' => $actor?->id,
            ]);

            Log::info('Auction created', [
                'operation' => 'auction.create',
                'auction_id' => $auction->id,
                'product_id' => $product->id,
                'ruleset_id' => $ruleset->id,
                'ruleset_version' => $ruleset->version,
                'winner_rule' => $snapshot->winnerRule(),
                'settlement_amount_minor' => $settlementAmount->minor,
                'pot_target_credits' => $auction->pot_target_credits,
                'actor_id' => $actor?->id,
            ]);

            return $auction;
        });
    }

    /**
     * A product has to be sellable before it can be auctioned.
     *
     * Stock is checked at publication rather than here, because a draft holds
     * no reservation and stock may legitimately arrive between drafting an
     * auction and opening it.
     */
    private function assertProductCanBeAuctioned(Product $product): void
    {
        if (! $product->status->isEditable()) {
            throw InvalidAuctionRules::because(
                'An archived product cannot be auctioned.'
            );
        }

        // The auction channel has its own gate on top of the catalogue's. A
        // product has to have been explicitly opted in -- `auction_eligible`
        // is a deliberate administrator decision, never a side effect of the
        // product existing. Anything not opted in is refused here, before a
        // single ledger row or reservation moves.
        if (! $product->auction_eligible) {
            throw InvalidAuctionRules::because(
                'This product is not marked as eligible for the auction channel.'
            );
        }
    }
}
