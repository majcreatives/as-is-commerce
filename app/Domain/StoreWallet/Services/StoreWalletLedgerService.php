<?php

declare(strict_types=1);

namespace App\Domain\StoreWallet\Services;

use App\Domain\Credit\Exceptions\InvalidLedgerOperation;
use App\Domain\Shared\Money\Money;
use App\Domain\StoreWallet\Exceptions\InsufficientStoreWalletValue;
use App\Domain\StoreWallet\ValueObjects\CreditValuation;
use App\Domain\StoreWallet\ValueObjects\LotValuation;
use App\Enums\StoreWalletTransactionType;
use App\Models\StoreWallet;
use App\Models\StoreWalletCreditSource;
use App\Models\StoreWalletTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only sanctioned way to move Store Wallet value.
 *
 * Structurally parallel to the credit and cash ledgers, and held to the same
 * rules: the transaction table is authoritative, rows are append-only, the
 * balance is materialized inside the same transaction as the row that
 * justifies it, and amounts are integer minor units that never touch a float.
 *
 * There are no lots here. A credit lot exists because credits are not
 * fungible -- they expire on different dates and were bought at different
 * prices. Store Wallet value is money-shaped: every pesewa is the same as
 * every other, nothing here expires, and there is no policy that would need to
 * know which issuance a spend came out of. Adding lots would be inventing a
 * distinction the business has not made.
 *
 * LOCKING: the store_wallets row, SELECT ... FOR UPDATE, before the balance is
 * read. That is what makes overspending impossible rather than unlikely -- a
 * second debit blocks until the first commits and then reads the balance the
 * first left behind.
 *
 * WHERE THIS SITS IN THE GLOBAL LOCK ORDER. After the auction and the order,
 * alongside the other wallets:
 *
 *     order -> auction -> product -> credit wallet -> store wallet
 *
 * Nothing here reaches back up that list. An issuance is called with the
 * auction already locked; a spend is called with the order already locked.
 */
class StoreWalletLedgerService
{
    /**
     * Ensure a user has a Store Wallet, creating an empty one if not.
     *
     * A new wallet starts at zero, which is a real balance rather than a
     * placeholder.
     */
    public function walletFor(User $user, string $currency = 'GHS'): StoreWallet
    {
        $wallet = StoreWallet::firstOrCreate(
            ['user_id' => $user->id],
            ['currency' => strtoupper($currency)],
        );

        // Eloquent does not copy a DB-supplied default back into the model it
        // just created, so a fresh zero-balance wallet would read as null.
        $wallet->balance_minor ??= 0;

        return $wallet;
    }

    /**
     * The balance, read fresh from the wallet row.
     */
    public function balanceFor(User $user, string $currency = 'GHS'): Money
    {
        return $this->walletFor($user, $currency)->balance();
    }

