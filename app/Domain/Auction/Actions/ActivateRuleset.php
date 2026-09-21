<?php

declare(strict_types=1);

namespace App\Domain\Auction\Actions;

use App\Domain\Auction\Exceptions\RulesetNotEditable;
use App\Enums\RulesetStatus;
use App\Models\AuctionRuleset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Promotes a draft ruleset to active.
 *
 * At most one version of a named lineage may be active at a time, so
 * activating v2 of "Standard Auction" archives v1. That supersession is
 * performed inside the same transaction as the activation, because the
 * database enforces the single-active rule with a unique index and would
 * otherwise reject the write.
 *
 * Archiving the old version does not disturb auctions created from it: those
 * hold their own immutable snapshot of the rules, not a reference to this row.
 */
final class ActivateRuleset
{
    public function handle(AuctionRuleset $ruleset, ?User $actor = null, bool $makeDefault = false): AuctionRuleset
    {
        if (! $ruleset->status->canTransitionTo(RulesetStatus::Active)) {
            throw RulesetNotEditable::transition($ruleset->status, RulesetStatus::Active);
        }

        // A draft may be half-finished; an ACTIVE ruleset creates auctions, so
        // it must be one an auction can actually be created from. Building the
        // rules object runs every invariant the engine relies on, and throws
        // with the reason -- for the cumulative model, a missing opening bid or
        // step -- before anything about the ruleset changes. The database
        // refuses the same thing, but a CHECK violation is a poor way to tell an
        // administrator what to fill in.
        $ruleset->toRules();

        return app(CauserResolver::class)->withCauser(
            $actor,
            fn (): AuctionRuleset => $this->persist($ruleset, $actor, $makeDefault),
        );
    }

    private function persist(AuctionRuleset $ruleset, ?User $actor, bool $makeDefault): AuctionRuleset
    {
        return DB::transaction(function () use ($ruleset, $actor, $makeDefault): AuctionRuleset {
            $superseded = AuctionRuleset::query()
                ->active()
                ->where('name', $ruleset->name)
                ->whereKeyNot($ruleset->getKey())
                ->lockForUpdate()
                ->get();

            foreach ($superseded as $previous) {
                $previous->status = RulesetStatus::Archived;
                $previous->archived_at = now();
                $previous->updated_by = $actor?->id;

                // The incoming version takes over as default, so the flag has
                // to be released before the unique index sees two of them.
                $wasDefault = $previous->is_default;
                $previous->is_default = false;
                $previous->save();

                activity('auction_ruleset')
                    ->performedOn($previous)
                    ->causedBy($actor)
                    ->withProperties([
                        'superseded_by_id' => $ruleset->id,
                        'superseded_by_version' => $ruleset->version,
                    ])
                    ->log('superseded');

                if ($wasDefault) {
                    $makeDefault = true;
                }
            }

            if ($makeDefault) {
                AuctionRuleset::query()
                    ->where('is_default', true)
                    ->whereKeyNot($ruleset->getKey())
                    ->update(['is_default' => false]);
            }

            $ruleset->status = RulesetStatus::Active;
            $ruleset->activated_at = now();
            $ruleset->archived_at = null;
            $ruleset->is_default = $makeDefault;
            $ruleset->updated_by = $actor?->id;
            $ruleset->save();

            activity('auction_ruleset')
                ->performedOn($ruleset)
                ->causedBy($actor)
                ->withProperties([
                    'version' => $ruleset->version,
                    'is_default' => $ruleset->is_default,
                ])
                ->log('activated');

            return $ruleset;
        });
    }
}
