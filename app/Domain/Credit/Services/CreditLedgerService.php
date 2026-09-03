<?php

declare(strict_types=1);

namespace App\Domain\Credit\Services;

use App\Domain\Credit\Exceptions\InsufficientCredits;
use App\Domain\Credit\Exceptions\InvalidLedgerOperation;
use App\Domain\Credit\ValueObjects\CreditAllocation;
use App\Enums\CreditLotSource;
use App\Enums\CreditTransactionType;
use App\Models\CreditLot;
use App\Models\CreditLotConsumption;
use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only sanctioned way to move bidding credits.
 *
 * Every method here writes the ledger row, the lot changes and the
 * materialized balance inside a single transaction. There is no path that
 * updates one without the others, so the wallet cannot be left describing a
 * state the ledger does not justify.
 *
 * LOCKING ORDER -- observe this everywhere:
 *
 *     1. the credit_wallets row, SELECT ... FOR UPDATE
 *     2. that wallet's credit_lots rows, SELECT ... FOR UPDATE ORDER BY id
 *
 * Always the wallet first, and always lots in ascending id order. Two
 * concurrent operations therefore request the same resources in the same
 * sequence and queue rather than deadlock. Note that lots are *locked* in id
 * order but *consumed* in business order -- the allocator reorders them after
 * the locks are held, which is why those two orders are allowed to differ.
 *
 * Because the wallet row is locked before its balance is read, two
 * simultaneous debits cannot both see the same starting balance: the second
 * blocks until the first commits and then reads the balance the first left
 * behind. That is what makes overspending impossible rather than unlikely.
 */
class CreditLedgerService
{
    public function __construct(
        private readonly CreditAllocationService $allocator,
    ) {}

    /**
     * Ensure a user has a credit wallet, creating one if not.
     *
     * A new wallet starts at zero, which is a real balance rather than a
     * placeholder.
     */
    public function walletFor(User $user): CreditWallet
    {
        return CreditWallet::firstOrCreate(['user_id' => $user->id]);
    }

    /**
     * Add credits to a wallet, creating the lot they live in.
     *
     * @param  int  $amount  Positive quantity of credits to add.
     * @param  array<string, mixed>  $metadata
     */
    public function addCredits(
        CreditWallet $wallet,
        CreditTransactionType $type,
        int $amount,
        ?DateTimeInterface $expiresAt = null,
        ?Model $reference = null,
        ?string $description = null,
        array $metadata = [],
        ?User $actor = null,
        ?string $idempotencyKey = null,
    ): CreditTransaction {
        if ($amount <= 0) {
            throw InvalidLedgerOperation::because('Credits added must be a positive amount.');
        }

        if (! $type->createsLot()) {
            throw InvalidLedgerOperation::because(
                "Transaction type [{$type->value}] does not add credits."
            );
        }

        return DB::transaction(function () use (
            $wallet, $type, $amount, $expiresAt, $reference, $description, $metadata, $actor, $idempotencyKey
        ): CreditTransaction {
            $locked = $this->lockWallet($wallet);

            $balanceAfter = $locked->balance + $amount;

            $transaction = $this->writeTransaction(
                wallet: $locked,
                type: $type,
                amount: $amount,
                balanceAfter: $balanceAfter,
                reference: $reference,
                description: $description,
                metadata: $metadata,
                actor: $actor,
                idempotencyKey: $idempotencyKey,
            );

            CreditLot::create([
                'credit_wallet_id' => $locked->id,
                'credit_transaction_id' => $transaction->id,
                'source_type' => $type->lotSource() ?? CreditLotSource::Adjustment,
                'original_amount' => $amount,
                'remaining_amount' => $amount,
                'expires_at' => $expiresAt,
            ]);

            $this->setBalance($locked, $balanceAfter);

            Log::info('Credits added', [
                'operation' => 'credit.add',
                'wallet_id' => $locked->id,
                'transaction_id' => $transaction->id,
                'type' => $type->value,
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'idempotency_key' => $idempotencyKey,
            ]);

            return $transaction;
        });
    }

