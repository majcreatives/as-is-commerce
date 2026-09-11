<?php

declare(strict_types=1);

namespace App\Domain\Operations\Queries;

use App\Domain\Operations\ValueObjects\OperationalException;
use App\Domain\Operations\ValueObjects\Severity;
use App\Domain\Referrals\Services\ReferralReconciler;
use App\Domain\Refunds\Services\RefundReconciler;
use App\Domain\StoreWallet\Services\StoreWalletReconciler;
use App\Enums\AuctionStatus;
use App\Enums\DeliveryStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Enums\WebhookProcessingStatus;
use App\Models\Auction;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\PaymentWebhookEvent;
use App\Models\Product;
use App\Models\Refund;
use App\Models\StoreWallet;
use Illuminate\Support\Carbon;

/**
 * Everything on the platform that needs a person.
 *
 * ONE PLACE TO LOOK. Before this, the things that need human judgement were
 * spread across six screens and two console commands: a paid order nobody could
 * deliver, a refund the provider refused, a package with nowhere to go, a
 * referral that qualified and was never paid. Operations needed to know all of
 * them and had to remember where each one lived.
 *
 * IT DETECTS AND IT REPORTS. Nothing here repairs anything, and there is no
 * "dismiss" or "acknowledge" -- an exception disappears when the situation it
 * describes stops being true. Marking one as handled without handling it would
 * be exactly the wrong affordance for a screen whose entire purpose is to stop
 * things being forgotten.
 *
 * IT REUSES THE RECONCILERS THAT ALREADY EXIST rather than reimplementing what
 * they know. The refund and referral reconcilers own their own definitions of
 * "wrong"; this asks them and presents the answer alongside the situations that
 * are visible from ordinary queries.
 *
 * EVERY QUERY IS BOUNDED. An operations screen is most needed on the worst day,
 * which is exactly the day an unbounded query would take the site down with it.
 *
 * NOTHING SENSITIVE APPEARS. Every detail line is written for staff and carries
 * references, amounts and reasons -- never a credential, a signature, a card
 * detail or a customer's contact information.
 */
class ExceptionCentre
{
    /** Deliberately small. A triage list is not an export. */
    public const PER_CATEGORY = 50;

    /**
     * Detection is expensive, and one page asks for both the list and the
     * counts. Memoized per instance -- which is per request, since nothing
     * registers this as a singleton -- so a render sweeps the tables once.
     *
     * Keyed on whether providers were asked, because the two answers are
     * genuinely different and a cached local answer must not be handed back to
     * somebody who pressed the button.
     *
     * @var array<string, array<string, list<OperationalException>>>
     */
    private array $memo = [];

    public function __construct(
        private readonly RefundReconciler $refunds,
        private readonly ReferralReconciler $referrals,
        private readonly StoreWalletReconciler $storeWallets,
    ) {}

    /**
     * Everything, grouped by the part of the platform that noticed.
     *
     * @param  bool  $askProviders  Whether reconciliation may make network
     *                              calls. False keeps the page local and fast,
     *                              which is what a dashboard wants.
     * @return array<string, list<OperationalException>>
     */
    public function grouped(bool $askProviders = false): array
    {
        $key = $askProviders ? 'remote' : 'local';

        return $this->memo[$key] ??= [
            'payments' => $this->payments(),
            'webhooks' => $this->webhooks(),
            'refunds' => $this->refundExceptions($askProviders),
            'auctions' => $this->auctions(),
            'delivery' => $this->delivery(),
            'referrals' => $this->referralExceptions(),
            'inventory' => $this->inventory(),
            'store_wallet' => $this->storeWallets(),
        ];
    }

    /**
     * Everything, most serious first.
     *
     * @param  string|null  $category  Restrict to one category, for a filter.
     * @return list<OperationalException>
     */
    public function all(?string $category = null, bool $askProviders = false): array
    {
        $found = $this->grouped($askProviders);

        $exceptions = $category !== null && isset($found[$category])
            ? $found[$category]
            : array_merge(...array_values($found));

        usort(
            $exceptions,
            fn (OperationalException $a, OperationalException $b): int => $a->severity->rank() <=> $b->severity->rank(),
        );

        return $exceptions;
    }

    /**
     * How many of each, for the dashboard and the filter chips.
     *
     * @return array<string, int>
     */
    public function counts(bool $askProviders = false): array
    {
        $counts = array_map(
            static fn (array $found): int => count($found),
            $this->grouped($askProviders),
        );

        $counts['total'] = array_sum($counts);

        return $counts;
    }

    // ---------------------------------------------------------- Store Wallet

