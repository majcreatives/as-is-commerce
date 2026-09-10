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
 * This is a real, conservative default -- not sample data.
 *
 * It carries no settlement price: what a normal auction winner pays has not
 * been decided, and encoding an amount would be inventing that decision. It
 * also leaves every undecided bid rule null rather than filling the schema
 * with numbers nobody chose.
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
            'description' => 'Conservative starting configuration. The highest valid credit bid wins '
                .'at normal closure. Create a new version rather than editing this one once it is active.',

            // Bid rules are deliberately unset. The minimum bid, the minimum
            // increment, and whether a participant may raise their own
            // standing bid have not been decided by the business, and null
            // says "no rule" honestly where a number would look like a
            // decision nobody made.
            'minimum_bid_credits' => null,
            'minimum_bid_increment_credits' => null,
            'allow_bid_increase' => null,

            // Three seconds between bids from the same user on the same auction.
            // Anti-spam rather than a business rule, so a value is safe here.
            'minimum_bid_interval_ms' => 3000,

            // Five minutes.
            'base_duration_seconds' => 300,

            // Late-bid extension is switched off. It remains meaningful under
            // a highest-bid auction -- it stops sniping -- but whether to use
            // it, and with what window, has not been decided. Seeding it on
            // with invented numbers would make that choice by default.
            'closing_window_seconds' => 0,
            'extension_seconds' => 0,
            'max_extensions' => 0,
            'max_extension_total_seconds' => 0,

            'checkout_deadline_minutes' => 60,
            'forfeit_policy' => ForfeitPolicy::Relist,

            // Buy Now is available and ends the auction when it succeeds.
            'buy_now_enabled' => true,

            // Consumed bid credits take their actual purchased cash value off
            // the Buy Now price -- each lot valued at the price it was bought
            // at, not at a system-wide rate per credit.
            'buy_now_credit_discount_enabled' => true,

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
