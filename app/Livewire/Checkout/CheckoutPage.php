<?php

declare(strict_types=1);

namespace App\Livewire\Checkout;

use App\Domain\Orders\Actions\InitializeOrderPayment;
use App\Domain\Orders\Exceptions\PaymentNotAcceptable;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Domain\Payments\Exceptions\PaymentGatewayError;
use App\Models\Order;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The page a customer reviews before paying.
 *
 * EVERY FIGURE HERE WAS FROZEN AT CHECKOUT. Nothing is recalculated on render:
 * the order carries its own subtotal, discount, delivery, tax and total, and
 * this page displays them. If the product were repriced between opening this
 * checkout and paying for it, the customer still pays what they were quoted.
 *
 * THE BROWSER SENDS NO AMOUNT. There is no field, hidden or otherwise, that
 * carries a price. Paying submits nothing but an intent; the server reads the
 * total off the order and asks the provider for exactly that.
 *
 * The components are shown separately -- what the goods cost, what the credit
 * discount took off, delivery, tax -- because a customer disputing a total
 * needs to see which part they are disputing.
 */
#[Layout('components.layouts.app')]
class CheckoutPage extends Component
{
    public Order $order;

    public function mount(Order $order): void
    {
        $this->authorize('checkout.create');

        // Ownership, not just authentication. A checkout is not viewable by
        // pasting somebody else's order number into the URL.
        abort_unless($order->user_id === auth()->id(), 404);

        $this->order = $order;
    }

    /**
     * Open a payment with the provider and send the customer to it.
     *
     * The amount is never passed from here. `InitializeOrderPayment` takes an
     * order and reads the total from it, so there is no parameter through
     * which a browser-supplied price could enter.
     */
    public function pay(InitializeOrderPayment $initialize): ?RedirectResponse
    {
        $this->authorize('checkout.create');

        abort_unless($this->order->user_id === auth()->id(), 404);

        try {
            $payment = $initialize->handle($this->order);
        } catch (PaymentNotAcceptable $e) {
            $this->addError('payment', $e->getMessage());
            $this->order->refresh();

            return null;
        } catch (PaymentGatewayError) {
            // The provider is unreachable or refused to open the transaction.
            // Nothing has been charged and the checkout is untouched.
            $this->addError('payment', 'We could not reach the payment provider. Please try again.');

            return null;
        }

        if ($payment->authorization_url === null) {
            $this->addError('payment', 'The payment provider did not return a payment page.');

            return null;
        }

        // Away to Paystack. Coming back from there proves nothing on its own:
        // the callback verifies with the provider before saying anything.
        return redirect()->away($payment->authorization_url);
    }

    /**
     * Abandon this checkout, releasing anything it was holding.
     */
    public function cancel(OrderLifecycle $orders): ?RedirectResponse
    {
        abort_unless($this->order->user_id === auth()->id(), 404);

        try {
            $orders->cancel($this->order, 'Cancelled by the customer.', auth()->user());
        } catch (DomainException $e) {
            $this->addError('payment', $e->getMessage());

            return null;
        }

        return redirect()->route('orders.index');
    }

    public function render(): View
    {
        $this->order->refresh();

        return view('livewire.checkout.checkout-page', [
            'pricing' => $this->order->pricing(),
            'item' => $this->order->item(),
        ])->title('Checkout '.$this->order->order_number);
    }
}