    /**
     * Store Wallets whose materialized balance does not match the ledger.
     *
     * A single query that detects projection divergence -- enough for a
     * dashboard count without the per-transaction depth of the dedicated
     * screen.
     *
     * @return list<OperationalException>
     */
    private function storeWallets(): array
    {
        $mismatches = $this->storeWallets->projectionMismatches(self::PER_CATEGORY);

        return $mismatches->map(fn (StoreWallet $wallet): OperationalException => new OperationalException(
            category: 'store_wallet',
            type: 'projection_divergence',
            severity: Severity::Critical,
            detail: "Store Wallet #{$wallet->id} balance ({$wallet->balance_minor}) does not match the sum of its ledger entries.",
            reference: 'User #'.$wallet->user_id,
            url: route('admin.wallets.show', $wallet->user_id),
            detectedAt: $wallet->updated_at,
            nextAction: 'Inspect the ledger on the Store Wallets screen. Correct with a compensating entry, never an edit.',
        ))->all();
    }

    // ------------------------------------------------------------ Payments

    /**
     * Money received that the platform could not deliver against.
     *
     * The three cases Stages 7 and 8 deliberately left for a person: another
     * transaction took the unit, the checkout expired mid-payment, or the order
     * had already been cancelled. All real money, all recorded, none of them
     * resolvable by code.
     *
     * @return list<OperationalException>
     */
    private function payments(): array
    {
        $blocked = Order::query()
            ->blocked()
            ->with('user')
            ->whereDoesntHave('refunds', fn ($q) => $q->whereIn('status', [
                RefundStatus::Pending,
                RefundStatus::Processing,
                RefundStatus::Succeeded,
            ]))
            ->latest('id')
            ->limit(self::PER_CATEGORY)
            ->get();

        return $blocked->map(function (Order $order): OperationalException {
            // A payment that landed on an order which had already closed is a
            // different situation from one that lost a race for stock, and the
            // person deciding what is owed needs to know which.
            $late = in_array($order->status, [
                OrderStatus::PaymentExpired,
                OrderStatus::Cancelled,
            ], true);

            return new OperationalException(
                category: 'payments',
                type: $late ? 'payment_after_close' : 'fulfilment_blocked',
                severity: Severity::Critical,
                detail: $late
                    ? "A payment succeeded after this order was already [{$order->status->value}]. "
                        .'The money is recorded and nothing was delivered.'
                    : 'Paid, and nothing could be delivered against it: '
                        .($order->fulfilment_blocked_reason ?? 'no reason recorded'),
                reference: $order->order_number,
                url: route('admin.orders.show', $order),
                detectedAt: $order->paid_at ?? $order->updated_at,
                nextAction: 'Decide what is owed, and refund through the order if it should be.',
            );
        })->all();
    }

    // ------------------------------------------------------------- Webhooks

    /**
     * Webhook events whose processing failed and remain unresolved.
     *
     * A failed event means Paystack told us something happened but we could
     * not act on it. Paystack will retry delivery, but an operator needs to
     * know something is stuck so they can investigate the root cause.
     *
     * Bounded, local, no provider calls. The webhook events screen holds
     * the full payload and context.
     *
     * @return list<OperationalException>
     */
    private function webhooks(): array
    {
        $failed = PaymentWebhookEvent::query()
            ->where('processing_status', WebhookProcessingStatus::Failed)
            ->latest('id')
            ->limit(self::PER_CATEGORY)
            ->get();

        return $failed->map(fn (PaymentWebhookEvent $event): OperationalException => new OperationalException(
            category: 'webhooks',
            type: 'webhook_processing_failed',
            severity: Severity::Warning,
            detail: 'A webhook event could not be processed: '
                .($event->processing_error ?? 'no error recorded'),
            reference: $event->provider->value.':'.$event->provider_event_id,
            url: route('admin.payment-events'),
            detectedAt: $event->processed_at ?? $event->received_at,
            nextAction: 'Paystack will retry delivery. Check the webhook events screen for the full payload.',
        ))->all();
    }

    // ------------------------------------------------------------- Refunds

