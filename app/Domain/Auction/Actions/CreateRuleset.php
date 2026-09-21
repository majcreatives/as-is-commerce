<?php

declare(strict_types=1);

namespace App\Domain\Auction\Actions;

use App\Domain\Auction\RulesetInvariants;
use App\Enums\BidModel;
use App\Enums\RulesetStatus;
use App\Models\AuctionRuleset;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Creates a new ruleset as a draft.
 *
 * Always a draft: nothing becomes active without an explicit activation, so
 * an administrator cannot put an unreviewed configuration into service simply
 * by saving a form.
 */
final class CreateRuleset
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, ?User $actor = null, BidModel $model = BidModel::SingleHighest): AuctionRuleset
    {
        RulesetInvariants::assert($attributes);

        // The audit entry names the actor this action was given, rather than
        // whoever happens to be authenticated, so a console command or queued
        // job attributes its changes correctly too.
        return app(CauserResolver::class)->withCauser(
            $actor,
            fn (): AuctionRuleset => $this->persist($attributes, $actor, $model),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persist(array $attributes, ?User $actor, BidModel $model): AuctionRuleset
    {
        return DB::transaction(function () use ($attributes, $actor, $model): AuctionRuleset {
            $ruleset = new AuctionRuleset;

            // Filtered rather than passed straight through: a payload carrying
            // `status` or `is_default` must be ignored, not rejected, so the
            // action is safe to call with whatever a caller happens to hold.
            $ruleset->fill(Arr::only($attributes, $ruleset->getFillable()));

            // Lifecycle columns are assigned here rather than mass-assigned,
            // so a crafted request cannot create something already active or
            // already marked as the global default.
            $ruleset->version = $this->nextVersionFor((string) $attributes['name']);
            $ruleset->status = RulesetStatus::Draft;
            $ruleset->is_default = false;

            // Chosen by the caller in code, never read from `$attributes`: which
            // bidding model a ruleset produces is not a value a request may
            // set. The admin form passes the cumulative model; the default is
            // the model every earlier ruleset was made under.
            $ruleset->bid_model = $model;

            $ruleset->created_by = $actor?->id;
            $ruleset->updated_by = $actor?->id;
            $ruleset->save();

            return $ruleset;
        });
    }

    /**
     * Versions run per named lineage: "Standard Auction" v1, v2, v3.
     */
    private function nextVersionFor(string $name): int
    {
        return (int) AuctionRuleset::where('name', $name)->max('version') + 1;
    }
}
