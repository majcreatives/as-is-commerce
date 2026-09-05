<?php

declare(strict_types=1);

namespace App\Domain\Refunds\Services;

use App\Domain\Shared\Money\Money;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Refund;

/**
 * How much of a payment may still be returned.
 *
 * THE BROWSER NEVER SUPPLIES AN AMOUNT. Every figure here is read from the
 * payment's own frozen amount and from the refund rows against it, both
 * server-side. A request names an order and a reason; what it is worth is
 * decided here.
 *
 * TWO FIGURES, AND THE DIFFERENCE MATTERS:
 *
 *   refunded    what has provably gone back -- succeeded refunds only. This
 *               is what a customer and an administrator are shown, because it
 *               is the only figure that is true.
 *
 *   refundable  what a new refund may be for. Succeeded refunds subtracted,
 *               and in-flight ones too.
 *
 * IN-FLIGHT REFUNDS COUNT AGAINST THE CAP, and that is the whole defence
 * against over-refunding. Subtracting only succeeded refunds would let two
 * GH₵70 attempts exist at once against a GH₵100 payment on the theory that
 * only one of them will settle -- and when both settled the platform would
 * have returned GH₵140 it never received. A failed attempt releases its share
 * again, because it returned nothing.
 *
 * All arithmetic is integer minor units in {@see Money}. No float touches a
 * refund at any point.
 */
class RefundCalculator
{
    /**
     * What may still be refunded against this payment.
     *
     * Zero for a payment that never succeeded: there is nothing to give back,
     * and a refund against it would be returning money the platform never
     * received.
     *
     * Callers deciding whether to allow a refund must hold the order row lock
     * first. Reading this without one lets two administrators measure
     * themselves against the same figure and both clear a cap only one of
     * them actually cleared.
     */
    public function refundable(OrderPayment $payment): Money
    {
        if (! $payment->isSuccessful()) {
            return Money::zero($payment->currency);
        }

        $spokenFor = $this->sum($payment, [
            RefundStatus::Succeeded,
            RefundStatus::Pending,
            RefundStatus::Processing,
        ]);

        $remaining = $payment->amount_minor - $spokenFor;

        // Clamped rather than allowed to go negative. A negative refundable
        // amount would mean the ledger has already been over-refunded, which
        // is a reconciliation anomaly to be reported by a person -- not
        // something to express as a number here.
        return Money::fromMinor(max(0, $remaining), $payment->currency);
    }

    /**
     * What has provably gone back against this payment.
     *
     * Succeeded refunds only. An attempt the provider is still deciding about
     * has returned nothing yet, and showing it as refunded would tell a
     * customer their money is back before it is.
     */
    public function refunded(OrderPayment $payment): Money
    {
        return Money::fromMinor(
            $this->sum($payment, [RefundStatus::Succeeded]),
            $payment->currency,
        );
    }

    /**
     * What has gone back across every payment on this order.
     *
     * Derived from the refund rows rather than cached on the order. A stored
     * total would be a projection needing its own guard and its own drift
     * problem, for a sum that is almost always over a single row.
     */
    public function refundedForOrder(Order $order): Money
    {
        $total = Refund::query()
            ->where('order_id', $order->id)
            ->where('status', RefundStatus::Succeeded)
            ->sum('amount_minor');

        return Money::fromMinor((int) $total, $order->currency);
    }

    /**
     * Whether every pesewa the platform took for this order has gone back.
     *
     * The condition for an order moving to `Refunded`: a partially refunded
     * order has not finished being refunded, and saying otherwise would
     * overstate what the customer has received.
     */
    public function isFullyRefunded(Order $order): bool
    {
        $payment = $order->payments()->successful()->first();

        if ($payment === null) {
            return false;
        }

        return $this->refunded($payment)->minor >= $payment->amount_minor;
    }

    /**
     * @param  list<RefundStatus>  $statuses
     */
    private function sum(OrderPayment $payment, array $statuses): int
    {
        return (int) Refund::query()
            ->where('order_payment_id', $payment->id)
            ->whereIn('status', array_map(fn (RefundStatus $s): string => $s->value, $statuses))
            ->sum('amount_minor');
    }
}
