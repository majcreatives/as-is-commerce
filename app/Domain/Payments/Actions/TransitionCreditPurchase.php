<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Exceptions\PaymentVerificationFailed;
use App\Enums\CreditPurchaseStatus;
use App\Models\CreditPurchase;
use App\Models\CreditPurchaseTransition;
use App\Models\User;

/**
 * Moves a purchase from one state to the next, and records that it happened.
 *
 * Every state change goes through here so two things stay true: only legal
 * transitions occur, and each one leaves evidence. A payment state machine
 * that permits arbitrary moves is how a purchase ends up marked complete
 * without credits, or fulfilled twice.
 */
final class TransitionCreditPurchase
{
    public function handle(
        CreditPurchase $purchase,
        CreditPurchaseStatus $to,
        ?string $reason = null,
        ?User $actor = null,
    ): CreditPurchase {
        $from = $purchase->status;

        if ($from === $to) {
            return $purchase;
        }

        if (! $from->canTransitionTo($to)) {
            throw PaymentVerificationFailed::because(
                "A purchase cannot move from {$from->label()} to {$to->label()}."
            );
        }

        $purchase->status = $to;

        if ($to === CreditPurchaseStatus::Paid && $purchase->paid_at === null) {
            $purchase->paid_at = now();
        }

        if ($to === CreditPurchaseStatus::Fulfilled) {
            $purchase->fulfilled_at = now();
        }

        if ($to === CreditPurchaseStatus::Failed && $reason !== null) {
            $purchase->failure_reason = $reason;
        }

        $purchase->save();

        CreditPurchaseTransition::create([
            'credit_purchase_id' => $purchase->id,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'caused_by' => $actor?->id,
        ]);

        return $purchase;
    }
}