    /**
     * @return list<OperationalException>
     */
    private function refundExceptions(bool $askProviders): array
    {
        $exceptions = [];

        // Refunds the provider refused. The money did not move, and somebody
        // has to decide whether to try again.
        $failed = Refund::query()
            ->where('status', RefundStatus::Failed)
            ->with('order')
            ->latest('id')
            ->limit(self::PER_CATEGORY)
            ->get();

        foreach ($failed as $refund) {
            $exceptions[] = new OperationalException(
                category: 'refunds',
                type: 'refund_failed',
                severity: Severity::Critical,
                detail: 'A refund did not go through: '
                    .($refund->failure_reason ?? 'no reason recorded'),
                reference: $refund->order?->order_number,
                url: $refund->order === null ? null : route('admin.orders.show', $refund->order),
                detectedAt: $refund->failed_at,
                nextAction: 'Review and start a new refund from the order if it should be retried.',
            );
        }

        // Sent to the provider and never settled.
        $stalled = Refund::query()
            ->where('status', RefundStatus::Processing)
            ->where('processed_at', '<', Carbon::now()->subHours(RefundReconciler::STALLED_AFTER_HOURS))
            ->with('order')
            ->limit(self::PER_CATEGORY)
            ->get();

        foreach ($stalled as $refund) {
            $exceptions[] = new OperationalException(
                category: 'refunds',
                type: 'refund_stalled',
                severity: Severity::Warning,
                detail: 'Sent to the provider on '
                    .($refund->processed_at?->toDateTimeString() ?? 'an unknown date')
                    .' and still unsettled.',
                reference: $refund->order?->order_number,
                url: $refund->order === null ? null : route('admin.orders.show', $refund->order),
                detectedAt: $refund->processed_at,
                nextAction: 'Run refunds:reconcile, or check the provider directly.',
            );
        }

        // Everything the refund reconciler considers wrong. Asked rather than
        // reimplemented: it owns its own definitions.
        foreach ($this->refunds->report(limit: self::PER_CATEGORY, askProvider: $askProviders) as $anomaly) {
            // Already surfaced above, in a form that links somewhere useful.
            if (in_array($anomaly['type'], ['stalled'], true)) {
                continue;
            }

            $exceptions[] = new OperationalException(
                category: 'refunds',
                type: $anomaly['type'],
                severity: $anomaly['type'] === 'unconfirmed_success'
                    ? Severity::Critical
                    : Severity::Warning,
                detail: $anomaly['detail'],
                reference: $anomaly['refund_id'] === null ? null : 'Refund #'.$anomaly['refund_id'],
                url: route('admin.refunds'),
                nextAction: 'Reconciliation reports only. A person decides what to do.',
            );
        }

        return $exceptions;
    }

    // ------------------------------------------------------------ Auctions

    /**
     * Auctions whose records do not line up.
     *
     * @return list<OperationalException>
     */
    private function auctions(): array
    {
        $exceptions = [];

        // A winner with no settlement checkout. Closing stands even when the
        // handoff fails -- deliberately -- so this is where that shows up.
        $missingSettlement = Auction::query()
            ->where('status', AuctionStatus::PendingSettlement)
            ->whereNotNull('winner_user_id')
            ->whereDoesntHave('orders', fn ($q) => $q->where('source', OrderSource::AuctionWin))
            ->limit(self::PER_CATEGORY)
            ->get();

        foreach ($missingSettlement as $auction) {
            $exceptions[] = new OperationalException(
                category: 'auctions',
                type: 'settlement_handoff_failed',
                severity: Severity::Critical,
                detail: 'This auction has a winner but no settlement checkout was opened. '
                    .'The win stands; the obligation is missing.',
                reference: 'Auction #'.$auction->id,
                url: route('admin.auctions.show', $auction),
                // When the auction was due to end. The clock is the authority
                // on that, and closing happens on the sweep that follows it.
                detectedAt: $auction->ends_at,
                nextAction: 'Investigate the handoff. The winner cannot pay until an order exists.',
            );
        }

        // A settlement deadline that has passed without the sweep closing it.
        // The clock is authoritative; if this appears, the sweep is not running.
        $overdue = Auction::query()
            ->where('status', AuctionStatus::PendingSettlement)
            ->whereNotNull('settlement_due_at')
            ->where('settlement_due_at', '<', Carbon::now()->subHour())
            ->limit(self::PER_CATEGORY)
            ->get();

        foreach ($overdue as $auction) {
            $exceptions[] = new OperationalException(
                category: 'auctions',
                type: 'settlement_overdue',
                severity: Severity::Warning,
                detail: 'The settlement deadline passed over an hour ago and this auction has '
                    .'not been forfeited. The sweep may not be running.',
                reference: 'Auction #'.$auction->id,
                url: route('admin.auctions.show', $auction),
                detectedAt: $auction->settlement_due_at,
                nextAction: 'Check that auctions:tick is scheduled and running.',
            );
        }

        return $exceptions;
    }

    // ------------------------------------------------------------ Delivery

