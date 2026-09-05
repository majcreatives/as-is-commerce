<?php

declare(strict_types=1);

namespace App\Domain\Refunds\Actions;

use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Exceptions\PaymentGatewayError;
use App\Domain\Refunds\Services\RefundLifecycle;
use App\Domain\Refunds\Services\RefundReconciler;
use App\Enums\RefundStatus;
use App\Models\Refund;
use Illuminate\Support\Facades\Log;

/**
 * Asks the provider what became of a refund it accepted.
 *
 * WHY THIS EXISTS. Paystack settles refunds asynchronously: it takes the
 * request, answers `pending`, and finishes the job some time later without
 * telling us. Without something to ask, every refund would sit at `Processing`
 * for ever and no customer would ever be told their money arrived. This is
 * that something, and it is the only reason a refund can reach `Succeeded` at
 * all.
 *
 * IT ASKS; IT DOES NOT DECIDE. The provider's own terminal status is the whole
 * of the evidence. A refund that is still pending there stays `Processing`
 * here, however long it has been -- there is no timeout after which the
 * platform assumes the money went back, because assuming would produce exactly
 * the message a customer must never receive falsely.
 *
 * IT IS NOT RECONCILIATION. This completes refunds we know are outstanding.
 * Detecting disagreements between our records and the provider's is
 * {@see RefundReconciler}, which reports and
 * never repairs.
 *
 * Idempotent, and safe to run repeatedly: the lifecycle re-reads each refund
 * under a lock and a settled one is left exactly as it is.
 */
final class VerifyRefund
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly RefundLifecycle $lifecycle,
    ) {}

    /**
     * Bring one refund up to date with the provider.
     *
     * Returns the refund as it now stands -- unchanged, in the ordinary case
     * where the provider is still deciding.
     */
    public function handle(Refund $refund): Refund
    {
        if ($refund->status !== RefundStatus::Processing) {
            // Nothing outstanding. A pending refund has not been sent yet and
            // a settled one is finished.
            return $refund;
        }

        $reference = $refund->provider_reference;

        if ($reference === null || $reference === '') {
            // Accepted by the provider without an id we can ask about. Left
            // alone deliberately: guessing which refund it meant would be
            // worse than leaving it for the reconciliation report, which
            // names exactly this case.
            Log::warning('Refund is processing without a provider reference', [
                'operation' => 'refund.unverifiable',
                'refund_id' => $refund->id,
                'order_id' => $refund->order_id,
            ]);

            return $refund;
        }

        try {
            $provider = $this->gateway->fetchRefund($reference);
        } catch (PaymentGatewayError $e) {
            // Not knowing is not the same as failing. The provider may be
            // briefly unreachable, and marking a customer's refund failed
            // because of a timeout would be a lie the next sweep would have to
            // take back.
            Log::warning('Could not fetch a refund from the provider', [
                'operation' => 'refund.fetch_failed',
                'refund_id' => $refund->id,
                'message' => $e->getMessage(),
            ]);

            return $refund;
        }

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

        // Still pending there. Recorded so the queue shows how recently we
        // asked, and left otherwise untouched.
        $refund->provider_status = $provider->status;
        $refund->save();

        return $refund;
    }
}
