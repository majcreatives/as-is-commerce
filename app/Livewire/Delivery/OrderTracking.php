<?php

declare(strict_types=1);

namespace App\Livewire\Delivery;

use App\Domain\Delivery\Exceptions\DeliveryNotAllowed;
use App\Domain\Delivery\Services\AddressBook;
use App\Enums\DeliveryStatus;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Where a customer's package has got to.
 *
 * ONLY WHAT HAPPENED. The timeline is built from the delivery's own recorded
 * timestamps, so a step appears complete because somebody recorded doing it
 * and for no other reason. A future step is never shown as done, and never
 * given a date -- a tracking page that guesses is worse than one that says
 * little.
 *
 * NOTHING INTERNAL LEAKS. Staff notes, the carrier's internal reference, the
 * raw failure code and the audit trail all stay on the staff screen. What the
 * customer sees is the state, when it happened, where it is going, and -- if
 * an attempt failed -- a neutral description that does not accuse them of
 * anything.
 *
 * THE ADDRESS CAN STILL BE SUPPLIED HERE, but only while nobody has touched
 * the package. That is the auction winner's path: their order was created by
 * the closing sweep while they were asleep, so they were never asked where it
 * should go. Once the warehouse starts work the address is frozen, by a
 * database trigger as well as by this screen.
 */
#[Layout('components.layouts.app')]
class OrderTracking extends Component
{
    public Order $order;

    public ?int $selectedAddressId = null;

    public function mount(Order $order): void
    {
        $this->authorize('orders.view_own');

        // Ownership, not just authentication. An order number in a URL is not
        // a capability to watch somebody else's delivery.
        abort_unless($order->user_id === auth()->id(), 404);

        $this->order = $order;

        // The tracking screen reads the delivery itself and the order's item
        // snapshot, and the "choose address" action touches the delivery too.
        // Loaded up front so neither the mount nor a later render reads either
        // relation lazily under strict lazy-loading.
        $this->order->loadMissing(['delivery', 'items']);
    }

    /**
     * Tell us where to send it.
     *
     * Only while the delivery is still pending. The service refuses an address
     * the customer does not own, and the database refuses the write once the
     * package is being handled.
     */
    public function chooseAddress(AddressBook $addresses): void
    {
        $this->authorize('orders.view_own');

        $delivery = $this->order->delivery;

        if ($delivery === null || ! $delivery->addressIsEditable()) {
            $this->addError('address', 'This delivery is already being prepared, so its address is fixed.');

            return;
        }

        if ($this->selectedAddressId === null) {
            $this->addError('address', 'Choose an address.');

            return;
        }

        try {
            $address = $addresses->resolveOwned(auth()->user(), $this->selectedAddressId);
        } catch (DeliveryNotAllowed $e) {
            $this->addError('address', $e->getMessage());

            return;
        }

        // A copy, not a reference: from here the address book and this package
        // are independent.
        foreach ($address->toSnapshot() as $field => $value) {
            $delivery->{$field} = $value;
        }

        $delivery->source_address_id = $address->id;
        $delivery->save();

        // The order remembers the choice too, so a support conversation can
        // see which book entry it came from.
        $this->order->delivery_address_id = $address->id;
        $this->order->save();

        $this->order->refresh();

        session()->flash('tracking', 'Thank you. We will send it there.');
    }

    public function render(): View
    {
        $this->order->refresh();
        $this->order->loadMissing(['delivery', 'items']);

        $delivery = $this->order->delivery;

        return view('livewire.delivery.order-tracking', [
            'delivery' => $delivery,
            'steps' => $this->steps(),
            'addresses' => auth()->user()->addresses()->get(),
        ])->title('Tracking '.$this->order->order_number);
    }

    /**
     * The timeline, with only the steps that actually happened marked done.
     *
     * @return list<array{status: DeliveryStatus, reached: bool, at: Carbon|null}>
     */
    private function steps(): array
    {
        $delivery = $this->order->delivery;

        if ($delivery === null) {
            return [];
        }

        $times = $delivery->stepTimestamps();

        return array_map(fn (DeliveryStatus $status): array => [
            'status' => $status,
            // A step counts as reached only if it has a time. Deriving it from
            // the current status instead would mark "out for delivery" done on
            // a package that went straight from dispatched to delivered --
            // claiming something happened that never did.
            'reached' => ($times[$status->value] ?? null) !== null,
            'at' => $times[$status->value] ?? null,
        ], DeliveryStatus::trackingSteps());
    }
}
