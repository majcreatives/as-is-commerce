<?php

declare(strict_types=1);

namespace App\Domain\Refunds\Services;

use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Exceptions\PaymentGatewayError;
use App\Enums\RefundStatus;
use App\Models\Refund;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Looks for disagreements between our refund records and the provider's.
 *
 * IT DETECTS. IT DOES NOT REPAIR. Every anomaly here is reported and left
 * exactly as it is, for the same reason the credit ledger's reconciliation
 * reports rather than corrects: an automatic repair is a guess about which of
 * two disagreeing records is right, made by the very code whose bug may have
 * caused the disagreement. If our books say a customer was refunded and
 * Paystack has never heard of it, quietly rewriting either side destroys the
 * evidence somebody needs to work out what happened.
 *
 * The correct response to anything in this report is a person looking, and
 * then a new financial act with its own record.
 *
 * WHAT IT LOOKS FOR:
 *
 *   Unconfirmed success     We say the money went back; the provider cannot
 *                           confirm it. The most serious of these.
 *   Untracked processing    Accepted by the provider with no id to ask about,
 *                           so it can never be settled automatically.
 *   Amount disagreement     The provider's figure is not the one we recorded.
 *   Currency disagreement   Likewise.
 *   Over-refund             Refunds against one payment sum past what was
 *                           paid. Should be impossible -- the cap is enforced
 *                           under a row lock -- which is exactly why it is
 *                           worth checking for.
 *   Stalled                 Sitting at processing far longer than a refund
 *                           takes. Not wrong, but worth somebody's attention.
 *
 * Read-only throughout: nothing in this class writes to the database.
 */
class RefundReconciler
{
    /**
     * How long a refund may sit at `Processing` before it is worth mentioning.
     *
     * Not a rule and not a timeout -- nothing happens to a stalled refund
     * because of this number, and it never causes one to be called succeeded
     * or failed. It only decides when a human is told to go and look.
     */
    public const STALLED_AFTER_HOURS = 72;

    public function __construct(
        private readonly PaymentGateway $gateway,
    ) {}

    /**
     * Everything that does not add up.
     *
     * @param  bool  $askProvider  Whether to check our records against the
     *                             provider's. False keeps the report local and
     *                             makes no network calls, which is what a test
     *                             or a quick look wants.
     * @return list<array{type: string, refund_id: int|null, order_id: int|null, detail: string}>
     */
    public function report(int $limit = 200, bool $askProvider = true): array
    {
        $anomalies = [
            ...$this->overRefundedPayments(),
            ...$this->untrackedProcessing($limit),
            ...$this->stalled($limit),
        ];

        if ($askProvider) {
            $anomalies = [...$anomalies, ...$this->disagreementsWithProvider($limit)];
        }

        foreach ($anomalies as $anomaly) {
            Log::warning('Refund reconciliation anomaly', [
                'operation' => 'refund.reconciliation_anomaly',
                'type' => $anomaly['type'],
                'refund_id' => $anomaly['refund_id'],
                'order_id' => $anomaly['order_id'],
                'detail' => $anomaly['detail'],
            ]);
        }

        return $anomalies;
    }

    /**
     * Payments whose refunds sum past what was actually paid.
     *
     * This should be impossible: the cap is checked under the order row lock
     * before any refund is written. Checking anyway is the point of
     * reconciliation -- an invariant nobody verifies is a hope.
     *
     * @return list<array{type: string, refund_id: int|null, order_id: int|null, detail: string}>
     */
    private function overRefundedPayments(): array
    {
        $rows = DB::table('refunds')
            ->join('order_payments', 'order_payments.id', '=', 'refunds.order_payment_id')
            ->where('refunds.status', RefundStatus::Succeeded->value)
            ->groupBy('refunds.order_payment_id', 'order_payments.amount_minor')
            ->havingRaw('SUM(refunds.amount_minor) > order_payments.amount_minor')
            ->select([
                'refunds.order_payment_id',
                'order_payments.amount_minor as paid_minor',
                DB::raw('SUM(refunds.amount_minor) as refunded_minor'),
            ])
            ->get();

        return $rows->map(fn (object $row): array => [
            'type' => 'over_refunded',
            'refund_id' => null,
            'order_id' => null,
            'detail' => "Payment {$row->order_payment_id} took {$row->paid_minor} and has "
                ."{$row->refunded_minor} recorded as refunded against it.",
        ])->all();
    }

