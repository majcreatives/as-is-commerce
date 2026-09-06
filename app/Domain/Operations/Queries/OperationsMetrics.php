<?php

declare(strict_types=1);

namespace App\Domain\Operations\Queries;

use App\Domain\Shared\Money\Money;
use App\Enums\AuctionStatus;
use App\Enums\DeliveryStatus;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Enums\ReferralStatus;
use App\Enums\RefundStatus;
use App\Models\Auction;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\Referral;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the platform looks like right now, for the people running it.
 *
 * READ ONLY, AND COUNTED FROM THE TABLES THAT OWN EACH FACT. Orders for order
 * counts, payments for payment counts, deliveries for what is in transit. There
 * is no metrics table, no cached counter and no nightly rollup, because a
 * counter that can drift is worse than a query that takes a moment -- an
 * operations screen exists precisely for the moments when somebody needs to
 * know what is actually true.
 *
 * EVERY FIGURE IS AN AGGREGATE. `COUNT` and `SUM` in the database, never a
 * collection loaded into PHP and counted there. A dashboard that loaded every
 * order to count them would fall over on the day it was most needed.
 *
 * NOTHING HERE DECIDES ANYTHING. It reports. Every action an administrator can
 * take from a screen built on this goes through the domain service that owns
 * the rule.
 */
class OperationsMetrics
{
    /**
     * Everything the dashboard shows, in one pass.
     *
     * Grouped counts rather than a query per status: eight statuses is eight
     * round trips done naively, and this is one.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return [
            'commerce' => $this->commerce(),
            'financial' => $this->financial(),
            'operations' => $this->operations(),
            'customers' => $this->customers(),
            'inventory' => $this->inventory(),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function commerce(): array
    {
        $orders = $this->countBy(Order::query(), 'status');
        $auctions = $this->countBy(Auction::query(), 'status');
        $products = $this->countBy(Product::query(), 'status');

        return [
            'products_active' => $products[ProductStatus::Active->value] ?? 0,
            'products_out_of_stock' => $products[ProductStatus::OutOfStock->value] ?? 0,
            'auctions_live' => ($auctions[AuctionStatus::Live->value] ?? 0)
                + ($auctions[AuctionStatus::Closing->value] ?? 0),
            'auctions_scheduled' => $auctions[AuctionStatus::Scheduled->value] ?? 0,
            // From the auction's own end time, which is what actually decides
            // when it closes -- never from anything a browser computed.
            'auctions_ending_soon' => Auction::query()
                ->whereIn('status', [AuctionStatus::Live, AuctionStatus::Closing])
                ->whereNotNull('ends_at')
                ->where('ends_at', '<=', Carbon::now()->addHours(6))
                ->count(),
            'orders_awaiting_payment' => $orders[OrderStatus::PendingPayment->value] ?? 0,
            'orders_paid' => $orders[OrderStatus::Paid->value] ?? 0,
            'orders_processing' => $orders[OrderStatus::Processing->value] ?? 0,
            'orders_fulfilled' => $orders[OrderStatus::Fulfilled->value] ?? 0,
            'orders_refunded' => $orders[OrderStatus::Refunded->value] ?? 0,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function financial(): array
    {
        $payments = $this->countBy(OrderPayment::query(), 'status');
        $refunds = $this->countBy(Refund::query(), 'status');

        return [
            'payments_successful' => $payments[OrderPaymentStatus::Success->value] ?? 0,
            'payments_pending' => ($payments[OrderPaymentStatus::Initiated->value] ?? 0)
                + ($payments[OrderPaymentStatus::Pending->value] ?? 0),
            'payments_failed' => $payments[OrderPaymentStatus::Failed->value] ?? 0,
            'refunds_pending' => $refunds[RefundStatus::Pending->value] ?? 0,
            'refunds_processing' => $refunds[RefundStatus::Processing->value] ?? 0,
            'refunds_succeeded' => $refunds[RefundStatus::Succeeded->value] ?? 0,
            'refunds_failed' => $refunds[RefundStatus::Failed->value] ?? 0,
            // Paid, undeliverable, and nobody has decided what is owed.
            'awaiting_recovery' => Order::query()
                ->blocked()
                ->whereDoesntHave('refunds', fn ($q) => $q->whereIn('status', [
                    RefundStatus::Pending,
                    RefundStatus::Processing,
                    RefundStatus::Succeeded,
                ]))
                ->count(),
        ];
    }

    /**
     * Money the platform has actually taken.
     *
     * Counted from the payment attempts that succeeded, not from order
     * statuses. An order's status is its current situation and moves as the
     * order is refunded, cancelled or fulfilled; a verified payment is a
     * historical fact that never moves. Counting statuses would quietly
     * subtract every refund from this figure while `refunded()` also reported
     * it -- netting the two under a label that claims not to.
     *
     * An outstanding obligation is not revenue: an attempt only counts once
     * the provider confirmed it.
     */
    public function collected(): Money
    {
        return Money::fromMinor(
            (int) OrderPayment::query()
                ->where('status', OrderPaymentStatus::Success)
                ->sum('amount_minor'),
        );
    }

