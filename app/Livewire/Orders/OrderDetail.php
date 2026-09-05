<?php

declare(strict_types=1);

namespace App\Livewire\Orders;

use App\Enums\RefundStatus;
use App\Models\Order;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One of a customer's own orders.
 *
 * Read-only. There is nothing on this screen that changes an amount or a
 * status: a customer cannot alter what they were charged, and the only action
 * available anywhere is paying an outstanding checkout, which lives on the
 * checkout page.
 */
#[Layout('components.layouts.app')]
class OrderDetail extends Component
{
    public Order $order;

    public function mount(Order $order): void
    {
        $this->authorize('orders.view_own');

        // Ownership, not just authentication. An order number in a URL is not
        // a capability to view somebody else's purchase.
        abort_unless($order->user_id === auth()->id(), 404);

        $this->order = $order;
    }

    public function render(): View
    {
        $this->order->refresh();

        return view('livewire.orders.order-detail', [
            'pricing' => $this->order->pricing(),
            'item' => $this->order->item(),
            // The successful attempt, if there was one. Failed and abandoned
            // attempts are the customer's own history and are shown too.
            'payments' => $this->order->payments()->orderByDesc('id')->get(),
            // Money returned, if any has been. Shown as its own record rather
            // than folded into the payment, because what was paid and what
            // was given back are two different facts.
            //
            // Pending refunds are deliberately excluded: at that point the
            // provider has not been asked, and showing one would tell a
            // customer something is happening when nothing has yet.
            'refunds' => $this->order->refunds()
                ->whereIn('status', [RefundStatus::Processing, RefundStatus::Succeeded, RefundStatus::Failed])
                ->orderByDesc('id')
                ->get(),
        ])->title('Order '.$this->order->order_number);
    }
}