    /**
     * Consume credits from a wallet, drawing from lots in allocation order.
     *
     * This is the method the auction stage will call to charge for a bid. It
     * is deliberately not wired to anything yet.
     *
     * @param  int  $amount  Positive quantity of credits to consume.
     * @param  array<string, mixed>  $metadata
     *
     * @throws InsufficientCredits
     */
    public function consumeCredits(
        CreditWallet $wallet,
        int $amount,
        CreditTransactionType $type = CreditTransactionType::BidDebit,
        ?Model $reference = null,
        ?string $description = null,
        array $metadata = [],
        ?User $actor = null,
        ?string $idempotencyKey = null,
    ): CreditTransaction {
        if ($amount <= 0) {
            throw InvalidLedgerOperation::because('Credits consumed must be a positive amount.');
        }

        return DB::transaction(function () use (
            $wallet, $amount, $type, $reference, $description, $metadata, $actor, $idempotencyKey
        ): CreditTransaction {
            $locked = $this->lockWallet($wallet);
            $lots = $this->lockSpendableLots($locked);

            // Recomputed from the locked lots rather than trusted from the
            // balance column, so an allocation can never be planned against a
            // stale or drifted figure.
            $available = $this->allocator->available($lots);

            $allocations = $this->allocator->allocate($lots, $amount);

            if ($allocations->isEmpty()) {
                throw new InsufficientCredits(requested: $amount, available: $available);
            }

            $balanceAfter = $locked->balance - $amount;

            if ($balanceAfter < 0) {
                // Would mean the ledger and the lots disagree. Refuse rather
                // than write a negative balance.
                throw new InsufficientCredits(requested: $amount, available: $locked->balance);
            }

            $transaction = $this->writeTransaction(
                wallet: $locked,
                type: $type,
                amount: -$amount,
                balanceAfter: $balanceAfter,
                reference: $reference,
                description: $description,
                metadata: $metadata,
                actor: $actor,
                idempotencyKey: $idempotencyKey,
            );

            $this->applyAllocations($allocations, $transaction);

            $this->setBalance($locked, $balanceAfter);

            Log::info('Credits consumed', [
                'operation' => 'credit.consume',
                'wallet_id' => $locked->id,
                'transaction_id' => $transaction->id,
                'type' => $type->value,
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'lots_used' => $allocations->map(
                    fn ($a): array => ['lot_id' => $a->lot->id, 'amount' => $a->amount]
                )->all(),
                'idempotency_key' => $idempotencyKey,
            ]);

            return $transaction;
        });
    }

    /**
     * Reverse a credit-adding transaction by clawing back what remains of the
     * lot it created.
     *
     * The original row is never touched. A REVERSAL is posted alongside it, so
     * both the mistake and the correction stay on record.
     *
     * If the credits have since been spent they cannot be clawed back, and
     * this fails rather than silently reversing less than asked. Recovering
     * spent credits is a business decision, not something to infer here.
     */
    public function reverse(
        CreditTransaction $original,
        string $reason,
        ?User $actor = null,
        ?string $idempotencyKey = null,
    ): CreditTransaction {
        if ($original->amount <= 0) {
            throw InvalidLedgerOperation::because(
                'Only credit-adding transactions can be reversed by this method.'
            );
        }

        return DB::transaction(function () use ($original, $reason, $actor, $idempotencyKey): CreditTransaction {
            $wallet = $this->lockWallet($original->wallet);

            $lot = CreditLot::where('credit_transaction_id', $original->id)
                ->lockForUpdate()
                ->first();

            if ($lot === null) {
                throw InvalidLedgerOperation::because(
                    "Transaction #{$original->id} created no lot, so it cannot be reversed this way."
                );
            }

            $amount = $original->amount;

            if ($lot->remaining_amount < $amount) {
                $spent = $amount - $lot->remaining_amount;

                throw InvalidLedgerOperation::because(
                    "Cannot reverse transaction #{$original->id}: {$spent} of its {$amount} credits "
                    .'have already been spent.'
                );
            }

            $balanceAfter = $wallet->balance - $amount;

            if ($balanceAfter < 0) {
                throw new InsufficientCredits(requested: $amount, available: $wallet->balance);
            }

            $reversal = $this->writeTransaction(
                wallet: $wallet,
                type: CreditTransactionType::Reversal,
                amount: -$amount,
                balanceAfter: $balanceAfter,
                reference: $original,
                description: $reason,
                metadata: ['reversed_transaction_id' => $original->id],
                actor: $actor,
                idempotencyKey: $idempotencyKey,
            );

            $this->applyAllocations(
                collect([new CreditAllocation($lot, $amount)]),
                $reversal,
            );

            $this->setBalance($wallet, $balanceAfter);

            Log::info('Credit transaction reversed', [
                'operation' => 'credit.reverse',
                'wallet_id' => $wallet->id,
                'original_transaction_id' => $original->id,
                'reversal_transaction_id' => $reversal->id,
                'amount' => $amount,
            ]);

            return $reversal;
        });
    }

