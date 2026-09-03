<?php

declare(strict_types=1);

namespace App\Domain\Auction;

use App\Domain\Auction\Exceptions\InvalidAuctionRules;
use App\Domain\Auction\ValueObjects\AuctionRules;
use App\Domain\Shared\Money\Money;
use App\Models\AuctionRuleset;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Finds the ruleset an auction should be created from.
 *
 * This is the seam the future auction-creation service will call. It hands
 * back an immutable {@see AuctionRules} value object, never the Eloquent
 * model, so callers cannot accidentally hold a live reference to mutable
 * configuration.
 *
 * Reads go through Laravel's cache contract. The active configuration changes
 * rarely and is read on every auction creation, so it caches well; moving the
 * store to Redis later is a configuration change and touches nothing here.
 */
class RulesetResolver
{
    public const CACHE_KEY_DEFAULT = 'auction.ruleset.default';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    /**
     * The globally designated default ruleset, if one exists.
     */
    public function default(): ?AuctionRuleset
    {
        $id = $this->cache->rememberForever(
            self::CACHE_KEY_DEFAULT,
            // Only the id is cached. The model is loaded fresh so callers
            // never receive a stale copy of a row that has since changed.
            fn (): ?int => AuctionRuleset::query()
                ->active()
                ->where('is_default', true)
                ->value('id'),
        );

        return $id === null ? null : AuctionRuleset::find($id);
    }

    /**
     * The active version of a named lineage, if any.
     */
    public function activeByName(string $name): ?AuctionRuleset
    {
        return AuctionRuleset::query()->active()->where('name', $name)->first();
    }

    /**
     * Build the immutable rules a new auction will be created with.
     *
     * The checkout price always comes from the caller, because it is a
     * property of the product being auctioned rather than of the rules. The
     * ruleset supplies it only as a fallback default.
     */
    public function rulesFor(?Money $checkoutPrice = null, ?string $rulesetName = null): AuctionRules
    {
        $ruleset = $rulesetName === null
            ? $this->default()
            : $this->activeByName($rulesetName);

        if ($ruleset === null) {
            throw InvalidAuctionRules::because(
                $rulesetName === null
                    ? 'No default auction ruleset is active. Activate one before creating auctions.'
                    : "No active auction ruleset named [{$rulesetName}] exists."
            );
        }

        return $ruleset->toRules($checkoutPrice);
    }

    public function flush(): void
    {
        $this->cache->forget(self::CACHE_KEY_DEFAULT);
    }
}
