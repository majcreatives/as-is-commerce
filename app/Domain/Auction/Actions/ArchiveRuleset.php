<?php

declare(strict_types=1);

namespace App\Domain\Auction\Actions;

use App\Domain\Auction\Exceptions\RulesetNotEditable;
use App\Enums\RulesetStatus;
use App\Models\AuctionRuleset;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Retires a ruleset from further use.
 *
 * Archiving is the terminal state and is never destructive: the row stays, so
 * the configuration behind past auctions remains readable. Auctions already
 * created from it are unaffected, since they carry their own snapshot.
 */
final class ArchiveRuleset
{
    public function handle(AuctionRuleset $ruleset, ?User $actor = null): AuctionRuleset
    {
        if (! $ruleset->status->canTransitionTo(RulesetStatus::Archived)) {
            throw RulesetNotEditable::transition($ruleset->status, RulesetStatus::Archived);
        }

        // Refused rather than silently clearing the flag: archiving the global
        // default would leave auction creation with no ruleset to fall back
        // on, and that should be a deliberate act, not a side effect.
        if ($ruleset->is_default) {
            throw new DomainException(
                'This is the default ruleset. Designate another ruleset as the default before archiving it.'
            );
        }

        return app(CauserResolver::class)->withCauser(
            $actor,
            fn (): AuctionRuleset => $this->persist($ruleset, $actor),
        );
    }

    private function persist(AuctionRuleset $ruleset, ?User $actor): AuctionRuleset
    {
        return DB::transaction(function () use ($ruleset, $actor): AuctionRuleset {
            $ruleset->status = RulesetStatus::Archived;
            $ruleset->archived_at = now();
            $ruleset->updated_by = $actor?->id;
            $ruleset->save();

            activity('auction_ruleset')
                ->performedOn($ruleset)
                ->causedBy($actor)
                ->withProperties(['version' => $ruleset->version])
                ->log('archived');

            return $ruleset;
        });
    }
}