    /**
     * Write off the unspent remainder of lots that have passed their expiry.
     *
     * The automated worker that calls this on a schedule belongs to a later
     * stage. The operation itself is written to be safe for that worker:
     * transactional, and naturally idempotent because an expired lot's
     * remainder reaches zero the first time and is skipped thereafter.
     */
    public function expireLots(CreditWallet $wallet, ?User $actor = null): ?CreditTransaction
    {
        return DB::transaction(function () use ($wallet, $actor): ?CreditTransaction {
            $locked = $this->lockWallet($wallet);

            /** @var Collection<int, CreditLot> $expired */
            $expired = CreditLot::where('credit_wallet_id', $locked->id)
                ->expiredWithRemainder()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($expired->isEmpty()) {
                return null;
            }

            $amount = (int) $expired->sum('remaining_amount');
            $balanceAfter = max(0, $locked->balance - $amount);

            $transaction = $this->writeTransaction(
                wallet: $locked,
                type: CreditTransactionType::Expiration,
                amount: -$amount,
                balanceAfter: $balanceAfter,
                reference: null,
                description: 'Unused credits reached their expiry date.',
                metadata: ['expired_lot_ids' => $expired->pluck('id')->all()],
                actor: $actor,
                idempotencyKey: null,
            );

            $allocations = $expired->map(
                fn (CreditLot $lot) => new CreditAllocation(
                    $lot,
                    $lot->remaining_amount,
                )
            );

            $this->applyAllocations($allocations, $transaction);

            $this->setBalance($locked, $balanceAfter);

            Log::info('Credits expired', [
                'operation' => 'credit.expire',
                'wallet_id' => $locked->id,
                'transaction_id' => $transaction->id,
                'amount' => $amount,
            ]);

            return $transaction;
        });
    }

    // ------------------------------------------------------------ Internals

    /**
     * Step 1 of the locking order: the wallet row.
     *
     * Returns a freshly read, locked instance. Callers must use the returned
     * model rather than the one passed in, whose attributes may be stale.
     */
    private function lockWallet(CreditWallet $wallet): CreditWallet
    {
        return CreditWallet::whereKey($wallet->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Step 2 of the locking order: the wallet's spendable lots, by id.
     *
     * The id ordering is what keeps concurrent operations from deadlocking.
     * Business ordering is applied afterwards, by the allocator.
     *
     * @return Collection<int, CreditLot>
     */
    private function lockSpendableLots(CreditWallet $wallet): Collection
    {
        return CreditLot::where('credit_wallet_id', $wallet->getKey())
            ->spendable()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function writeTransaction(
        CreditWallet $wallet,
        CreditTransactionType $type,
        int $amount,
        int $balanceAfter,
        ?Model $reference,
        ?string $description,
        array $metadata,
        ?User $actor,
        ?string $idempotencyKey,
    ): CreditTransaction {
        if (! $type->allowsAmount($amount)) {
            throw InvalidLedgerOperation::because(
                "Transaction type [{$type->value}] does not permit an amount of {$amount}."
            );
        }

        if ($balanceAfter < 0) {
            throw InvalidLedgerOperation::because('A wallet balance may never go negative.');
        }

        return CreditTransaction::create([
            'credit_wallet_id' => $wallet->id,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'reference_type' => $reference === null ? null : $reference::class,
            'reference_id' => $reference?->getKey(),
            'idempotency_key' => $idempotencyKey,
            'description' => $description,
            'metadata' => $metadata === [] ? null : $metadata,
            'created_by' => $actor?->id,
        ]);
    }

    /**
     * Apply a plan: record each draw and reduce the lot it came from.
     *
     * @param  Collection<int, CreditAllocation>  $allocations
     */
    private function applyAllocations(Collection $allocations, CreditTransaction $transaction): void
    {
        foreach ($allocations as $allocation) {
            CreditLotConsumption::create([
                'credit_lot_id' => $allocation->lot->id,
                'credit_transaction_id' => $transaction->id,
                'amount' => $allocation->amount,
            ]);

            $lot = $allocation->lot;
            $lot->remaining_amount -= $allocation->amount;

            if ($lot->remaining_amount === 0) {
                $lot->exhausted_at = now();
            }

            $lot->save();
        }
    }

    /**
     * Write the materialized balance.
     *
     * Guarded so this cannot happen anywhere but here, and always alongside
     * the ledger row that accounts for it.
     */
    private function setBalance(CreditWallet $wallet, int $balance): void
    {
        CreditWallet::permittingBalanceWrites(function () use ($wallet, $balance): void {
            $wallet->balance = $balance;
            $wallet->save();
        });
    }
}
