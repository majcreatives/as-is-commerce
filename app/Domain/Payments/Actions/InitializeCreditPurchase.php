<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Exceptions\PaymentVerificationFailed;
use App\Domain\Payments\ValueObjects\InitializedTransaction;
use App\Enums\CreditPurchaseStatus;
use App\Enums\PaymentProvider;
use App\Models\CreditPackage;
use App\Models\CreditPurchase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Opens a credit purchase and hands the customer somewhere to pay.
 *
 * The amount comes from the package record read here on the server, and is
 * frozen onto the purchase before the provider is contacted. Nothing the
 * browser sends influences it -- a request carries a package, never a price,
 * and never a credit quantity.
 *
 * The purchase is created before the provider call so that a transaction which
 * succeeds at Paystack but fails on the way back to us still has a row to be
 * reconciled against. The reverse order would lose the payment entirely.
 */
final class InitializeCreditPurchase
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly TransitionCreditPurchase $transition,
    ) {}

    /**
     * @return array{purchase: CreditPurchase, transaction: InitializedTransaction}
     */
    public function handle(User $user, CreditPackage $package): array
    {
        if (! $package->is_active) {
            throw PaymentVerificationFailed::because('That credit package is not available for purchase.');
        }

        $purchase = DB::transaction(function () use ($user, $package): CreditPurchase {
            $purchase = new CreditPurchase([
                'user_id' => $user->id,
                'credit_package_id' => $package->id,

                // The snapshot. Everything fulfilment will need, fixed now, so
                // a later repricing cannot change what this purchase grants.
                'package_name_snapshot' => $package->name,
                'credit_amount' => $package->credit_amount,
                'amount_minor' => $package->price_minor,
                'currency' => $package->currency,
            ]);

            $purchase->status = CreditPurchaseStatus::Pending;
            $purchase->payment_provider = PaymentProvider::Paystack;
            $purchase->provider_reference = $this->generateReference();
            $purchase->idempotency_key = Str::uuid()->toString();
            $purchase->save();

            return $purchase;
        });

        $transaction = $this->gateway->initializeTransaction(
            user: $user,
            // From the snapshot, not the package, so the two can never diverge.
            amount: $purchase->amount(),
            reference: $purchase->provider_reference,
            callbackUrl: route(config('paystack.callback_route')),
            metadata: [
                'credit_purchase_id' => $purchase->id,
                'user_id' => $user->id,
                'package' => $purchase->package_name_snapshot,
                'credits' => $purchase->credit_amount,
            ],
        );

        $this->transition->handle(
            $purchase,
            CreditPurchaseStatus::PaymentProcessing,
            'Handed off to the payment provider.',
            $user,
        );

        Log::info('Credit purchase initialized', [
            'operation' => 'credit_purchase.initialize',
            'purchase_id' => $purchase->id,
            'user_id' => $user->id,
            'package' => $purchase->package_name_snapshot,
            'credits' => $purchase->credit_amount,
            'amount_minor' => $purchase->amount_minor,
            'currency' => $purchase->currency,
            'reference' => $purchase->provider_reference,
        ]);

        return ['purchase' => $purchase->fresh(), 'transaction' => $transaction];
    }

    /**
     * A reference that is unique, unguessable and carries nothing sensitive.
     *
     * Random rather than sequential: the reference travels to a third party
     * and appears in URLs, so a predictable one would let anyone enumerate
     * other customers' payments.
     */
    private function generateReference(): string
    {
        return 'AIC-'.now()->format('Ymd').'-'.strtoupper(Str::random(18));
    }
}