    /**
     * Money given back, from the refunds that actually succeeded.
     *
     * Its own figure, standing beside `collected()` and never subtracted from
     * it. What was taken and what was returned are two facts, and one number
     * describing the difference would describe neither.
     */
    public function refunded(): Money
    {
        return Money::fromMinor(
            (int) Refund::query()->where('status', RefundStatus::Succeeded)->sum('amount_minor'),
        );
    }

    /**
     * @return array<string, int>
     */
    public function operations(): array
    {
        $deliveries = $this->countBy(Delivery::query(), 'status');

        return [
            'deliveries_pending' => $deliveries[DeliveryStatus::Pending->value] ?? 0,
            'deliveries_preparing' => $deliveries[DeliveryStatus::Preparing->value] ?? 0,
            'deliveries_ready' => $deliveries[DeliveryStatus::ReadyForDispatch->value] ?? 0,
            'deliveries_dispatched' => $deliveries[DeliveryStatus::Dispatched->value] ?? 0,
            'deliveries_out' => $deliveries[DeliveryStatus::OutForDelivery->value] ?? 0,
            'deliveries_delivered' => $deliveries[DeliveryStatus::Delivered->value] ?? 0,
            'deliveries_failed' => $deliveries[DeliveryStatus::DeliveryFailed->value] ?? 0,
            // Waiting on the customer rather than on the warehouse: an auction
            // winner's order is created while nobody is at a keyboard.
            'deliveries_awaiting_address' => Delivery::query()
                ->where('status', DeliveryStatus::Pending)
                ->whereNull('address_line')
                ->count(),
            'orders_blocked' => Order::query()->blocked()->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function customers(): array
    {
        $referrals = $this->countBy(Referral::query(), 'status');

        return [
            'customers_total' => User::query()->role('customer')->count(),
            'customers_recent' => User::query()
                ->role('customer')
                ->where('created_at', '>=', Carbon::now()->subDays(30))
                ->count(),
            'referrals_attributed' => $referrals[ReferralStatus::Attributed->value] ?? 0,
            'referrals_qualified' => $referrals[ReferralStatus::Qualified->value] ?? 0,
            'referrals_rewarded' => $referrals[ReferralStatus::Rewarded->value] ?? 0,
            // Counted from the reward snapshots, which are what was actually
            // granted -- never from wallet balances.
            'referral_credits_issued' => (int) Referral::query()
                ->where('status', ReferralStatus::Rewarded)
                ->sum('reward_credits'),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function inventory(): array
    {
        return [
            // Available stock, which is on hand less what is spoken for. A
            // product whose only unit an auction is holding counts here, and
            // that is correct: it cannot be bought outright right now.
            'products_no_available_stock' => Product::query()
                ->whereRaw('stock_on_hand - stock_reserved <= 0')
                ->whereIn('status', ProductStatus::publiclyVisibleCases())
                ->count(),
            'units_on_hand' => (int) Product::query()->sum('stock_on_hand'),
            'units_reserved' => (int) Product::query()->sum('stock_reserved'),
            // Should be impossible: reserved above on hand, or either below
            // zero. Checked because an invariant nobody verifies is a hope.
            'stock_anomalies' => Product::query()
                ->where(fn ($q) => $q->whereRaw('stock_reserved > stock_on_hand')
                    ->orWhere('stock_on_hand', '<', 0)
                    ->orWhere('stock_reserved', '<', 0))
                ->count(),
        ];
    }

    /**
     * Count rows grouped by one column, in a single query.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return array<string, int>
     */
    private function countBy($query, string $column): array
    {
        return $query
            ->select($column, DB::raw('COUNT(*) as aggregate'))
            ->groupBy($column)
            ->pluck('aggregate', $column)
            ->map(fn ($value): int => (int) $value)
            ->all();
    }
}
