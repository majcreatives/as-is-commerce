<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Refunds\Actions\VerifyRefund;
use App\Domain\Refunds\Services\RefundReconciler;
use App\Enums\RefundStatus;
use App\Models\Refund;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finishes outstanding refunds, and reports anything that does not add up.
 *
 * WHY THIS EXISTS. Paystack settles refunds asynchronously: it accepts the
 * request, answers `pending`, and completes the job later without telling us.
 * Without something to ask, every refund would sit at `Processing` for ever
 * and no customer would ever be told their money arrived. There is no queue
 * worker in this application, so this is a scheduled command -- bounded, not a
 * polling loop.
 *
 * TWO JOBS, AND THEY ARE DIFFERENT.
 *
 *   Verify       Asks the provider what became of refunds we know are
 *                outstanding, and records the answer. This is the only way a
 *                refund reaches `Succeeded`, and it changes only refunds whose
 *                outcome the provider has decided.
 *
 *   Reconcile    Looks for disagreements between our records and the
 *                provider's, and reports them. It repairs nothing, ever --
 *                an automatic repair is a guess about which of two disagreeing
 *                records is right, made by the code whose bug may have caused
 *                the disagreement. The same principle as the credit ledger's
 *                reconciliation, for the same reason.
 *
 * Idempotent throughout. Each refund is re-read under a row lock and a settled
 * one is left exactly as it is, so overlapping runs settle a refund once. A
 * missed run delays a customer being told; it never changes an outcome, and
 * never invents one.
 *
 * One refund failing must not strand the others: a single unreachable record
 * should not hold up everybody else's money.
 */
class ReconcileRefunds extends Command
{
    protected $signature = 'refunds:reconcile
        {--limit=100 : Most refunds to check in one pass}
        {--skip-report : Settle outstanding refunds without running the anomaly report}';

    protected $description = 'Settle refunds the provider has decided, and report anything that disagrees';

    public function handle(VerifyRefund $verify, RefundReconciler $reconciler): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $settled = $this->verifyOutstanding($verify, $limit);

        $this->info("Checked {$settled['checked']} outstanding refund(s); {$settled['settled']} settled.");

        if ($this->option('skip-report') === true) {
            return self::SUCCESS;
        }

        $anomalies = $reconciler->report($limit);

        if ($anomalies === []) {
            $this->info('No reconciliation anomalies.');

            return self::SUCCESS;
        }

        // Reported, never repaired. Every line here wants a person.
        $this->warn(count($anomalies).' reconciliation anomal'.(count($anomalies) === 1 ? 'y' : 'ies').':');

        $this->table(
            ['Type', 'Refund', 'Order', 'Detail'],
            array_map(fn (array $a): array => [
                $a['type'],
                $a['refund_id'] ?? '-',
                $a['order_id'] ?? '-',
                $a['detail'],
            ], $anomalies),
        );

        // A non-zero exit so a scheduler or a monitor notices. The anomalies
        // are recorded either way; this is how somebody finds out without
        // reading logs.
        return self::FAILURE;
    }

    /**
     * @return array{checked: int, settled: int}
     */
    private function verifyOutstanding(VerifyRefund $verify, int $limit): array
    {
        $checked = 0;
        $settled = 0;

        $outstanding = Refund::query()
            ->awaitingProvider()
            ->whereNotNull('provider_reference')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($outstanding as $refund) {
            $checked++;

            try {
                $result = $verify->handle($refund);

                if ($result->status !== RefundStatus::Processing) {
                    $settled++;
                }
            } catch (Throwable $e) {
                // Logged and skipped. One refund that cannot be checked must
                // not stop every other customer's money being confirmed.
                Log::error('Refund verification failed', [
                    'operation' => 'refund.verify_failed',
                    'refund_id' => $refund->id,
                    'order_id' => $refund->order_id,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                $this->warn("Refund {$refund->id} could not be checked: {$e->getMessage()}");
            }
        }

        return ['checked' => $checked, 'settled' => $settled];
    }
}
