<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Orders\Services\OrderLifecycle;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Close checkouts whose payment window has passed.
 *
 * WHY THIS EXISTS. A Buy Now checkout on a catalog product holds a unit of
 * stock aside so it cannot be sold from under a customer who is paying for it.
 * Without something to notice that they never paid, that hold would last
 * forever and one abandoned checkout would take a product off sale
 * permanently. This is that something, and it is why the hold has a deadline
 * at all.
 *
 * Server time decides, from `payment_due_at`, which was written when the
 * checkout was opened. No browser has any say in whether a customer may still
 * pay.
 *
 * Idempotent throughout: each order is re-read under a row lock and left alone
 * if it has since been paid or cancelled, so overlapping runs expire it once.
 * A missed run delays a release; it never expires something that was paid.
 *
 * One order failing must not strand the others -- a single problem checkout
 * should not keep every other held unit off sale.
 */
class ExpireCheckouts extends Command
{
    protected $signature = 'orders:expire-checkouts {--limit=200 : Most checkouts to close in one pass}';

    protected $description = 'Close unpaid checkouts whose payment window has passed and release what they held';

    public function handle(OrderLifecycle $orders): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $now = Carbon::now();

        $expired = 0;

        foreach (Order::query()->dueToExpire()->limit($limit)->get() as $order) {
            try {
                $result = $orders->expire($order, $now);

                if (! $result->status->acceptsPayment()) {
                    $expired++;
                }
            } catch (Throwable $e) {
                // Logged and skipped. A checkout that cannot be released must
                // not stop every other held unit going back on sale.
                Log::error('Checkout expiry failed', [
                    'operation' => 'orders.expire',
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                $this->warn("Order {$order->order_number} could not be expired: {$e->getMessage()}");
            }
        }

        $this->info("Expired {$expired} checkout(s).");

        Cache::put('sweeps:expire_checkouts:last_run', Carbon::now());

        return self::SUCCESS;
    }
}
