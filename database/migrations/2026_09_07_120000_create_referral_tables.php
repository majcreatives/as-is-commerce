<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who invited whom, and what came of it.
 *
 * TWO CHANGES, AND DELIBERATELY NOT THREE. A referral code is one immutable
 * string per customer with no lifecycle of its own, so it is a column on
 * `users` rather than a table. A reward is at most one per referral, so it
 * lives on the referral row rather than in a table that would only ever be
 * joined one-to-one. Both were considered and both were rejected as tables
 * that would carry no authority a simpler shape does not.
 *
 * NO SECOND WALLET, ANYWHERE. There is no referral balance, no reward balance,
 * no bonus balance and no promotional wallet. Referral credits are ordinary
 * credits: they enter through `CreditLedgerService`, land in a credit lot whose
 * source is `referral`, and are consumed in the ledger's own deterministic
 * order like everything else. The column here that matters is
 * `credit_transaction_id` -- a pointer into the real ledger, not a copy of it.
 *
 * ONE REFERRER PER CUSTOMER, ENFORCED BY THE DATABASE. `referred_user_id` is
 * unique, so a second attribution is a constraint violation rather than an
 * application check two concurrent registrations could both pass. A customer
 * cannot be re-attributed to somebody else by editing a URL, because there is
 * no second row for them to occupy.
 *
 * ONE REWARD PER REFERRAL, ALSO ENFORCED BY THE DATABASE. Two credit
 * transactions cannot point at one referral and one credit transaction cannot
 * be claimed by two referrals: `credit_transaction_id` is unique. The
 * application is idempotent as well, and this is what makes it a guarantee.
 *
 * THE REWARD AMOUNT IS A SNAPSHOT. `reward_credits` records what was actually
 * granted, not what the setting says today. An administrator raising the reward
 * from 50 to 100 must not retroactively make every past referral worth 100 --
 * that would rewrite history and disagree with the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A customer's own referral code. One string, immutable once issued,
        // and unique across the platform.
        //
        // A column rather than a table: it has no status, no history and no
        // relationships of its own, and a `referral_codes` table would be a
        // one-to-one join for a single value.
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 16)->nullable()->unique()->after('status');
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->id();

            // Restricted, both. A referral is evidence about credits that were
            // issued, and deleting either party would leave a reward nobody
            // could explain.
            $table->foreignId('referrer_user_id')->constrained('users')->restrictOnDelete();

            // Unique: one customer, one referrer, for ever. This is the
            // constraint that makes re-attribution impossible rather than
            // merely discouraged.
            $table->foreignId('referred_user_id')->unique()->constrained('users')->restrictOnDelete();

            // The code as it was actually used, copied rather than referenced.
            // If codes ever rotate, this still says which one brought somebody
            // in.
            $table->string('code_used', 16);

            $table->string('status', 20)->default('attributed');

            // ---- The qualifying event ------------------------------------
            //
            // The order whose verified payment qualified this referral.
            // Nullable until one exists, and restricted afterwards: it is the
            // evidence for the reward.
            $table->foreignId('qualifying_order_id')->nullable()
                ->constrained('orders')->restrictOnDelete();

            // ---- The reward ----------------------------------------------
            //
            // What was actually granted, snapshotted at the moment of issue.
            // Never recomputed from today's setting.
            $table->unsignedInteger('reward_credits')->nullable();

            // The ledger row the credits came in on. Unique, so one credit
            // transaction can never be claimed by two referrals, and a second
            // reward for one referral is a database refusal.
            $table->foreignId('credit_transaction_id')->nullable()->unique()
                ->constrained('credit_transactions')->restrictOnDelete();

            // Why an administrator refused it, when one did. Never a silent
            // deletion: the relationship stays, with a reason attached.
            $table->string('invalidation_reason', 500)->nullable();
            $table->foreignId('invalidated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('attributed_at')->useCurrent();
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            // A referrer's own list, and the cap count.
            $table->index(['referrer_user_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('qualifying_order_id');
        });

        // ---------------------------------------------------------------
        // Constraints. Repeated below the application, because a service can
        // be bypassed by a console command or a hand-run statement, and an
        // invariant that lives only in application code is a convention
        // rather than a guarantee.
        // ---------------------------------------------------------------

        DB::statement(<<<'SQL'
            ALTER TABLE referrals
            ADD CONSTRAINT chk_referrals_status CHECK (
                status IN ('attributed','qualified','rewarded','invalidated')
            ),
            ADD CONSTRAINT chk_referrals_reward_positive CHECK (
                reward_credits IS NULL OR reward_credits > 0
            )
        SQL);

        // Nobody refers themselves. The application refuses it too; this is
        // the version that survives a hand-run INSERT.
        DB::statement(<<<'SQL'
            ALTER TABLE referrals
            ADD CONSTRAINT chk_referrals_no_self_referral CHECK (
                referrer_user_id <> referred_user_id
            )
        SQL);

        // A rewarded referral must carry its evidence: what was granted, on
        // which ledger row, for which order, and when. Without this a row
        // could claim a reward it has nothing to show for.
        DB::statement(<<<'SQL'
            ALTER TABLE referrals
            ADD CONSTRAINT chk_referrals_rewarded_has_evidence CHECK (
                status <> 'rewarded'
                OR (
                    reward_credits IS NOT NULL
                    AND credit_transaction_id IS NOT NULL
                    AND qualifying_order_id IS NOT NULL
                    AND rewarded_at IS NOT NULL
                )
            ),
            ADD CONSTRAINT chk_referrals_qualified_has_order CHECK (
                status NOT IN ('qualified','rewarded') OR qualifying_order_id IS NOT NULL
            ),
            ADD CONSTRAINT chk_referrals_invalidated_has_reason CHECK (
                status <> 'invalidated' OR invalidation_reason IS NOT NULL
            )
        SQL);

        // The relationship and the reward are historical facts.
        //
        // The pair may not change at all: a referral records who brought whom,
        // and editing that would rewrite the past. The reward may be written
        // once, when it is issued, and never afterwards -- so an administrator
        // cannot revalue a reward that has already reached somebody's wallet,
        // and cannot point it at a different order or a different ledger row.
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

        // A referral that produced credits is never deleted. Deleting one
        // would leave credits in a wallet with nothing explaining them.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER referrals_rewarded_no_delete
            BEFORE DELETE ON referrals
            FOR EACH ROW
            BEGIN
                IF OLD.status = 'rewarded' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'A rewarded referral explains credits that were issued and cannot be deleted.';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS referrals_rewarded_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS referrals_frozen_relationship');

        Schema::dropIfExists('referrals');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['referral_code']);
            $table->dropColumn('referral_code');
        });
    }
};
