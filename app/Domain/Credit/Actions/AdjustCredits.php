<?php

declare(strict_types=1);

namespace App\Domain\Credit\Actions;

use App\Domain\Credit\Exceptions\InvalidLedgerOperation;
use App\Domain\Credit\Services\CreditLedgerService;
use App\Domain\Shared\Idempotency\IdempotencyGuard;
use App\Enums\CreditLotSource;
use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * An administrator adds or removes a user's credits.
 *
 * The only route by which staff can change a balance, and deliberately a
 * narrow one:
 *
 *   - a reason is mandatory, because an unexplained balance change is
 *     indistinguishable from fraud when someone reviews it months later;
 *   - the administrator is recorded as the actor on the ledger row and in the
 *     audit log;
 *   - the operation is idempotent, so a double-submitted form grants credits
 *     once rather than twice;
 *   - it goes through the ledger service like everything else, so no balance
 *     moves without a transaction accounting for it.
 *
 * There is no "set balance to X" operation anywhere in this system, and there
 * should never be one.
 */
final class AdjustCredits
{
    public const OPERATION = 'credit.adjust';

    public function __construct(
        private readonly CreditLedgerService $ledger,
        private readonly IdempotencyGuard $idempotency,
    ) {}

    /**
     * @param  int  $amount  Positive to grant credits, negative to remove them.
     * @param  string  $reason  Mandatory, stored on the ledger row and the audit entry.
     */
    public function handle(
        User $user,
        int $amount,
        string $reason,
        User $actor,
        ?CreditLotSource $source = null,
        ?DateTimeInterface $expiresAt = null,
        ?string $idempotencyKey = null,
    ): CreditTransaction {
        if ($amount === 0) {
            throw InvalidLedgerOperation::because('An adjustment must add or remove a non-zero number of credits.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw InvalidLedgerOperation::because('An adjustment requires a reason.');
        }

        $key = $idempotencyKey ?? Str::uuid()->toString();

        $result = $this->idempotency->execute(
            operation: self::OPERATION,
            key: $key,
            userId: $actor->id,
            work: fn (): array => $this->apply($user, $amount, $reason, $actor, $source, $expiresAt, $key),
        );

        return CreditTransaction::findOrFail($result['transaction_id']);
    }

    /**
     * @return array{transaction_id: int, balance_after: int}
     */
    private function apply(
        User $user,
        int $amount,
        string $reason,
        User $actor,
        ?CreditLotSource $source,
        ?DateTimeInterface $expiresAt,
        string $key,
    ): array {
        $wallet = $this->ledger->walletFor($user);

        $metadata = ['adjusted_by' => $actor->id, 'reason' => $reason];

        $transaction = $amount > 0
            ? $this->ledger->addCredits(
                wallet: $wallet,
                type: $this->creditTypeFor($source),
                amount: $amount,
                expiresAt: $expiresAt,
                reference: null,
                description: $reason,
                metadata: $metadata,
                actor: $actor,
                idempotencyKey: $key,
            )
            // Removing credits is posted as a Reversal, the ledger's sanctioned
            // type for taking credits back. It draws from lots in the normal
            // allocation order, so the record still shows exactly which
            // credits were removed.
            : $this->ledger->consumeCredits(
                wallet: $wallet,
                amount: abs($amount),
                type: CreditTransactionType::Reversal,
                reference: null,
                description: $reason,
                metadata: $metadata,
                actor: $actor,
                idempotencyKey: $key,
            );

        activity('credit')
            ->performedOn($transaction)
            ->causedBy($actor)
            ->withProperties([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'amount' => $amount,
                'reason' => $reason,
                'balance_after' => $transaction->balance_after,
            ])
            ->log('credit_adjusted');

        Log::info('Credit adjustment applied', [
            'operation' => self::OPERATION,
            'admin_id' => $actor->id,
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'transaction_id' => $transaction->id,
            'amount' => $amount,
            'idempotency_key' => $key,
        ]);

        return [
            'transaction_id' => $transaction->id,
            'balance_after' => $transaction->balance_after,
        ];
    }

    /**
     * Which credit type a positive adjustment should be recorded as.
     *
     * Choosing the type here means the lot it creates carries the right
     * source, so promotional grants stay distinguishable from purchased
     * credits for expiry and refund purposes.
     */
    private function creditTypeFor(?CreditLotSource $source): CreditTransactionType
    {
        return match ($source) {
            CreditLotSource::Promotional => CreditTransactionType::PromotionalCredit,
            CreditLotSource::Referral => CreditTransactionType::ReferralCredit,
            default => CreditTransactionType::AdjustmentCredit,
        };
    }
}
