<?php

declare(strict_types=1);

namespace App\Livewire\Checkout;

use App\Domain\Delivery\Exceptions\DeliveryNotAllowed;
use App\Domain\Delivery\Services\AddressBook;
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

    /**
     * Where this order should be delivered.
     *
     * An id from the browser, and treated as such: it is resolved through the
     * signed-in customer's own addresses, so one belonging to somebody else
     * finds nothing. Without that check, a request could have a package
     * delivered to a stranger.
     */
    public ?int $deliveryAddressId = null;

    public function mount(Order $order, AddressBook $addresses): void
    {
        $this->authorize('checkout.create');

        // Ownership, not just authentication. A checkout is not viewable by
        // pasting somebody else's order number into the URL.
        abort_unless($order->user_id === auth()->id(), 404);

        $this->order = $order;

        // Pre-selected from what the customer already has, so the common case
        // takes no clicks. Still re-checked on the server when it is used.
        $this->deliveryAddressId = $order->delivery_address_id
            ?? $addresses->defaultFor($order->user)?->id;
    }

    /**
     * Remember where this order is going.
     *
     * Only a pointer is stored. The copy that governs where a physical thing
     * actually goes is taken when the delivery is created, at the moment a
     * payment is verified -- so a customer editing this address afterwards
     * changes their address book and nothing that has shipped.
     *
     * Deliberately not required before paying. Payment and delivery are
     * separate concerns, and an auction winner's order is created by the
     * closing sweep without anybody choosing anything; the address is captured
     * before the package can be prepared instead, which is where it actually
     * matters.
     */
    public function chooseAddress(AddressBook $addresses): void
    {
        $this->authorize('checkout.create');

        abort_unless($this->order->user_id === auth()->id(), 404);

        if ($this->deliveryAddressId === null) {
            $this->addError('address', 'Choose where this should be delivered.');

            return;
        }

        try {
            $address = $addresses->resolveOwned(auth()->user(), $this->deliveryAddressId);
        } catch (DeliveryNotAllowed $e) {
            $this->addError('address', $e->getMessage());

            return;
        }

        $this->order->delivery_address_id = $address->id;
        $this->order->save();
        $this->order->refresh();

        session()->flash('checkout-address', 'We will deliver to '.$address->displayLabel().'.');
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

        // A Shop order's lines return to the cart it came from, so the customer
        // picks the basket back up on the cart page. Auction-linked orders have
        // no basket to return to and stay in the order history.
        return redirect(
            $this->order->isShopOrder()
                ? route('cart.show')
                : route('orders.index')
        );
    }

    public function render(): View|RedirectResponse
    {
        $this->order->refresh();

        // The win is described in the words of the model the auction was frozen
        // with, so the auction has to be loaded rather than read lazily.
        $this->order->loadMissing('auction');

        $this->guardPayable();

        return view('livewire.checkout.checkout-page', [
            'addresses' => auth()->user()->addresses()->get(),
            'pricing' => $this->order->pricing(),
            'items' => $this->order->items()->with('order')->get(),
        ])->title('Checkout '.$this->order->order_number);
    }

    /**
     * A checkout nobody can pay for does not belong on this page.
     *
     * A Shop order that died unpaid -- cancelled, expired, or the provider
     * reported a failed charge -- has already had its lines restored to the
     * customer's cart, so the customer is guided back to the basket to place
     * it again. A paid order is finished and lives with the order records;
     * so does any auction-linked checkout, which never returns to a cart.
     */
    private function guardPayable(): void
    {
        if ($this->order->isPayable()) {
            return;
        }

        if ($this->order->isPaid()) {
            abort(redirect()->route('orders.show', $this->order));
        }

        abort(redirect(
            $this->order->isShopOrder()
                ? route('cart.show')
                : route('orders.show', $this->order)
        ));
    }
}
