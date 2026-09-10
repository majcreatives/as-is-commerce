<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Cash\Services\CashLedgerService;
use App\Domain\Credit\Services\CreditLedgerService;
use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Exceptions\PaymentVerificationFailed;
use App\Domain\Payments\ValueObjects\VerifiedTransaction;
use App\Domain\Shared\Idempotency\IdempotencyGuard;
use App\Enums\CashTransactionType;
use App\Enums\CreditPurchaseStatus;
use App\Enums\CreditTransactionType;
use App\Models\CreditPurchase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a verified payment into credits.
 *
 * This is the only path by which a purchase grants credits, and both the
 * webhook and the browser callback go through it. That is deliberate: sharing
 * one path means the callback cannot become a weaker way in, and a customer
 * refreshing the return page cannot produce a second grant.
 *
 * The order of operations matters:
 *
 *   1. Verify server-to-server with the provider. A webhook body is not
 *      evidence -- it says what someone sent us, not what was paid.
 *   2. Check the provider's answer against our own immutable snapshot:
 *      status, reference, currency, amount. Any mismatch is a refusal.
 *   3. Run the financial effect under the idempotency guard, keyed on the
 *      purchase, so five deliveries of the same event produce one grant.
 *   4. Inside one transaction: lock the purchase row, re-check it is not
 *      already fulfilled, post cash, post credits, mark fulfilled.
 *
 * A purchase is marked FULFILLED only after the credits exist. If posting
 * fails the whole transaction rolls back, the purchase stays PAID, and it
 * remains visibly outstanding rather than looking complete -- which is what
 * allows a retry to put it right.
 */
