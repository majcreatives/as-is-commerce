<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Referrals\Actions\RewardReferral;
use App\Domain\Referrals\Services\ReferralProgramme;
use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a customer's first real purchase into their referrer's reward.
 *
 * WHY A LISTENER RATHER THAN A CALL FROM FULFILMENT. The orders domain has no
 * business knowing that referrals exist. It announces that an order became
 * paid -- which it already did, for notifications -- and this subscriber
 * decides whether that means anything to the referral programme. Removing
 * referrals entirely would mean deleting this file and nothing else.
 *
 * THE EVENT IS NOT THE SOURCE OF TRUTH. It is a prompt. Everything that
 * follows re-reads the order, re-checks the qualifying rules against the
 * database, and takes a row lock before writing. A replayed event finds the
 * work done; a lost event leaves a qualifying purchase that
 * `referrals:reconcile` will surface.
 *
 * NOTHING HERE MAY THROW INTO A CALLER. The order has already been paid for
 * and committed by the time this runs. A referral programme failing must never
 * make a completed purchase look like a failure, so everything is wrapped and
 * every failure path ends in a log line.
 */
class ReferralSubscriber
{
    public function __construct(
        private readonly ReferralProgramme $programme,
        private readonly RewardReferral $rewards,
    ) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderStatusChanged::class => 'onOrderStatusChanged',
        ];
    }

    /**
     * An order moved. If it moved into being genuinely paid, somebody's
     * referrer may have earned something.
     */
    public function onOrderStatusChanged(OrderStatusChanged $event): void
    {
        // Only the transition into paid. Later moves along the same path --
        // processing, fulfilled -- describe the same purchase, and acting on
        // each would ask the same question three times.
        if ($event->to !== OrderStatus::Paid) {
            return;
        }

        $this->guard(function () use ($event): void {
            // Re-read rather than trusting the event's copy: the fulfilment
            // path may have blocked the order after marking it paid, and a
            // blocked order does not qualify anybody.
            $order = Order::find($event->order->id);

            if ($order === null || ! $this->programme->orderQualifies($order)) {
                return;
            }

            $referral = $this->programme->outstandingReferralFor($order);

            if ($referral === null) {
                return;
            }

            // Two steps, deliberately. Qualifying records the fact; rewarding
            // issues the credits and may legitimately decline -- and when it
            // does, the qualifying event survives to be retried.
            //
            // Qualifying can also close the referral outright, when the
            // attribution window has already run out. There is nothing to issue
            // in that case, and `reward()` treats anything other than
            // `Qualified` as a programming error -- which this is not. So the
            // decision is made here, where the outcome is expected and can be
            // read without an exception in the log.
            $qualified = $this->rewards->qualify($referral, $order);

            if (! $qualified->awaitsReward()) {
                return;
            }

            $this->rewards->reward($qualified);
        }, 'order_paid');
    }

    /**
     * Run a handler, and swallow anything it throws.
     *
     * The purchase committed before this class saw it. A referral that cannot
     * be rewarded is a problem worth logging and no reason whatever to make a
     * completed payment look like a failure.
     */
    private function guard(callable $work, string $operation): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            Log::error('Referral handling failed', [
                'operation' => 'referral.'.$operation,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
