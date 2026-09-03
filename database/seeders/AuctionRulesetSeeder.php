<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ForfeitPolicy;
use App\Enums\RulesetStatus;
use App\Models\AuctionRuleset;
use Illuminate\Database\Seeder;

/**
 * Reference data: a safe starting auction configuration.
 *
 * This is a real, conservative default -- not sample data. It carries no
 * checkout price, because a price belongs to the product being auctioned and
 * inventing one here would be fabricating a figure the business never chose.
 * Auction creation supplies the price; this ruleset supplies everything else.
 *
 * Safe to run repeatedly: it will not overwrite a ruleset an administrator
 * has since edited or superseded.
 */
class AuctionRulesetSeeder extends Seeder
{
    public const DEFAULT_NAME = 'Standard Auction';

    public function run(): void
    {
        if (AuctionRuleset::where('name', self::DEFAULT_NAME)->exists()) {
            return;
        }

        $ruleset = new AuctionRuleset([
            'name' => self::DEFAULT_NAME,
            'description' => 'Conservative starting configuration for Last Bidder Standing auctions. '
                .'Create a new version rather than editing this one once it is active.',

            // One credit per bid.
            'bid_cost_credits' => 1,

            // A user cannot hold the lead twice in a row, so bidding against
            // yourself is impossible.
            'unique_leader' => true,

            // One second between bids from the same user on the same auction.
            'minimum_bid_interval_ms' => 1000,

            // Five minutes, extended by ten seconds whenever a bid lands in
            // the final ten seconds.
            'base_duration_seconds' => 300,
            'closing_window_seconds' => 10,
            'extension_seconds' => 10,

            // Both limits apply. Twenty extensions of ten seconds is 200
            // seconds, comfortably inside the 300-second absolute ceiling, so
            // an auction can never run more than five minutes past its close.
            'max_extensions' => 20,
            'max_extension_total_seconds' => 300,

            'checkout_deadline_minutes' => 60,
            'forfeit_policy' => ForfeitPolicy::Relist,

            // Deliberately null. See the class comment.
            'default_checkout_price_minor' => null,

            'delivery_fee_minor' => 0,
            'currency' => 'GHS',
            'tax_bps' => 0,
        ]);

        $ruleset->version = 1;
        $ruleset->status = RulesetStatus::Active;
        $ruleset->activated_at = now();
        $ruleset->is_default = true;
        $ruleset->save();
    }
}
