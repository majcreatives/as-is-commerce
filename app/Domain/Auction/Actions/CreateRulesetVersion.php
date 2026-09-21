<?php

declare(strict_types=1);

namespace App\Domain\Auction\Actions;

use App\Enums\BidModel;
use App\Enums\RulesetStatus;
use App\Models\AuctionRuleset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Copies an existing ruleset into a new editable draft version.
 *
 * This is the supported way to change an active configuration. Rather than
 * mutating a ruleset that auctions were created from, an administrator drafts
 * the next version, reviews it, and activates it -- at which point the old
 * version is archived rather than overwritten.
 *
 * The result is that every configuration the platform has ever run under
 * stays on record and can be pointed at when explaining a past auction.
 */
final class CreateRulesetVersion
{
    public function handle(AuctionRuleset $source, ?User $actor = null): AuctionRuleset
    {
        return app(CauserResolver::class)->withCauser(
            $actor,
            fn (): AuctionRuleset => $this->persist($source, $actor),
        );
    }

    private function persist(AuctionRuleset $source, ?User $actor): AuctionRuleset
    {
        return DB::transaction(function () use ($source, $actor): AuctionRuleset {
            $draft = $source->replicate([
                ...AuctionRuleset::GENERATED_COLUMNS,
                'status',
                'version',
                'is_default',
                'activated_at',
                'archived_at',
                'created_by',
                'updated_by',
                'created_at',
                'updated_at',
            ]);

            $draft->version = (int) AuctionRuleset::where('name', $source->name)->max('version') + 1;

            // A NEW VERSION ADOPTS THE CUMULATIVE MODEL. The version it was copied
            // from is untouched and keeps producing what it always produced; this
            // is the deliberate step by which a ruleset moves to the new rules.
            // The single-highest rules do not carry over -- the database and the
            // rules object refuse them beside a cumulative model -- and the step
            // is left as the source had it, which is empty for an older ruleset,
            // so the draft cannot be activated until an administrator has chosen
            // one. Nothing here invents a number.
            $draft->bid_model = BidModel::CumulativeStep;
            $draft->minimum_bid_increment_credits = null;
            $draft->allow_bid_increase = null;

            $draft->status = RulesetStatus::Draft;
            $draft->is_default = false;
            $draft->activated_at = null;
            $draft->archived_at = null;
            $draft->created_by = $actor?->id;
            $draft->updated_by = $actor?->id;
            $draft->save();

            activity('auction_ruleset')
                ->performedOn($draft)
                ->causedBy($actor)
                ->withProperties([
                    'copied_from_id' => $source->id,
                    'copied_from_version' => $source->version,
                ])
                ->log('version_drafted');

            return $draft;
        });
    }
}
