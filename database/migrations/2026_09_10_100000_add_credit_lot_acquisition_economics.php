<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Freeze what a purchased credit lot actually cost.
 *
 * The Store Wallet and the corrected Buy Now discount value consumed credits
 * at the price they were bought at, lot by lot:
 *
 *     consumed credits from a lot x that lot's acquisition rate
 *
 * To do that a lot must be able to say what it cost. Its acquisition figures
 * are copied from the `credit_purchases` row whose fulfilment created it, and
 * are then frozen -- so a package repriced tomorrow, or a promotional lot
 * created alongside, cannot retroactively change what past credits were worth.
 *
 * Columns are nullable, not defaulted. A non-purchased lot (promotional,
 * referral, adjustment) cost the customer no money; leaving it null says "free"
 * honestly, the same way `minimum_bid_credits` being null says "no rule".
 *
 * BACKFILL. Existing purchased lots gain their acquisition figures from the
 * purchase that created them, walked through the ledger row each lot carries.
 * Any purchased lot that cannot be reconciled to a purchase stops the
 * migration: silently valuing it at zero would manufacture a free credit out of
 * something the customer paid for, and inventing a figure would be fabrication.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_lots', function (Blueprint $table) {
            $table->unsignedBigInteger('acquisition_amount_minor')->nullable()
                ->after('remaining_amount');

            $table->char('acquisition_currency', 3)->nullable()
                ->after('acquisition_amount_minor');
        });

        // A purchased lot must be able to name the purchase that created it.
        // Not doing so means an existing customer paid money for credits whose
        // cost this schema cannot reconstruct, and that is an administrative
        // problem to resolve, not a gap to paper over with a guess.
        $unreconciled = DB::table('credit_lots')
            ->join('credit_transactions', 'credit_transactions.id', '=', 'credit_lots.credit_transaction_id')
            ->where('credit_lots.source_type', 'purchased')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('credit_purchases')
                    ->whereColumn('credit_purchases.id', '=', 'credit_transactions.reference_id')
                    ->where('credit_transactions.reference_type', 'App\Models\CreditPurchase');
            })
            ->count();

        if ($unreconciled > 0) {
            throw new RuntimeException(
                "Cannot migrate credit lots: {$unreconciled} purchased lot(s) have no "
                .'matching credit_purchases row, so their acquisition cost cannot be known. '
                .'Reconcile these before running this migration.'
            );
        }

        // Only purchased lots carry acquisition figures. Every other source
        // stays null, meaning the credits cost the customer nothing.
        DB::statement(<<<'SQL'
            UPDATE credit_lots
            JOIN credit_transactions
              ON credit_transactions.id = credit_lots.credit_transaction_id
            JOIN credit_purchases
              ON credit_purchases.id = credit_transactions.reference_id
             AND credit_transactions.reference_type = 'App\Models\CreditPurchase'
            SET credit_lots.acquisition_amount_minor = credit_purchases.amount_minor,
                credit_lots.acquisition_currency      = credit_purchases.currency
            WHERE credit_lots.source_type = 'purchased'
        SQL);

        // The pair arrives together or not at all. A purchased lot of zero
        // would mean somebody got credits for free, which is a contradiction
        // in terms; null and number are kept apart so "free" and "cost" are
        // never guessed at on the way in.
        DB::statement(<<<'SQL'
            ALTER TABLE credit_lots
            ADD CONSTRAINT chk_credit_lot_acquisition_pairing CHECK (
                (acquisition_amount_minor IS NULL AND acquisition_currency IS NULL)
                OR (acquisition_amount_minor IS NOT NULL AND acquisition_currency IS NOT NULL)
            )
        SQL);

        // Acquisition figures are history, frozen like everything else a lot
        // records. The lot itself may be decremented as credits are consumed,
        // but what its credits cost must never move underneath the valuations
        // and the Store Wallet issuances that were built on it.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER credit_lots_acquisition_frozen
            BEFORE UPDATE ON credit_lots
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.acquisition_amount_minor <=> OLD.acquisition_amount_minor)
                    OR NOT (NEW.acquisition_currency <=> OLD.acquisition_currency)
                THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'A credit lot acquisition is frozen once the lot exists.';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS credit_lots_acquisition_frozen');

        Schema::table('credit_lots', function (Blueprint $table) {
            $table->dropColumn(['acquisition_amount_minor', 'acquisition_currency']);
        });
    }
};