    /**
     * Issue value earned by consumed purchased credits.
     *
     * The valuation is passed in already computed, because deciding what
     * credits were worth is a different job from recording that they were
     * given -- and because the same valuation is what the Buy Now path uses,
     * so the two cannot drift.
     *
     * IDEMPOTENT BY THE DATABASE. `idempotencyKey` is unique on the table. A
     * second attempt with the same key finds the existing row and returns it
     * rather than issuing again, and because the uniqueness is a constraint
     * rather than a check-then-insert, two concurrent attempts cannot both
     * pass -- one inserts and the other catches the violation.
     *
     * Returns null when the valuation came to nothing, which is the ordinary
     * outcome for a bidder who used only free credits. Posting a zero-value
     * row would be recording an event that did not move anything.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function issue(
        StoreWallet $wallet,
        CreditValuation $valuation,
        StoreWalletTransactionType $type,
        string $idempotencyKey,
        ?Model $reference = null,
        ?string $description = null,
        array $metadata = [],
    ): ?StoreWalletTransaction {
        if (! $type->isCredit()) {
            throw InvalidLedgerOperation::because(
                "Transaction type [{$type->value}] does not add Store Wallet value."
            );
        }

        if (! $valuation->total->isPositive()) {
            return null;
        }

        // Checked before the write as well as being enforced by the unique
        // index, so the ordinary repeat case costs a read rather than a failed
        // insert and a caught exception.
        $existing = StoreWalletTransaction::where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use (
                $wallet, $valuation, $type, $idempotencyKey, $reference, $description, $metadata
            ): StoreWalletTransaction {
                $locked = $this->lockWallet($wallet);

                $transaction = $this->post(
                    wallet: $locked,
                    type: $type,
                    amountMinor: $valuation->total->minor,
                    reference: $reference,
                    description: $description,
                    metadata: $metadata + ['valuation' => $valuation->toArray()],
                    idempotencyKey: $idempotencyKey,
                );

                $this->recordCreditSources($transaction, $valuation);

                Log::info('Store Wallet value issued', [
                    'operation' => 'store_wallet.issue',
                    'wallet_id' => $locked->id,
                    'user_id' => $locked->user_id,
                    'transaction_id' => $transaction->id,
                    'type' => $type->value,
                    'amount_minor' => $valuation->total->minor,
                    'paid_credits' => $valuation->paidCredits,
                    'free_credits' => $valuation->freeCredits(),
                    'idempotency_key' => $idempotencyKey,
                ]);

                return $transaction;
            });
        } catch (QueryException $e) {
            // Another transaction inserted the same key between the read above
            // and this write. That is the concurrent-issuance case, and the
            // right answer is the row that won rather than an error: the
            // customer has been credited exactly once, which is the guarantee.
            $winner = StoreWalletTransaction::where('idempotency_key', $idempotencyKey)->first();

            if ($winner !== null) {
                return $winner;
            }

            throw $e;
        }
    }

    /**
     * Commit value to something, reducing the balance.
     *
     * @param  array<string, mixed>  $metadata
     *
     * @throws InsufficientStoreWalletValue
     */
    public function debit(
        StoreWallet $wallet,
        StoreWalletTransactionType $type,
        Money $amount,
        ?Model $reference = null,
        ?string $description = null,
        array $metadata = [],
        ?User $actor = null,
        ?string $idempotencyKey = null,
    ): StoreWalletTransaction {
        if (! $amount->isPositive()) {
            throw InvalidLedgerOperation::because(
                'A Store Wallet debit must be given a positive amount to remove.'
            );
        }

        if (! $type->isDebit()) {
            throw InvalidLedgerOperation::because(
                "Transaction type [{$type->value}] does not remove Store Wallet value."
            );
        }

        return DB::transaction(function () use (
            $wallet, $type, $amount, $reference, $description, $metadata, $actor, $idempotencyKey
        ): StoreWalletTransaction {
            $locked = $this->lockWallet($wallet);

            // Read after the lock, never before. Two simultaneous spends
            // therefore cannot both measure themselves against the same
            // starting balance.
            if ($locked->balance_minor < $amount->minor) {
                throw InsufficientStoreWalletValue::for($amount, $locked->balance());
            }

            return $this->post(
                wallet: $locked,
                type: $type,
                amountMinor: -$amount->minor,
                reference: $reference,
                description: $description,
                metadata: $metadata,
                idempotencyKey: $idempotencyKey,
                actor: $actor,
            );
        });
    }

    /**
     * Return value that was committed to something that did not happen.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function credit(
        StoreWallet $wallet,
        StoreWalletTransactionType $type,
        Money $amount,
        ?Model $reference = null,
        ?string $description = null,
        array $metadata = [],
        ?User $actor = null,
        ?string $idempotencyKey = null,
    ): StoreWalletTransaction {
        if (! $amount->isPositive()) {
            throw InvalidLedgerOperation::because('A Store Wallet credit must be a positive amount.');
        }

        if (! $type->isCredit()) {
            throw InvalidLedgerOperation::because(
                "Transaction type [{$type->value}] does not add Store Wallet value."
            );
        }

        return DB::transaction(function () use (
            $wallet, $type, $amount, $reference, $description, $metadata, $actor, $idempotencyKey
        ): StoreWalletTransaction {
            $locked = $this->lockWallet($wallet);

            return $this->post(
                wallet: $locked,
                type: $type,
                amountMinor: $amount->minor,
                reference: $reference,
                description: $description,
                metadata: $metadata,
                idempotencyKey: $idempotencyKey,
                actor: $actor,
            );
        });
    }

    /**
     * Whether a movement with this key has already been recorded.
     *
     * For callers that want to avoid preparing work that has already been
     * done. Never a substitute for the unique index, which is what actually
     * makes a repeat safe.
     */
    public function alreadyRecorded(string $idempotencyKey): bool
    {
        return StoreWalletTransaction::where('idempotency_key', $idempotencyKey)->exists();
    }

