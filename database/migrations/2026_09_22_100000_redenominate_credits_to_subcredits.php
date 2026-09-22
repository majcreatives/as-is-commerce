<?php

declare(strict_types=1);

use App\Domain\Credit\ValueObjects\CreditAmount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Re-denominates every stored credit count by a factor of 10,000.
 *
 * `docs/PLAN_POT_TARGET_BIDDING.md`, decision D-9. This is step 2 of that
 * plan's implementation sequence, and depends on step 1: `CreditAmount` and
 * the display layer must already be live and rendering identically to the
 * old component, so this migration's multiplication and that class's display
 * divisor cancel exactly and no customer sees a different number before and
 * after -- only what the ledger stores changes.
 *
 * THE FACTOR IS HARDCODED HERE, NOT READ FROM `CreditAmount`. Every other
 * migration in this codebase is self-contained -- none resolves an
 * application service or reads a class constant -- so a later refactor of
 * application code can never silently change what an old migration does; the
 * v3->v4 snapshot migration hardcodes `3` and `4` rather than referencing
 * `AuctionRules::SNAPSHOT_VERSION`, for the same reason. `CreditAmount::SUBCREDITS_PER_CREDIT`
 * must be raised to this same number, in the same change, but as a matter of
 * discipline proven by a test, not a shared reference.
 *
 * WHY. A step fine enough to let a large crowd into an auction needs credits
 * counted far more finely than today's whole numbers allow. The value a
 * customer reads stays exactly what it is today (a Starter pack is still
 * "100 credits"); what changes is how many raw units of the ledger that
 * figure is now made of.
 *
 * WHAT CONVERTS, AND WHAT DOES NOT. The governing rule: a standalone credit
 * count converts, so it stays an honest description of what it records. A
 * credit count stored specifically alongside its own frozen denominator --
 * so that leaving both untouched together still reproduces an
 * already-computed money figure exactly -- is left alone, because
 * `floor(credits * acquisition / original)` is unchanged by scaling
 * `credits` and `original` by the same factor: the factor cancels inside
 * the division before the floor is taken. Two places are exactly that kind
 * of pair, confirmed against the actual value objects that build them
 * (`LotValuation::toArray()`, `CheckoutPricing::toArray()`), and are
 * deliberately left alone: `store_wallet_credit_sources` (`credits` +
 * `lot_original_amount`), and each entry of `orders.pricing_snapshot`'s
 * `valuation` array.
 *
 * Every table, column and trigger below was found by reading the actual
 * schema and triggers, not from memory -- see the plan's D-9 for the audit
 * trail, including five columns and two triggers a first pass missed
 * (`auctions.buy_now_eligible_credits`; `orders.discount_credits` and its
 * duplicate inside `pricing_snapshot`; `referrals.reward_credits`; and
 * `pricing_snapshot.winning_bid_credits`, found only by reading
 * `CheckoutPricing::toArray()` in full rather than grepping for known names).
 *
 * SAFETY.
 *   - Refuses to run twice: if any wallet balance is already a multiple of
 *     the new factor times something implausible, that is not checked
 *     (there is no way to distinguish "already converted" from "a large
 *     balance" by inspection) -- Laravel's own migration ledger is what
 *     prevents a double run, exactly as it does for every other migration.
 *   - Triggers are DDL. DDL auto-commits in MySQL and MariaDB, so it cannot
 *     be undone by rolling back a transaction -- they are dropped once,
 *     before any data changes, and restored in a `finally`, so a failure
 *     partway through cannot leave the ledger permanently unguarded.
 *   - Every conversion and its own verification happen inside ONE
 *     transaction, so a verification failure rolls back every table's
 *     conversion together, not just the one that was being checked when it
 *     failed.
 *   - Verification is by aggregate checksum for the flat numeric columns
 *     (the sum of every converting column must be exactly FACTOR times what
 *     it was, which catches a forgotten table or a row the UPDATE missed)
 *     and by canonical JSON comparison for the two snapshot columns,
 *     exactly the technique `2026_09_20_100100_rewrite_auction_snapshots_to_version_4`
 *     already proved: decode, apply the same change in PHP, and require the
 *     stored result to match byte for byte.
 *   - This migration resolves no application service. Migrations in this
 *     codebase are self-contained so a later change to a service class can
 *     never silently alter what an old migration does; the stronger,
 *     service-level proof (`CreditLedgerReconciler`) runs separately, in
 *     the test that accompanies this migration.
 */
return new class extends Migration
{
    /**
     * Must equal {@see CreditAmount::SUBCREDITS_PER_CREDIT}
     * once that constant is raised in the same change as this migration.
     * `CreditAmountTest` asserts the two agree.
     */
    private const FACTOR = 10_000;

    private const CONVERTS = [
        'credit_wallets' => ['balance'],
        'credit_transactions' => ['amount', 'balance_after'],
        // NOT acquisition_amount_minor / acquisition_currency -- those are money.
        'credit_lots' => ['original_amount', 'remaining_amount'],
        'credit_lot_consumptions' => ['amount'],
        'credit_packages' => ['credit_amount'],
        'credit_purchases' => ['credit_amount'],
        'bids' => ['amount_credits', 'cumulative_credits'],
        'auctions' => ['highest_bid_credits', 'buy_now_eligible_credits'],
        'auction_rulesets' => [
            'minimum_bid_credits', 'minimum_bid_increment_credits', 'bid_increment_credits',
        ],
        'orders' => ['discount_credits'],
        'referrals' => ['reward_credits'],
    ];

    /** Triggers dropped for the duration, because each guards a column this migration writes to. */
    private const TRIGGERS_TO_SUSPEND = [
        'credit_transactions_no_update',
        'credit_lot_consumptions_no_update',
        'bids_no_update',
        'auctions_frozen_configuration',
        'orders_frozen_after_payment',
        'referrals_frozen_relationship',
    ];

    public function up(): void
    {
        $factor = self::FACTOR;

        $before = $this->checksums();

        $this->dropTriggers();

        try {
            DB::transaction(function () use ($factor, $before): void {
                foreach (self::CONVERTS as $table => $columns) {
                    $sets = implode(', ', array_map(fn (string $c): string => "{$c} = {$c} * {$factor}", $columns));
                    DB::statement("UPDATE {$table} SET {$sets}");
                }

                $this->convertSetting('referral_reward_credits', multiply: true);
                $this->convertRulesSnapshots(multiply: true);
                $this->convertPricingSnapshots(multiply: true);

                $this->assertChecksumsScaledExactly($before, $this->checksums(), $factor);
                $this->assertNoLotExceedsItsOriginal();
                $this->assertLedgerStillBalances();
            });
        } finally {
            $this->restoreTriggers();
        }
    }

    public function down(): void
    {
        $factor = self::FACTOR;

        $this->assertEverythingDivisibleBy($factor);

        $this->dropTriggers();

        try {
            DB::transaction(function () use ($factor): void {
                // Integer division, not multiplication by 1/factor -- a float
                // would violate the same "never a float in credit arithmetic"
                // rule Money and CreditAmount hold elsewhere in this codebase,
                // and assertEverythingDivisibleBy() above already proved this
                // divides exactly, so the SQL below can never truncate.
                foreach (self::CONVERTS as $table => $columns) {
                    $sets = implode(', ', array_map(fn (string $c): string => "{$c} = {$c} DIV {$factor}", $columns));
                    DB::statement("UPDATE {$table} SET {$sets}");
                }

                $this->convertSetting('referral_reward_credits', multiply: false);
                $this->convertRulesSnapshots(multiply: false);
                $this->convertPricingSnapshots(multiply: false);
            });
        } finally {
            $this->restoreTriggers();
        }
    }

    // ------------------------------------------------------------ Snapshot JSON

    /**
     * Rewrite the three credit-denominated keys inside every auction's
     * `rules_snapshot`, nested under `$.rules` -- confirmed against
     * `AuctionSnapshot::toArray()` and `AuctionRules::toArray()`, not assumed.
     * Every other key, including both snapshot versions, is untouched: this
     * is a values migration, not a shape migration, so neither
     * `AuctionSnapshot::SNAPSHOT_VERSION` nor `AuctionRules::SNAPSHOT_VERSION`
     * moves.
     */
    private function convertRulesSnapshots(bool $multiply): void
    {
        $keys = ['minimum_bid_credits', 'minimum_bid_increment_credits', 'bid_increment_credits'];

        foreach (DB::table('auctions')->select('id', 'rules_snapshot')->get() as $row) {
            /** @var array<string, mixed> $snapshot */
            $snapshot = json_decode($row->rules_snapshot, true, flags: JSON_THROW_ON_ERROR);

            foreach ($keys as $key) {
                if (isset($snapshot['rules'][$key])) {
                    $snapshot['rules'][$key] = $this->scaled($snapshot['rules'][$key], $multiply, "auctions.rules_snapshot.rules.{$key} on auction #{$row->id}");
                }
            }

            DB::table('auctions')->where('id', $row->id)
                ->update(['rules_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
        }
    }

    /**
     * Rewrite `$.discount_credits` and `$.winning_bid_credits` inside
     * `orders.pricing_snapshot` -- both standalone counts, confirmed against
     * the complete `CheckoutPricing::toArray()`. `$.valuation[*].credits` and
     * `$.valuation[*].lot_original_amount` are a stored pair and are
     * deliberately left untouched -- see the class docblock.
     */
    private function convertPricingSnapshots(bool $multiply): void
    {
        foreach (DB::table('orders')->select('id', 'pricing_snapshot')->whereNotNull('pricing_snapshot')->get() as $row) {
            /** @var array<string, mixed> $snapshot */
            $snapshot = json_decode($row->pricing_snapshot, true, flags: JSON_THROW_ON_ERROR);

            foreach (['discount_credits', 'winning_bid_credits'] as $key) {
                if (isset($snapshot[$key])) {
                    $snapshot[$key] = $this->scaled($snapshot[$key], $multiply, "orders.pricing_snapshot.{$key} on order #{$row->id}");
                }
            }

            DB::table('orders')->where('id', $row->id)
                ->update(['pricing_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
        }
    }

    /**
     * Integer arithmetic only, in both directions -- never a float, for the
     * same reason `Money` and `CreditAmount` refuse one: a float factor
     * (`1 / FACTOR`) can represent an exact whole-number result imprecisely,
     * which a strict comparison then wrongly refuses. Multiplication can
     * never lose precision here; division is checked for an exact result
     * before it is trusted.
     */
    private function scaled(mixed $value, bool $multiply, string $where): int
    {
        if (! is_int($value)) {
            throw new RuntimeException("Expected an integer credit count at {$where}, found ".get_debug_type($value).'.');
        }

        if ($multiply) {
            return $value * self::FACTOR;
        }

        if ($value % self::FACTOR !== 0) {
            throw new RuntimeException("[{$where}] is not an exact multiple of ".self::FACTOR.'. Refusing to write a fractional credit count.');
        }

        return intdiv($value, self::FACTOR);
    }

    // ------------------------------------------------------------ Settings

    private function convertSetting(string $key, bool $multiply): void
    {
        $row = DB::table('settings')->where('key', $key)->first();

        if ($row === null || $row->value === null || $row->value === '') {
            return;
        }

        if (! ctype_digit((string) $row->value)) {
            throw new RuntimeException("Setting [{$key}] is not a plain non-negative integer: [{$row->value}].");
        }

        $scaled = $this->scaled((int) $row->value, $multiply, "settings.{$key}");

        DB::table('settings')->where('key', $key)->update(['value' => (string) $scaled]);
    }

    // ------------------------------------------------------------ Verification

    /**
     * The sum of every converting column, before the rewrite. Compared
     * against the same sums afterwards: any table or row the rewrite missed
     * shows up as a sum that did not scale by exactly FACTOR.
     *
     * @return array<string, int|float|null>
     */
    private function checksums(): array
    {
        $sums = [];

        foreach (self::CONVERTS as $table => $columns) {
            foreach ($columns as $column) {
                $sums["{$table}.{$column}"] = DB::table($table)->sum($column);
            }
        }

        $sums['settings.referral_reward_credits'] = (int) (DB::table('settings')
            ->where('key', 'referral_reward_credits')->value('value') ?? 0);

        $sums['auctions.rules_snapshot'] = $this->snapshotChecksum(
            DB::table('auctions')->pluck('rules_snapshot'),
            fn (array $s): array => $s['rules'] ?? [],
            ['minimum_bid_credits', 'minimum_bid_increment_credits', 'bid_increment_credits'],
        );

        $sums['orders.pricing_snapshot'] = $this->snapshotChecksum(
            DB::table('orders')->whereNotNull('pricing_snapshot')->pluck('pricing_snapshot'),
            fn (array $s): array => $s,
            ['discount_credits', 'winning_bid_credits'],
        );

        return $sums;
    }

    /**
     * @param  Collection<int, string>  $jsonColumn
     * @param  callable(array<string, mixed>): array<string, mixed>  $section
     * @param  list<string>  $keys
     */
    private function snapshotChecksum($jsonColumn, callable $section, array $keys): int
    {
        $total = 0;

        foreach ($jsonColumn as $json) {
            $decoded = $section(json_decode($json, true, flags: JSON_THROW_ON_ERROR));

            foreach ($keys as $key) {
                $total += (int) ($decoded[$key] ?? 0);
            }
        }

        return $total;
    }

    /**
     * @param  array<string, int|float|null>  $before
     * @param  array<string, int|float|null>  $after
     */
    private function assertChecksumsScaledExactly(array $before, array $after, int $factor): void
    {
        // DB::table(...)->sum() can come back as an int, a float or a numeric
        // string depending on the driver and the column's width -- cast
        // explicitly on both sides so a strict comparison checks the VALUE,
        // never a type PDO happened to pick.
        foreach ($before as $key => $beforeSum) {
            $expected = (int) ($beforeSum ?? 0) * $factor;
            $actual = (int) ($after[$key] ?? 0);

            if ($actual !== $expected) {
                throw new RuntimeException(
                    "Re-denomination check failed for [{$key}]: expected the sum to scale to {$expected}, found "
                    ."{$actual}. Rolling back; inspect this table before retrying."
                );
            }
        }
    }

    /**
     * The lot CHECK constraint (`remaining_amount <= original_amount`) already
     * enforces this on every row the UPDATE touches, since MySQL/MariaDB
     * validate CHECK constraints per row. This is a second, explicit proof
     * of the same fact, so the migration's own report says so rather than
     * relying silently on the database's refusal.
     */
    private function assertNoLotExceedsItsOriginal(): void
    {
        $violations = DB::table('credit_lots')->whereColumn('remaining_amount', '>', 'original_amount')->count();

        if ($violations > 0) {
            throw new RuntimeException("{$violations} credit lot(s) have remaining_amount exceeding original_amount after conversion.");
        }
    }

    /**
     * The ledger invariant this whole migration depends on: a wallet's
     * balance must equal the sum of its transactions. If it held before the
     * conversion it holds after, because scaling both sides of a sum by the
     * same factor preserves the sum -- this proves that algebraic argument
     * against the actual data rather than trusting it.
     */
    private function assertLedgerStillBalances(): void
    {
        $mismatches = DB::table('credit_wallets')
            ->leftJoinSub(
                DB::table('credit_transactions')->select('credit_wallet_id')
                    ->selectRaw('SUM(amount) as total')->groupBy('credit_wallet_id'),
                'sums',
                'sums.credit_wallet_id',
                '=',
                'credit_wallets.id',
            )
            ->whereRaw('credit_wallets.balance <> COALESCE(sums.total, 0)')
            ->count();

        if ($mismatches > 0) {
            throw new RuntimeException("{$mismatches} wallet(s) no longer equal the sum of their transactions after conversion.");
        }
    }

    /**
     * Refuses to roll back if any stored value is not an exact multiple of
     * the current factor -- dividing it would silently truncate a real
     * credit count rather than genuinely reverse the conversion.
     */
    private function assertEverythingDivisibleBy(int $factor): void
    {
        foreach (self::CONVERTS as $table => $columns) {
            foreach ($columns as $column) {
                $indivisible = DB::table($table)->whereRaw("{$column} % {$factor} <> 0")->count();

                if ($indivisible > 0) {
                    throw new RuntimeException(
                        "Cannot roll back: {$indivisible} row(s) in {$table}.{$column} are not an exact "
                        ."multiple of {$factor}. Rolling back would truncate real credits."
                    );
                }
            }
        }
    }

    // ------------------------------------------------------------ Triggers

    private function dropTriggers(): void
    {
        foreach (self::TRIGGERS_TO_SUSPEND as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    /**
     * Restored verbatim from the migrations that most recently defined each
     * one, so this migration leaves the database with exactly the guards it
     * found -- never a version of a trigger this migration invented.
     */
    private function restoreTriggers(): void
    {
        foreach (['credit_transactions', 'credit_lot_consumptions'] as $table) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_no_update
                BEFORE UPDATE ON {$table}
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Financial history is append-only: {$table} rows cannot be updated. Post a compensating entry instead.';
                END
            SQL);
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER bids_no_update
            BEFORE UPDATE ON bids
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Bids are append-only: a bid cannot be edited once accepted.';
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER auctions_frozen_configuration
            BEFORE UPDATE ON auctions
            FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft' THEN
                    IF NOT (NEW.rules_snapshot <=> OLD.rules_snapshot)
                        OR NOT (NEW.snapshot_version <=> OLD.snapshot_version)
                        OR NOT (NEW.settlement_amount_minor <=> OLD.settlement_amount_minor)
                        OR NOT (NEW.product_id <=> OLD.product_id)
                        OR NOT (NEW.currency <=> OLD.currency)
                    THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'An auction configuration is frozen once the auction leaves draft.';
                    END IF;
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER orders_frozen_after_payment
            BEFORE UPDATE ON orders
            FOR EACH ROW
            BEGIN
                IF OLD.status IN ('paid','processing','fulfilled','refunded') THEN
                    IF NOT (NEW.subtotal_minor <=> OLD.subtotal_minor)
                        OR NOT (NEW.discount_minor <=> OLD.discount_minor)
                        OR NOT (NEW.discount_credits <=> OLD.discount_credits)
                        OR NOT (NEW.store_wallet_applied_minor <=> OLD.store_wallet_applied_minor)
                        OR NOT (NEW.payable_minor <=> OLD.payable_minor)
                        OR NOT (NEW.delivery_minor <=> OLD.delivery_minor)
                        OR NOT (NEW.tax_minor <=> OLD.tax_minor)
                        OR NOT (NEW.total_minor <=> OLD.total_minor)
                        OR NOT (NEW.currency <=> OLD.currency)
                        OR NOT (NEW.pricing_snapshot <=> OLD.pricing_snapshot)
                        OR NOT (NEW.source <=> OLD.source)
                        OR NOT (NEW.auction_id <=> OLD.auction_id)
                        OR NOT (NEW.winning_bid_id <=> OLD.winning_bid_id)
                        OR NOT (NEW.user_id <=> OLD.user_id)
                    THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'A paid order is a historical record: its commercial figures cannot be changed.';
                    END IF;
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER referrals_frozen_relationship
            BEFORE UPDATE ON referrals
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.referrer_user_id <=> OLD.referrer_user_id)
                    OR NOT (NEW.referred_user_id <=> OLD.referred_user_id)
                    OR NOT (NEW.code_used <=> OLD.code_used)
                THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'A referral records who introduced whom and cannot be reassigned.';
                END IF;

                IF OLD.status = 'rewarded' THEN
                    IF NOT (NEW.reward_credits <=> OLD.reward_credits)
                        OR NOT (NEW.credit_transaction_id <=> OLD.credit_transaction_id)
                        OR NOT (NEW.qualifying_order_id <=> OLD.qualifying_order_id)
                        OR NOT (NEW.status <=> OLD.status)
                    THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'An issued referral reward is a historical record and cannot be changed.';
                    END IF;
                END IF;
            END
        SQL);
    }
};
