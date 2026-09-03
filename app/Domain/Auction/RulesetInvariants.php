<?php

declare(strict_types=1);

namespace App\Domain\Auction;

use App\Domain\Auction\Exceptions\InvalidAuctionRules;
use App\Domain\Auction\ValueObjects\AuctionRules;

/**
 * Cross-field rules that a ruleset must satisfy.
 *
 * These are the combinations that are individually valid but contradictory
 * together, so a per-field check cannot catch them. Kept in one place and
 * shared by the actions and the admin form, so the domain and the UI can
 * never disagree about what a legal configuration is.
 *
 * Single-field bounds live in the database CHECK constraints and in
 * {@see AuctionRules}; this class only
 * covers the relationships between fields.
 */
final class RulesetInvariants
{
    /**
     * @param  array<string, mixed>  $values
     * @return list<string> Human-readable problems, empty when valid.
     */
    public static function problems(array $values): array
    {
        $problems = [];

        $baseDuration = (int) ($values['base_duration_seconds'] ?? 0);
        $closingWindow = (int) ($values['closing_window_seconds'] ?? 0);
        $extensionSeconds = (int) ($values['extension_seconds'] ?? 0);
        $maxExtensions = (int) ($values['max_extensions'] ?? 0);
        $maxExtensionTotal = (int) ($values['max_extension_total_seconds'] ?? 0);

        // A closing window at least as long as the auction would put every
        // auction into its extension phase the moment it opened.
        if ($closingWindow > $baseDuration) {
            $problems[] = 'The closing window cannot be longer than the base duration.';
        }

        $extensionsRequested = $maxExtensions > 0 && $extensionSeconds > 0;

        if ($extensionsRequested) {
            // A total budget shorter than a single extension could never grant
            // even one, so the configuration says two contradictory things.
            if ($maxExtensionTotal < $extensionSeconds) {
                $problems[] = 'The maximum total extension must be at least as long as one extension, '
                    .'or extensions must be disabled by setting the maximum extensions to zero.';
            }

            // Extensions are triggered by bids inside the closing window. With
            // no window there is no trigger, so they could never fire.
            if ($closingWindow === 0) {
                $problems[] = 'Extensions need a closing window greater than zero, otherwise they can never trigger.';
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $values
     *
     * @throws InvalidAuctionRules
     */
    public static function assert(array $values): void
    {
        $problems = self::problems($values);

        if ($problems !== []) {
            throw InvalidAuctionRules::because(implode(' ', $problems));
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function passes(array $values): bool
    {
        return self::problems($values) === [];
    }
}
