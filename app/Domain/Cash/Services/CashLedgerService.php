<?php

declare(strict_types=1);

namespace App\Domain\Cash\Services;

use App\Domain\Cash\Exceptions\InsufficientFunds;
use App\Domain\Credit\Exceptions\InvalidLedgerOperation;
use App\Domain\Shared\Money\Money;
use App\Enums\CashTransactionType;
use App\Models\CashTransaction;
use App\Models\CashWallet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only sanctioned way to move real money.
 *
 * Structurally parallel to the credit ledger and deliberately separate from
 * it. Cash has no lots and no expiry -- money does not lapse -- so the model
 * is simpler, but the same rules hold: the ledger is authoritative, rows are
 * append-only, the balance is materialized inside the same transaction, and
 * amounts are integer minor units that never touch a float.
 *
 * A credit purchase will eventually produce two entries in two ledgers: cash
 * out, credits in. That workflow belongs to the payments stage; this class
 * only makes it representable.
 *
 * LOCKING: the cash_wallets row, SELECT ... FOR UPDATE, before the balance is
 * read. Same reasoning as the credit ledger -- it is what stops two
 * simultaneous withdrawals both seeing the same starting balance.
 */
class CashLedgerService
{
    public function walletFor(User $user, string $currency = 'GHS'): CashWallet
    {
        return CashWallet::firstOrCreate(
            ['user_id' => $user->id],
            ['currency' => strtoupper($currency)],
        );
    }

    /**
     * Add money to a wallet.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function credit(
        CashWallet $wallet,
        CashTransactionType $type,
        Money $amount,
        ?Model $reference = null,
        ?string $description = null,
        array $metadata = [],
        ?User $actor = null,
        ?string $idempotencyKey = null,
    ): CashTransaction {
        if (! $amount->isPositive()) {
            throw InvalidLedgerOperation::because('A cash credit must be a positive amount.');
        }

        return $this->post($wallet, $type, $amount->minor, $reference, $description, $metadata, $actor, $idempotencyKey);
    }

    /**
     * Remove money from a wallet.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function debit(
        CashWallet $wallet,
        CashTransactionType $type,
        Money $amount,
        ?Model $reference = null,
        ?string $description = null,
        array $metadata = [],
        ?User $actor = null,
        ?string $idempotencyKey = null,
    ): CashTransaction {
        if (! $amount->isPositive()) {
            throw InvalidLedgerOperation::because('A cash debit must be given a positive amount to remove.');
        }

        return $this->post($wallet, $type, -$amount->minor, $reference, $description, $metadata, $actor, $idempotencyKey);
    }

    /**
     * Reverse an earlier transaction with a compensating entry.
     *
     * The original is never modified.
     */
    public function reverse(
        CashTransaction $original,
        string $reason,
        ?User $actor = null,
        ?string $idempotencyKey = null,
    ): CashTransaction {
        return $this->post(
            wallet: $original->wallet,
            type: CashTransactionType::Reversal,
            amountMinor: -$original->amount_minor,
            reference: $original,
            description: $reason,
            metadata: ['reversed_transaction_id' => $original->id],
            actor: $actor,
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function post(
        CashWallet $wallet,
        CashTransactionType $type,
        int $amountMinor,
        ?Model $reference,
        ?string $description,
        array $metadata,
        ?User $actor,
        ?string $idempotencyKey,
    ): CashTransaction {
        if (! $type->allowsAmount($amountMinor)) {
            throw InvalidLedgerOperation::because(
                "Cash transaction type [{$type->value}] does not permit an amount of {$amountMinor}."
            );
        }

        return DB::transaction(function () use (
            $wallet, $type, $amountMinor, $reference, $description, $metadata, $actor, $idempotencyKey
        ): CashTransaction {
            $locked = CashWallet::whereKey($wallet->getKey())->lockForUpdate()->firstOrFail();

            $balanceAfter = $locked->balance_minor + $amountMinor;

            if ($balanceAfter < 0) {
                throw new InsufficientFunds(
                    requested: abs($amountMinor),
                    available: $locked->balance_minor,
                    currency: $locked->currency,
                );
            }

            $transaction = CashTransaction::create([
                'cash_wallet_id' => $locked->id,
                'type' => $type,
                'amount_minor' => $amountMinor,
                'balance_after_minor' => $balanceAfter,
                'currency' => $locked->currency,
                'reference_type' => $reference === null ? null : $reference::class,
                'reference_id' => $reference?->getKey(),
                'idempotency_key' => $idempotencyKey,
                'description' => $description,
                'metadata' => $metadata === [] ? null : $metadata,
                'created_by' => $actor?->id,
            ]);

            CashWallet::permittingBalanceWrites(function () use ($locked, $balanceAfter): void {
                $locked->balance_minor = $balanceAfter;
                $locked->save();
            });

            Log::info('Cash transaction posted', [
                'operation' => 'cash.post',
                'wallet_id' => $locked->id,
                'transaction_id' => $transaction->id,
                'type' => $type->value,
                'amount_minor' => $amountMinor,
                'balance_after_minor' => $balanceAfter,
                'currency' => $locked->currency,
                'idempotency_key' => $idempotencyKey,
            ]);

            return $transaction;
        });
    }
}
