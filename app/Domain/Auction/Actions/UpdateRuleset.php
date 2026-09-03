<?php

declare(strict_types=1);

namespace App\Domain\Auction\Actions;

use App\Domain\Auction\Exceptions\RulesetNotEditable;
use App\Domain\Auction\RulesetInvariants;
use App\Models\AuctionRuleset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Updates a draft ruleset in place.
 *
 * Refuses anything that is not a draft. An active ruleset may already have
 * produced auctions, and an archived one certainly has; editing either would
 * make those auctions' recorded behaviour unexplainable. The supported way to
 * change an active ruleset is {@see CreateRulesetVersion}.
 */
final class UpdateRuleset
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(AuctionRuleset $ruleset, array $attributes, ?User $actor = null): AuctionRuleset
    {
        if (! $ruleset->isEditable()) {
            throw RulesetNotEditable::status($ruleset->status);
        }

        // Validated against the merged result, so a change to one timing field
        // is checked against the values it will actually sit alongside.
        RulesetInvariants::assert(array_merge($ruleset->only([
            'base_duration_seconds',
            'closing_window_seconds',
            'extension_seconds',
            'max_extensions',
            'max_extension_total_seconds',
        ]), $attributes));

        // The audit entry names the actor this action was given, rather than
        // whoever happens to be authenticated.
        return app(CauserResolver::class)->withCauser(
            $actor,
            fn (): AuctionRuleset => $this->persist($ruleset, $attributes, $actor),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persist(AuctionRuleset $ruleset, array $attributes, ?User $actor): AuctionRuleset
    {
        return DB::transaction(function () use ($ruleset, $attributes, $actor): AuctionRuleset {
            $ruleset->fill($attributes);
            $ruleset->updated_by = $actor?->id;
            $ruleset->save();

            return $ruleset;
        });
    }
}