    /**
     * Refunds the provider accepted without giving us anything to ask about.
     *
     * These can never be settled by the reconcile command, so they would
     * otherwise sit at `Processing` silently for ever.
     *
     * @return list<array{type: string, refund_id: int|null, order_id: int|null, detail: string}>
     */
    private function untrackedProcessing(int $limit): array
    {
        return $this->refunds(
            Refund::query()
                ->where('status', RefundStatus::Processing)
                ->where(fn ($q) => $q->whereNull('provider_reference')->orWhere('provider_reference', ''))
                ->limit($limit)
                ->get(),
            'untracked_processing',
            fn (Refund $refund): string => 'Accepted by the provider with no reference to check, '
                .'so it cannot be settled automatically.',
        );
    }

    /**
     * Refunds that have been in flight far longer than one should take.
     *
     * @return list<array{type: string, refund_id: int|null, order_id: int|null, detail: string}>
     */
    private function stalled(int $limit): array
    {
        return $this->refunds(
            Refund::query()
                ->where('status', RefundStatus::Processing)
                ->whereNotNull('provider_reference')
                ->where('processed_at', '<', now()->subHours(self::STALLED_AFTER_HOURS))
                ->limit($limit)
                ->get(),
            'stalled',
            fn (Refund $refund): string => 'Sent to the provider on '
                .($refund->processed_at?->toDateTimeString() ?? 'an unknown date')
                .' and still unsettled.',
        );
    }

    /**
     * Where our record and the provider's do not agree.
     *
     * Checks succeeded refunds, which is where a disagreement matters most: a
     * refund we have told a customer about, and which the provider does not
     * confirm, is the one anomaly that has already reached somebody's inbox.
     *
     * @return list<array{type: string, refund_id: int|null, order_id: int|null, detail: string}>
     */
    private function disagreementsWithProvider(int $limit): array
    {
        $anomalies = [];

        $settled = Refund::query()
            ->where('status', RefundStatus::Succeeded)
            ->whereNotNull('provider_reference')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        foreach ($settled as $refund) {
            $reference = $refund->provider_reference;

            if ($reference === null) {
                continue;
            }

            try {
                $provider = $this->gateway->fetchRefund($reference);
            } catch (PaymentGatewayError $e) {
                $anomalies[] = [
                    'type' => 'unconfirmed_success',
                    'refund_id' => $refund->id,
                    'order_id' => $refund->order_id,
                    'detail' => 'Recorded as refunded here, and the provider could not confirm it: '
                        .$e->getMessage(),
                ];

                continue;
            }

            if (! $provider->isSucceeded()) {
                $anomalies[] = [
                    'type' => 'unconfirmed_success',
                    'refund_id' => $refund->id,
                    'order_id' => $refund->order_id,
                    'detail' => "Recorded as refunded here; the provider says [{$provider->status}].",
                ];
            }

            if ($provider->amountMinor !== $refund->amount_minor) {
                $anomalies[] = [
                    'type' => 'amount_disagreement',
                    'refund_id' => $refund->id,
                    'order_id' => $refund->order_id,
                    'detail' => "Recorded as {$refund->amount_minor}; the provider says "
                        ."{$provider->amountMinor}.",
                ];
            }

            if (mb_strtoupper($provider->currency) !== mb_strtoupper($refund->currency)) {
                $anomalies[] = [
                    'type' => 'currency_disagreement',
                    'refund_id' => $refund->id,
                    'order_id' => $refund->order_id,
                    'detail' => "Recorded in {$refund->currency}; the provider says "
                        ."{$provider->currency}.",
                ];
            }
        }

        return $anomalies;
    }

    /**
     * @param  Collection<int, Refund>  $refunds
     * @param  callable(Refund): string  $detail
     * @return list<array{type: string, refund_id: int|null, order_id: int|null, detail: string}>
     */
    private function refunds(Collection $refunds, string $type, callable $detail): array
    {
        return $refunds->map(fn (Refund $refund): array => [
            'type' => $type,
            'refund_id' => $refund->id,
            'order_id' => $refund->order_id,
            'detail' => $detail($refund),
        ])->all();
    }
}
