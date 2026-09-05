<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Delivery\Exceptions\DeliveryNotAllowed;
use App\Domain\Delivery\Services\AddressBook;
use App\Enums\DeliveryStatus;
use App\Models\Address;
use App\Models\Delivery;
use App\Models\DeliveryTransition;
use App\Models\Order;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Creates the package record for an order that has actually been paid for.
 *
 * WHEN, AND WHY NOT EARLIER. A delivery is created at the moment a payment is
 * verified, and never before. Creating one when a checkout opened would fill
 * the warehouse queue with abandoned carts, expired checkouts and failed
 * payments -- work that does not exist, for orders nobody paid for. The
 * trigger is the same verified payment that turns a reservation into a sale.
 *
 * ONE ORDER, ONE PACKAGE. The unique index on `order_id` is the guarantee,
 * not a preceding SELECT: fulfilment is idempotent and a webhook may arrive
 * five times, so a duplicate is caught by the database rather than by a check
 * two concurrent deliveries could both pass.
 *
 * THE ADDRESS IS COPIED, NOT REFERENCED. From the one chosen at checkout, or
 * the customer's default if they never chose. If they have neither -- which is
 * ordinary for an auction winner, whose order is created by the closing sweep
 * while nobody is at a keyboard -- the delivery is created without one and
 * cannot leave `Pending` until it has one. That is the rule that stops a box
 * being packed for an address nobody supplied.
 *
 * IT MOVES NO MONEY AND NO STOCK. The unit was sold when the payment was
 * verified; this records that a physical thing now has to reach somebody.
 */
final class OpenDelivery
{
    public function __construct(
        private readonly AddressBook $addresses,
    ) {}

    /**
     * Open the delivery for a paid order.
     *
     * Called inside the fulfilment transaction with the order row locked.
     * Returns the existing delivery when there is one, rather than throwing:
     * a replayed webhook reaching this must be a no-op, not an error.
     */
    public function handle(Order $order): Delivery
    {
        $existing = Delivery::where('order_id', $order->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $address = $this->addressFor($order);

        try {
            return $this->create($order, $address);
        } catch (UniqueConstraintViolationException) {
            // Another delivery of the same webhook got there first. The
            // database recognised it, which is the point of the index.
            $delivery = Delivery::where('order_id', $order->id)->first();

            if ($delivery === null) {
                throw DeliveryNotAllowed::because(
                    'A delivery for this order exists but could not be read back.'
                );
            }

            return $delivery;
        }
    }

    /**
     * Which address this package is going to.
     *
     * The one chosen at checkout wins. Failing that, the customer's default --
     * useful precisely for the auction winner who never saw a checkout form.
     * Failing that, nothing, and the delivery waits.
     */
    private function addressFor(Order $order): ?Address
    {
        if ($order->delivery_address_id !== null) {
            $chosen = Address::find($order->delivery_address_id);

            // Ownership re-checked even here. It was checked at checkout, but
            // this is the copy that governs where a physical thing goes, and
            // one more check costs nothing.
            if ($chosen !== null && $chosen->user_id === $order->user_id) {
                return $chosen;
            }
        }

        return $this->addresses->defaultFor($order->user);
    }

    private function create(Order $order, ?Address $address): Delivery
    {
        $delivery = new Delivery;

        $delivery->order_id = $order->id;
        $delivery->user_id = $order->user_id;
        $delivery->reference = $this->generateReference();
        $delivery->status = DeliveryStatus::Pending;
        $delivery->source_address_id = $address?->id;

        if ($address !== null) {
            // A copy. From here on the address book and this package are
            // independent: editing one changes nothing about the other.
            foreach ($address->toSnapshot() as $field => $value) {
                $delivery->{$field} = $value;
            }
        }

        $delivery->save();

        DeliveryTransition::create([
            'delivery_id' => $delivery->id,
            'from_status' => null,
            'to_status' => DeliveryStatus::Pending,
            'reason_code' => null,
            'note' => $address === null
                ? 'Delivery opened. Awaiting a delivery address from the customer.'
                : 'Delivery opened for a verified payment.',
            'caused_by' => null,
        ]);

        Log::info('Delivery opened', [
            'operation' => 'delivery.opened',
            'delivery_id' => $delivery->id,
            'reference' => $delivery->reference,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'has_address' => $address !== null,
        ]);

        return $delivery;
    }

    /**
     * A reference that is unique, readable and unguessable.
     *
     * Ours, and only ours. It makes no claim to be verifiable anywhere else:
     * there is no courier integration, and a reference that looked like a
     * tracking number would invite a customer to try looking it up.
     *
     * Random rather than sequential, like an order number, so one reference
     * does not let anybody enumerate everybody else's packages.
     */
    private function generateReference(): string
    {
        return 'AIC-D-'.now()->format('Ymd').'-'.mb_strtoupper(Str::random(8));
    }
}