    /**
     * Recompute a wallet's balance from its transactions.
     *
     * Reports; it does not repair. Deciding to overwrite a stored balance is a
     * separate act from noticing it is wrong, and the same reasoning applies
     * here as in the credit ledger's reconciler: an automatic repair is a guess
     * about which of two disagreeing records is right, made by the code whose
     * bug may have caused the disagreement.
     *
     * @return array{matches: bool, projected_minor: int, ledger_minor: int}
     */
    public function verify(StoreWallet $wallet): array
    {
        $ledger = (int) StoreWalletTransaction::where('store_wallet_id', $wallet->getKey())
            ->sum('amount_minor');

        return [
            'matches' => $wallet->balance_minor === $ledger,
            'projected_minor' => $wallet->balance_minor,
            'ledger_minor' => $ledger,
        ];
    }

    // ------------------------------------------------------------ Internals

    /**
     * The lock, taken before the balance is read.
     *
     * Returns a freshly read instance. Callers must use the returned model
     * rather than the one passed in, whose attributes may be stale.
     */
    private function lockWallet(StoreWallet $wallet): StoreWallet
    {
        return StoreWallet::whereKey($wallet->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Write the movement and the balance it produces, together.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function post(
        StoreWallet $wallet,
        StoreWalletTransactionType $type,
        int $amountMinor,
        ?Model $reference,
        ?string $description,
        array $metadata,
        ?string $idempotencyKey,
        ?User $actor = null,
    ): StoreWalletTransaction {
        if (! $type->allowsAmount($amountMinor)) {
            throw InvalidLedgerOperation::because(
                "Transaction type [{$type->value}] does not permit an amount of {$amountMinor}."
            );
        }

        $balanceAfter = $wallet->balance_minor + $amountMinor;

        if ($balanceAfter < 0) {
            throw InvalidLedgerOperation::because('A Store Wallet balance may never go negative.');
        }

        $transaction = StoreWalletTransaction::create([
            'store_wallet_id' => $wallet->id,
            'type' => $type,
            'amount_minor' => $amountMinor,
            'balance_after_minor' => $balanceAfter,
            'currency' => $wallet->currency,
            'reference_type' => $reference === null ? null : $reference::class,
            'reference_id' => $reference?->getKey(),
            'idempotency_key' => $idempotencyKey,
            'description' => $description,
            'metadata' => $metadata === [] ? null : $metadata,
            'created_by' => $actor?->id,
        ]);

        $this->setBalance($wallet, $balanceAfter);

        return $transaction;
    }

    /**
     * Record which credit lots produced an issuance.
     *
     * Every line, including the ones worth nothing. A breakdown that dropped
     * the free lots could not explain why 45 consumed credits produced the
     * value of 30.
     */
    private function recordCreditSources(StoreWalletTransaction $transaction, CreditValuation $valuation): void
    {
        foreach ($valuation->lines as $line) {
            /** @var LotValuation $line */
            StoreWalletCreditSource::create([
                'store_wallet_transaction_id' => $transaction->id,
                'credit_lot_id' => $line->lotId,
                'source_type' => $line->source,
                'credits' => $line->credits,
                'lot_acquisition_amount_minor' => $line->lotAcquisitionMinor,
                'lot_original_amount' => $line->lotOriginalAmount,
                'amount_minor' => $line->value->minor,
                'remainder_numerator' => $line->remainderNumerator,
                'currency' => $transaction->currency,
            ]);
        }
    }

    /**
     * Write the materialized balance.
     *
     * Guarded so this cannot happen anywhere but here, and always alongside
     * the ledger row that accounts for it.
     */
    private function setBalance(StoreWallet $wallet, int $balanceMinor): void
    {
        StoreWallet::permittingBalanceWrites(function () use ($wallet, $balanceMinor): void {
            $wallet->balance_minor = $balanceMinor;
            $wallet->save();
        });
    }
}
