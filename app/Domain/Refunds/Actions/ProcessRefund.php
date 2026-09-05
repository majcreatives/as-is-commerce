<?php

declare(strict_types=1);

namespace App\Domain\Refunds\Actions;

use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Exceptions\PaymentGatewayError;
use App\Domain\Payments\ValueObjects\ProviderRefund;
use App\Domain\Refunds\Exceptions\RefundNotAllowed;
use App\Domain\Refunds\Services\RefundLifecycle;
use App\Enums\RefundStatus;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Support\CauserResolver;
use Throwable;

/**
 * Asks the provider to send the money back, and records what it said.
 *
 * THE PROVIDER DECIDES, NOT THIS CLASS. Paystack accepting the request is not
 * the money arriving -- refunds there are queued and settled afterwards -- so
 * an accepted request becomes `Processing` and nothing more. Only
 * {@see ProviderRefund::isSucceeded()}, reading the provider's own terminal
 * status, produces a successful refund. Treating acceptance as success would
 * mean telling a customer their money is back while the provider is still
 * deciding whether to send it, and that message cannot be taken back.
 *
 * IT DOES NOT THROW WHEN THE PROVIDER SAYS NO. A rejection, an unreachable
 * host, a malformed answer -- each is recorded on the refund as a failure with
 * a reason a person can read, and the caller is handed the failed refund. The
 * original payment stays exactly as it was, the order is untouched, and the
 * attempt stays visible in the queue. Throwing instead would leave a `Pending`
 * row nobody could explain and an administrator with a stack trace.
 *
 * IT NEVER RETRIES BY ITSELF. A failed refund is a person's decision to make
 * again; a loop that kept asking would hammer the provider on a request it has
 * already refused, and could return money twice if one of those attempts
 * quietly succeeded.
 *
 * AMOUNT AND CURRENCY ARE CHECKED AGAINST THE REFUND, not the order and not
 * the payment. The refund row is what was actually asked of the provider, and
 * a trigger refuses any change to it -- so comparing against it is a real
 * check rather than a comparison with something that could have moved.
 */
final class ProcessRefund
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly RefundLifecycle $lifecycle,
    ) {}

    /**
     * Send a pending refund to the provider and record the outcome.
     *
     * @param  User|null  $actor  The member of staff who set it going, for the
     *                            audit record. Null when a scheduled command
     *                            is the one acting.
     *
     * @throws RefundNotAllowed When the refund is not waiting to be sent.
     */
    public function handle(Refund $refund, ?User $actor = null): Refund
    {
        // Locked and re-read, so two administrators pressing the button
        // together cannot both send the same refund to the provider.
        $claimed = $this->claim($refund);

        try {
            $provider = $this->gateway->refundTransaction(
                reference: $claimed->payment->provider_reference,
                amount: $claimed->amount(),
                reason: $claimed->reason->value,
            );
        } catch (PaymentGatewayError $e) {
            // The provider refused it or could not be reached. Recorded as a
            // failure rather than thrown: the attempt is a fact, and it
            // belongs in the queue where somebody can see it and decide.
            return $this->recordFailure($claimed, $e->getMessage(), $actor);
        } catch (Throwable $e) {
            Log::error('Refund call failed unexpectedly', [
                'operation' => 'refund.provider_error',
                'refund_id' => $claimed->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->recordFailure(
                $claimed,
                'The refund could not be sent to the payment provider.',
                $actor,
            );
        }

        $mismatch = $this->mismatch($claimed, $provider);

        if ($mismatch !== null) {
            // The provider is describing something other than what we asked
            // for. Never "close enough" where money is concerned.
            return $this->recordFailure($claimed, $mismatch, $actor, $provider);
        }

        $settled = $this->settle($claimed, $provider);

        // The action names the outcome, not the fact that a call was made. A
        // refund the provider refused filed under "processed" would be missed
        // by anybody auditing failures, which is exactly who goes looking.
        $this->audit($settled, $actor, $this->actionFor($settled->status), [
            'provider_status' => $provider->status,
            'provider_reference' => $provider->providerReference,
            'result' => $settled->status->value,
        ]);

        return $settled;
    }

    /**
     * Take the refund under a lock and confirm it is still ours to send.
     *
     * @throws RefundNotAllowed
     */
    private function claim(Refund $refund): Refund
    {
        $locked = $this->lifecycle->lock($refund);

        if ($locked->status !== RefundStatus::Pending) {
            throw RefundNotAllowed::notPending($locked->status->value);
        }

        return $locked->load('payment');
    }

    /**
     * Record whatever the provider's answer amounts to.
     */
    private function settle(Refund $refund, ProviderRefund $provider): Refund
    {
        if ($provider->isSucceeded()) {
            return $this->lifecycle->settle($refund, $provider);
        }

        if ($provider->isFailed()) {
            return $this->lifecycle->fail(
                $refund,
                "The payment provider reported the refund [{$provider->status}].",
                $provider,
            );
        }

        // Still being decided, which is the ordinary answer. The refund waits,
        // and `refunds:reconcile` asks again.
        return $this->lifecycle->markProcessing($refund, $provider);
    }

    /**
     * Whether the provider is describing the refund we actually asked for.
     *
     * A provider answering with a different amount or currency is either a bug
     * or something worse, and in both cases the right move is to stop and let
     * a person look rather than record a success.
     */
    private function mismatch(Refund $refund, ProviderRefund $provider): ?string
    {
        if (mb_strtoupper($provider->currency) !== mb_strtoupper($refund->currency)) {
            return "The provider answered in {$provider->currency} for a refund requested "
                ."in {$refund->currency}.";
        }

        if ($provider->amountMinor !== $refund->amount_minor) {
            return 'The provider answered for a different amount than the one requested.';
        }

        return null;
    }

    /**
     * What to file this audit entry under.
     */
    private function actionFor(RefundStatus $status): string
    {
        return match ($status) {
            RefundStatus::Succeeded => 'succeeded',
            RefundStatus::Failed => 'failed',
            RefundStatus::Processing => 'sent',
            RefundStatus::Pending => 'requested',
        };
    }

    private function recordFailure(
        Refund $refund,
        string $reason,
        ?User $actor,
        ?ProviderRefund $provider = null,
    ): Refund {
        $failed = $this->lifecycle->fail($refund, $reason, $provider);

        $this->audit($failed, $actor, 'failed', ['failure_reason' => $failed->failure_reason]);

        return $failed;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function audit(Refund $refund, ?User $actor, string $action, array $properties): void
    {
        $log = function () use ($refund, $action, $properties): void {
            activity('refund')
                ->performedOn($refund)
                ->withProperties(array_merge([
                    'action' => $action,
                    'order_id' => $refund->order_id,
                    'order_payment_id' => $refund->order_payment_id,
                    'amount_minor' => $refund->amount_minor,
                    'currency' => $refund->currency,
                    'status' => $refund->status->value,
                ], $properties))
                ->log("Refund {$action}");
        };

        // Explicit, so a refund driven by a scheduled command still attributes
        // correctly instead of recording whichever session happened to be
        // resolvable.
        $actor === null ? $log() : app(CauserResolver::class)->withCauser($actor, $log);
    }
}