final class FulfillCreditPurchase
{
    public const OPERATION = 'credit_purchase.fulfil';

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly CreditLedgerService $credits,
        private readonly CashLedgerService $cash,
        private readonly IdempotencyGuard $idempotency,
        private readonly TransitionCreditPurchase $transition,
    ) {}

    /**
     * Verify a purchase with the provider and, if it holds up, grant credits.
     *
     * @return array{purchase_id: int, credit_transaction_id: int|null, already_fulfilled: bool}
     */
    public function handle(CreditPurchase $purchase): array
    {
        // Already done. Returning rather than throwing means a customer
        // refreshing the return page sees success, not an error.
        if ($purchase->isFulfilled()) {
            return [
                'purchase_id' => $purchase->id,
                'credit_transaction_id' => $purchase->creditTransactions()->value('id'),
                'already_fulfilled' => true,
            ];
        }

        $verified = $this->gateway->verifyTransaction($purchase->provider_reference);

        $this->assertMatchesPurchase($purchase, $verified);

        return $this->idempotency->execute(
            operation: self::OPERATION,
            key: $purchase->idempotency_key,
            userId: $purchase->user_id,
            work: fn (): array => $this->grant($purchase, $verified),
        );
    }

    /**
     * Every check that stands between a payment and a grant.
     *
     * A failure here is never "close enough". A mismatch means either a bug or
     * an attempt to manipulate a payment, and both are reasons to stop.
     */
    private function assertMatchesPurchase(CreditPurchase $purchase, VerifiedTransaction $verified): void
    {
        if (! $verified->isSuccessful()) {
            throw PaymentVerificationFailed::notSuccessful($verified->status);
        }

        if (! hash_equals($purchase->provider_reference, $verified->reference)) {
            throw PaymentVerificationFailed::referenceMismatch(
                $purchase->provider_reference,
                $verified->reference,
            );
        }

        if (strtoupper($verified->currency) !== strtoupper($purchase->currency)) {
            throw PaymentVerificationFailed::currencyMismatch($purchase->currency, $verified->currency);
        }

        // Compared against the snapshot, not the package. If a package was
        // repriced after this purchase was opened, the snapshot is what the
        // customer agreed to and what the provider was asked for.
        if ($verified->amountMinor !== $purchase->amount_minor) {
            throw PaymentVerificationFailed::amountMismatch($purchase->amount_minor, $verified->amountMinor);
        }
    }

    /**
     * @return array{purchase_id: int, credit_transaction_id: int|null, already_fulfilled: bool}
     */
    private function grant(CreditPurchase $purchase, VerifiedTransaction $verified): array
    {
        return DB::transaction(function () use ($purchase, $verified): array {
            // Locked so two deliveries arriving together cannot both pass the
            // fulfilled check. The guard already serializes on the key; this
            // closes the window inside the transaction as well.
            $locked = CreditPurchase::whereKey($purchase->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isFulfilled()) {
                return [
                    'purchase_id' => $locked->id,
                    'credit_transaction_id' => $locked->creditTransactions()->value('id'),
                    'already_fulfilled' => true,
                ];
            }

            $locked->provider_transaction_id = $verified->providerTransactionId;
            $locked->provider_channel = $verified->channel;
            $locked->save();

            if ($locked->status !== CreditPurchaseStatus::Paid) {
                $this->transition->handle(
                    $locked,
                    CreditPurchaseStatus::Paid,
                    'Payment verified with the provider.',
                );
            }

            $user = $locked->user;

            // ---- Real money -----------------------------------------------
            //
            // Two entries rather than one. The money arrived and immediately
            // bought credits, and recording both is more truthful than a bare
            // debit -- which could not be written anyway, since a cash wallet
            // may not go negative. The pair nets to zero, which is correct:
            // the customer holds credits now, not a cash balance.
            $this->cash->credit(
                wallet: $this->cash->walletFor($user, $locked->currency),
                type: CashTransactionType::Deposit,
                amount: $locked->amount(),
                reference: $locked,
                description: "Payment received for {$locked->package_name_snapshot}.",
                metadata: ['provider' => $locked->payment_provider->value],
                idempotencyKey: $locked->idempotency_key.':cash-in',
            );

            $this->cash->debit(
                wallet: $this->cash->walletFor($user, $locked->currency)->fresh(),
                type: CashTransactionType::CreditPurchase,
                amount: $locked->amount(),
                reference: $locked,
                description: "Purchase of {$locked->credit_amount} credits.",
                metadata: ['credits' => $locked->credit_amount],
                idempotencyKey: $locked->idempotency_key.':cash-out',
            );

            // ---- Credits ---------------------------------------------------
            //
            // PURCHASE, not promotional: these were paid for. And no expiry --
            // the ledger stage established that purchased credits do not
            // expire by default, and that policy is not changed here.
            $creditTransaction = $this->credits->addCredits(
                wallet: $this->credits->walletFor($user),
                type: CreditTransactionType::Purchase,
                amount: $locked->credit_amount,
                expiresAt: null,
                reference: $locked,
                description: "{$locked->package_name_snapshot} ({$locked->credit_amount} credits).",
                metadata: [
                    'credit_purchase_id' => $locked->id,
                    'provider_reference' => $locked->provider_reference,
                ],
                idempotencyKey: $locked->idempotency_key.':credits',
                acquisitionAmountMinor: $locked->amount_minor,
                acquisitionCurrency: $locked->currency,
            );

            // Only now, with the credits actually posted.
            $this->transition->handle(
                $locked,
                CreditPurchaseStatus::Fulfilled,
                "Posted {$locked->credit_amount} credits to the wallet.",
            );

            activity('credit_purchase')
                ->performedOn($locked)
                ->causedBy($user)
                ->withProperties([
                    'credits' => $locked->credit_amount,
                    'amount_minor' => $locked->amount_minor,
                    'currency' => $locked->currency,
                    'provider_reference' => $locked->provider_reference,
                    'credit_transaction_id' => $creditTransaction->id,
                ])
                ->log('credit_purchase_fulfilled');

            Log::info('Credit purchase fulfilled', [
                'operation' => self::OPERATION,
                'purchase_id' => $locked->id,
                'user_id' => $locked->user_id,
                'credits' => $locked->credit_amount,
                'amount_minor' => $locked->amount_minor,
                'currency' => $locked->currency,
                'reference' => $locked->provider_reference,
                'credit_transaction_id' => $creditTransaction->id,
            ]);

            return [
                'purchase_id' => $locked->id,
                'credit_transaction_id' => $creditTransaction->id,
                'already_fulfilled' => false,
            ];
        });
    }
}
