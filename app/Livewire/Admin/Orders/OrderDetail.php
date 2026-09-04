<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Orders;

use App\Domain\Orders\Services\OrderLifecycle;
use App\Enums\OrderStatus;
use App\Models\Order;
use DomainException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One order, in full, for staff.
 *
 * WHAT THIS SCREEN CANNOT DO, and why there is no form for any of it:
 *
 *   Mark an order paid.        There is no code path. `Paid` is reachable only
 *                              through a payment verified with the provider,
 *                              so a control here would have nothing to call.
 *   Edit an amount.            Frozen once paid, by the model and again by a
 *                              database trigger. A correction is a separate
 *                              financial act, not a rewrite of the original.
 *   Change the winning bid,
 *   the auction, or the
 *   customer.                  Same freeze, same reason.
 *
 * Offering a control that always failed would be worse than not offering one.
 *
 * What it can do is move a paid order along -- processing, then fulfilled --
 * which is ordinary operational work and is recorded like every other
 * transition.
 */
#[Layout('components.layouts.app')]
class OrderDetail extends Component
{
    public Order $order;

    public function mount(Order $order): void
    {
        $this->authorize('orders.view');

        $this->order = $order;
    }

    /**
     * Move a paid order forward.
     *
     * Only to `Processing` or `Fulfilled`; the lifecycle service refuses
     * anything else, `Paid` included.
     */
    public function advance(string $target, OrderLifecycle $orders): void
    {
        $this->authorize('orders.manage');

        $status = OrderStatus::tryFrom($target);

        if ($status === null) {
            $this->addError('lifecycle', 'That is not an order state.');

            return;
        }

        try {
            $orders->advance($this->order, $status, auth()->user());
        } catch (DomainException $e) {
            $this->addError('lifecycle', $e->getMessage());

            return;
        }

        $this->order->refresh();
    }

    public function render(): View
    {
        $this->order->refresh();

        return view('livewire.admin.orders.order-detail', [
            'pricing' => $this->order->pricing(),
            'item' => $this->order->item(),
            'payments' => $this->order->payments()->orderByDesc('id')->get(),
        ])->title('Order '.$this->order->order_number);
    }
}
