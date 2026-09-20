<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rules snapshot version 3 -> 4: every existing auction gains an explicit
 * `bid_model`, and it is `single_highest`.
 *
 * WHAT CHANGES, AND WHAT DOES NOT. Version 4 adds ONE key under `$.rules`. Every
 * existing auction was created under the single-highest model, so that is what
 * is written, and nothing else about any snapshot moves: not the minimum bid,
 * not the increment, not the timing, not the winner rule. Every existing
 * auction behaves identically afterwards -- which this migration checks itself
 * rather than asserting.
 *
 * WHY A VERSION BUMP. The engine refuses any snapshot version but the current
 * one rather than reinterpret it, and the shape has changed. This is the
 * sanctioned moment to touch a frozen snapshot, exactly as in
 * 2026_09_10_100200: the freeze trigger is dropped for the duration and
 * restored verbatim, and a snapshot is never edited any other way.
 *
 * THE TRIGGER IS RESTORED WHATEVER HAPPENS. It comes back in a `finally`, so a
 * failure part-way through cannot leave every auction editable.
 *
 * ANYTHING UNEXPECTED STOPS THE MIGRATION. A version other than 3 or 4, or a
 * winner rule that is not the single-highest one, means a snapshot this
 * migration does not understand. Guessing would rewrite history; stopping asks
 * a human. Version 4 rows are left alone, so the migration is safe to run twice.
 *
 * FORWARD ONLY FOR NEW-MODEL AUCTIONS. `down()` can restore version 3 for
 * single-highest auctions, which lose only the key this added. It refuses to
 * run if any auction follows another model, because version 3 cannot describe
 * one and writing it would make a snapshot lie.
 */
return new class extends Migration
{
    private const SINGLE_HIGHEST_WINNER_RULE = 'highest_valid_credit_bid';

    public function up(): void
    {
        $rows = DB::table('auctions')->select('id', 'rules_snapshot')->get();

        $versions = $rows
            ->map(fn ($row): int => (int) (json_decode($row->rules_snapshot, true)['rules']['snapshot_version'] ?? 0))
            ->unique()
            ->values()
            ->all();

        if ($versions !== [] && array_diff($versions, [3, 4]) !== []) {
            throw new RuntimeException(
                'Cannot migrate auction snapshots: found rules snapshot version(s) '
                .implode(', ', array_map('strval', $versions))
                .' in the database. Only version 3 can be migrated to version 4. '
                .'Inspect these rows before continuing.'
            );
        }

        $foreignWinnerRules = $rows
            ->filter(fn ($row): bool => (int) (json_decode($row->rules_snapshot, true)['rules']['snapshot_version'] ?? 0) === 3)
            ->map(fn ($row): string => (string) (json_decode($row->rules_snapshot, true)['rules']['winner_rule'] ?? ''))
            ->unique()
            ->reject(fn (string $rule): bool => $rule === self::SINGLE_HIGHEST_WINNER_RULE)
            ->values()
            ->all();

        if ($foreignWinnerRules !== []) {
            throw new RuntimeException(
                'Cannot migrate auction snapshots: found winner rule(s) other than the '
                .'single-highest rule ('.implode(', ', $foreignWinnerRules).'). Every existing '
                .'auction is expected to be single-highest; inspect these rows before continuing.'
            );
        }

        DB::unprepared('DROP TRIGGER IF EXISTS auctions_frozen_configuration');

        try {
            // The rewrite and the check that it changed only what it meant to
            // are ONE transaction. A migration is not wrapped in one, so
            // without this a failed check would leave the rewrite committed.
            DB::transaction(function () use ($rows): void {
                DB::statement(<<<'SQL'
                    UPDATE auctions
                    SET rules_snapshot = JSON_SET(
                        rules_snapshot,
                        '$.rules.snapshot_version', 4,
                        '$.rules.bid_model', 'single_highest'
                    )
                    WHERE CAST(
                        JSON_UNQUOTE(JSON_EXTRACT(rules_snapshot, '$.rules.snapshot_version'))
                        AS UNSIGNED
                    ) = 3
                SQL);

                $this->assertNothingElseMoved($rows);
            });
        } finally {
            $this->restoreFreezeTrigger();
        }
    }

    public function down(): void
    {
        $foreign = DB::table('auctions')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(rules_snapshot, '$.rules.bid_model')) <> 'single_highest'")
            ->count();

        if ($foreign > 0) {
            throw new RuntimeException(
                'Cannot restore version 3 snapshots: '.$foreign.' auction(s) follow a bid model '
                .'that version 3 cannot describe. Writing it would make those snapshots lie.'
            );
        }

        DB::unprepared('DROP TRIGGER IF EXISTS auctions_frozen_configuration');

        try {
            DB::statement(<<<'SQL'
                UPDATE auctions
                SET rules_snapshot = JSON_SET(
                    JSON_REMOVE(rules_snapshot, '$.rules.bid_model'),
                    '$.rules.snapshot_version', 3
                )
                WHERE CAST(
                    JSON_UNQUOTE(JSON_EXTRACT(rules_snapshot, '$.rules.snapshot_version'))
                    AS UNSIGNED
                ) = 4
            SQL);
        } finally {
            $this->restoreFreezeTrigger();
        }
    }

    /**
     * Prove the rewrite changed the two keys it meant to and nothing else.
     *
     * Every version-3 snapshot is compared with what it is now: the same rules
     * apart from `snapshot_version` and `bid_model`, and the same auction half.
     * A difference anywhere else throws inside the surrounding transaction, so
     * the rewrite is undone rather than leaving history subtly altered.
     *
     * @param  Collection<int, stdClass>  $before
     */
    private function assertNothingElseMoved($before): void
    {
        foreach ($before as $row) {
            $old = json_decode($row->rules_snapshot, true);

            if ((int) ($old['rules']['snapshot_version'] ?? 0) !== 3) {
                continue;
            }

            $new = json_decode(DB::table('auctions')->where('id', $row->id)->value('rules_snapshot'), true);

            $expected = $old;
            $expected['rules']['snapshot_version'] = 4;
            $expected['rules']['bid_model'] = 'single_highest';

            if ($this->canonical($new) !== $this->canonical($expected)) {
                throw new RuntimeException(
                    'Auction #'.$row->id.' changed in a way this migration did not intend. '
                    .'The rewrite has been rolled back; inspect the snapshot before continuing.'
                );
            }
        }
    }

    /**
     * Key order and whitespace are storage details, not content.
     *
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function canonical(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }

        return $value;
    }

    /**
     * Restored verbatim from 2026_09_10_100200, the last migration to define it.
     */
    private function restoreFreezeTrigger(): void
    {
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
    }
};