    /**
     * @return list<OperationalException>
     */
    private function delivery(): array
    {
        $exceptions = [];

        $failed = Delivery::query()
            ->where('status', DeliveryStatus::DeliveryFailed)
            ->with('order')
            ->latest('id')
            ->limit(self::PER_CATEGORY)
            ->get();

        foreach ($failed as $delivery) {
            $exceptions[] = new OperationalException(
                category: 'delivery',
                type: 'delivery_failed',
                severity: Severity::Warning,
                detail: 'A delivery attempt did not succeed: '
                    .($delivery->failure_reason?->label() ?? 'no reason recorded')
                    .'. The customer still has neither the item nor their money.',
                reference: $delivery->reference,
                url: $delivery->order === null ? null : route('admin.orders.show', $delivery->order),
                detectedAt: $delivery->failed_at,
                nextAction: 'Retry from the order, or decide whether a refund is owed.',
            );
        }

        // Waiting on the customer rather than on the warehouse.
        $noAddress = Delivery::query()
            ->where('status', DeliveryStatus::Pending)
            ->whereNull('address_line')
            ->with('order')
            ->limit(self::PER_CATEGORY)
            ->get();

        foreach ($noAddress as $delivery) {
            $exceptions[] = new OperationalException(
                category: 'delivery',
                type: 'delivery_awaiting_address',
                severity: Severity::Notice,
                detail: 'This package has no delivery address yet, so nothing can be packed. '
                    .'Usually an auction win, where the order was created automatically.',
                reference: $delivery->reference,
                url: $delivery->order === null ? null : route('admin.orders.show', $delivery->order),
                detectedAt: $delivery->created_at,
                nextAction: 'The customer supplies this. Contact them if it has been a while.',
            );
        }

        // A paid order that never got a package at all. The handoff logs and
        // carries on rather than un-paying an order, so this is where it shows.
        $missingDelivery = Order::query()
            ->whereIn('status', [OrderStatus::Paid, OrderStatus::Processing])
            ->whereNull('fulfilment_blocked_reason')
            ->whereDoesntHave('delivery')
            ->latest('id')
            ->limit(self::PER_CATEGORY)
            ->get();

        foreach ($missingDelivery as $order) {
            $exceptions[] = new OperationalException(
                category: 'delivery',
                type: 'delivery_missing',
                severity: Severity::Critical,
                detail: 'This order was paid and deliverable but has no delivery record, so it '
                    .'will never appear in the fulfilment queue.',
                reference: $order->order_number,
                url: route('admin.orders.show', $order),
                detectedAt: $order->paid_at,
                nextAction: 'Investigate the fulfilment handoff for this order.',
            );
        }

        return $exceptions;
    }

    // ----------------------------------------------------------- Referrals

    /**
     * @return list<OperationalException>
     */
    private function referralExceptions(): array
    {
        $severities = [
            'qualified_not_rewarded' => Severity::Warning,
            'reward_without_evidence' => Severity::Critical,
            'amount_disagreement' => Severity::Critical,
            'duplicate_reward' => Severity::Critical,
            'self_referral' => Severity::Critical,
            'wrong_recipient' => Severity::Critical,
            'wrong_transaction_type' => Severity::Critical,
            'missing_ledger_row' => Severity::Critical,
        ];

        return array_map(
            fn (array $anomaly): OperationalException => new OperationalException(
                category: 'referrals',
                type: $anomaly['type'],
                severity: $severities[$anomaly['type']] ?? Severity::Warning,
                detail: $anomaly['detail'],
                reference: $anomaly['referral_id'] === null ? null : 'Referral #'.$anomaly['referral_id'],
                url: route('admin.referrals'),
                nextAction: 'Reconciliation reports only, and issues no credits.',
            ),
            $this->referrals->report(self::PER_CATEGORY),
        );
    }

    // ----------------------------------------------------------- Inventory

    /**
     * Stock projections that cannot be true.
     *
     * These should be impossible: the inventory service takes a row lock, and
     * database constraints refuse negative stock. Checked because an invariant
     * nobody verifies is a hope.
     *
     * @return list<OperationalException>
     */
    private function inventory(): array
    {
        $impossible = Product::query()
            ->where(fn ($q) => $q->whereRaw('stock_reserved > stock_on_hand')
                ->orWhere('stock_on_hand', '<', 0)
                ->orWhere('stock_reserved', '<', 0))
            ->limit(self::PER_CATEGORY)
            ->get();

        return $impossible->map(fn (Product $product): OperationalException => new OperationalException(
            category: 'inventory',
            type: 'impossible_stock',
            severity: Severity::Critical,
            detail: "Stock projection cannot be true: {$product->stock_on_hand} on hand, "
                ."{$product->stock_reserved} reserved.",
            reference: $product->sku,
            url: route('admin.inventory', ['search' => $product->sku]),
            detectedAt: $product->updated_at,
            nextAction: 'Correct with an opposing adjustment through inventory. Never edit a stock column.',
        ))->all();
    }
}
