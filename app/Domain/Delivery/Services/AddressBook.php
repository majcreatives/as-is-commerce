<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Services;

use App\Domain\Delivery\Exceptions\DeliveryNotAllowed;
use App\Domain\Shared\Phone\PhoneNumberNormalizer;
use App\Models\Address;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A customer's own addresses.
 *
 * OWNERSHIP IS ENFORCED HERE, NOT AT THE CALL SITES. Every method that takes
 * an address also takes the person it is supposed to belong to, and refuses
 * the pair if they do not match. An address id in a request is not a
 * capability: without this, somebody could send a stranger's address id at
 * checkout and have a package delivered to them.
 *
 * PHONES ARE NORMALIZED BEFORE STORAGE, to E.164, like every other number on
 * this platform. A rider dials what is written down, so "024 123 4567" and
 * "+233241234567" must not be two different things in the same system.
 *
 * EDITING AN ADDRESS CHANGES NOTHING THAT HAS SHIPPED. A delivery holds its
 * own copy, taken when it was created and frozen by a database trigger once
 * anybody starts handling the package. That is the whole reason the address
 * book and the delivery snapshot are separate tables: a customer who moves
 * house must not be able to redirect a package already in transit, and an old
 * order must still say where it actually went.
 */
class AddressBook
{
    public function __construct(
        private readonly PhoneNumberNormalizer $phones,
    ) {}

    /**
     * Add an address to somebody's book.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws DeliveryNotAllowed
     */
    public function create(User $owner, array $attributes, bool $makeDefault = false): Address
    {
        return DB::transaction(function () use ($owner, $attributes, $makeDefault): Address {
            $address = new Address;

            // Set here, from the authenticated owner, never from the payload.
            $address->user_id = $owner->id;

            $this->fill($address, $attributes);
            $address->save();

            // The first address a customer saves is their default, whether
            // they asked for that or not. Somebody with exactly one address
            // and no default would be shown an empty selection at checkout.
            if ($makeDefault || $owner->addresses()->count() === 1) {
                $this->makeDefault($owner, $address);
            }

            return $address->fresh();
        });
    }

    /**
     * Change an address the customer still owns.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws DeliveryNotAllowed
     */
    public function update(User $owner, Address $address, array $attributes): Address
    {
        $this->assertOwnedBy($owner, $address);

        $this->fill($address, $attributes);
        $address->save();

        return $address->fresh();
    }

    /**
     * Remove an address.
     *
     * Safe at any time. Orders point at it with a nullable reference and
     * deliveries hold their own copy, so removing an address from a book
     * cannot orphan a package or change where one went.
     *
     * @throws DeliveryNotAllowed
     */
    public function delete(User $owner, Address $address): void
    {
        $this->assertOwnedBy($owner, $address);

        $wasDefault = $address->is_default;

        DB::transaction(function () use ($owner, $address, $wasDefault): void {
            $address->delete();

            if (! $wasDefault) {
                return;
            }

            // Somebody has to be the default, or the next checkout has nothing
            // pre-selected.
            $next = $owner->addresses()->orderBy('id')->first();

            if ($next !== null) {
                $this->makeDefault($owner, $next);
            }
        });
    }

    /**
     * Make one address the default, and the others not.
     *
     * One statement per side rather than a loop: two concurrent requests
     * cannot leave a customer with two defaults, because the clearing update
     * and the setting update are in the same transaction.
     *
     * @throws DeliveryNotAllowed
     */
    public function makeDefault(User $owner, Address $address): Address
    {
        $this->assertOwnedBy($owner, $address);

        DB::transaction(function () use ($owner, $address): void {
            Address::query()
                ->where('user_id', $owner->id)
                ->whereKeyNot($address->getKey())
                ->update(['is_default' => false]);

            $address->is_default = true;
            $address->save();
        });

        return $address->fresh();
    }

    /**
     * The address a checkout should start from.
     *
     * The default, or the only one, or nothing at all -- a customer who has
     * never saved an address is a normal state, not an error. Auction winners
     * in particular are given an order by the closing sweep without ever
     * seeing a form.
     */
    public function defaultFor(User $owner): ?Address
    {
        return $owner->addresses()->where('is_default', true)->first()
            ?? $owner->addresses()->orderBy('id')->first();
    }

    /**
     * Resolve an address id supplied by a browser.
     *
     * The one place a request-supplied id becomes an address, and it refuses
     * anything the signed-in customer does not own.
     *
     * @throws DeliveryNotAllowed
     */
    public function resolveOwned(User $owner, int $addressId): Address
    {
        $address = Address::query()
            ->where('user_id', $owner->id)
            ->whereKey($addressId)
            ->first();

        if ($address === null) {
            throw DeliveryNotAllowed::notOwned();
        }

        return $address;
    }

    /**
     * @throws DeliveryNotAllowed
     */
    public function assertOwnedBy(User $owner, Address $address): void
    {
        if ($address->user_id !== $owner->id) {
            throw DeliveryNotAllowed::notOwned();
        }
    }

    /**
     * Write the supplied fields, normalizing what needs it.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws DeliveryNotAllowed
     */
    private function fill(Address $address, array $attributes): void
    {
        $phone = isset($attributes['recipient_phone'])
            ? $this->phones->normalize((string) $attributes['recipient_phone'])
            : null;

        if ($phone === null && ! $address->exists) {
            throw DeliveryNotAllowed::because(
                'A delivery needs a phone number somebody can actually be reached on.'
            );
        }

        $address->label = $this->trimmed($attributes['label'] ?? $address->label, 60);
        $address->recipient_name = (string) $this->trimmed(
            $attributes['recipient_name'] ?? $address->recipient_name, 120,
        );
        $address->recipient_phone = $phone ?? $address->recipient_phone;
        $address->address_line = (string) $this->trimmed(
            $attributes['address_line'] ?? $address->address_line, 200,
        );
        $address->area = $this->trimmed($attributes['area'] ?? $address->area, 120);
        $address->city = (string) $this->trimmed($attributes['city'] ?? $address->city, 120);
        $address->region = $this->trimmed($attributes['region'] ?? $address->region, 120);
        $address->digital_address = $this->trimmed(
            $attributes['digital_address'] ?? $address->digital_address, 20,
        );
        $address->landmark = $this->trimmed($attributes['landmark'] ?? $address->landmark, 200);
        $address->instructions = $this->trimmed(
            $attributes['instructions'] ?? $address->instructions, 500,
        );
    }

    private function trimmed(mixed $value, int $length): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = mb_substr(trim($value), 0, $length);

        return $trimmed === '' ? null : $trimmed;
    }
}
