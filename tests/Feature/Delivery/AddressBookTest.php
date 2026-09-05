<?php

declare(strict_types=1);

use App\Domain\Delivery\Exceptions\DeliveryNotAllowed;
use App\Domain\Delivery\Services\AddressBook;
use App\Domain\Delivery\Services\DeliveryLifecycle;
use App\Enums\DeliveryStatus;
use App\Livewire\Delivery\AddressBookPage;
use App\Models\Address;
use App\Models\User;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

/*
 * A customer's own addresses, and the line between the book and the package.
 *
 * The rule everything here protects: editing an address changes where the next
 * order goes and nothing that has already shipped.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);

    $this->book = app(AddressBook::class);
});

/**
 * A valid set of address fields.
 *
 * @return array<string, string>
 */
function addressFields(array $overrides = []): array
{
    return array_replace([
        'label' => 'Home',
        'recipient_name' => 'Akosua Darko',
        'recipient_phone' => '024 123 4567',
        'address_line' => '14 Boundary Road',
        'area' => 'East Legon',
        'city' => 'Accra',
        'region' => 'Greater Accra',
    ], $overrides);
}

// ------------------------------------------------------------ Managing

it('lets a customer save an address', function (): void {
    $customer = userWithRole('customer');

    $address = $this->book->create($customer, addressFields());

    expect($address->user_id)->toBe($customer->id)
        ->and($address->city)->toBe('Accra')
        // Normalized to E.164 on the way in, so a rider dials one canonical
        // form whatever the customer typed.
        ->and($address->recipient_phone)->toBe('+233241234567');
});

it('makes a customer first address their default', function (): void {
    $customer = userWithRole('customer');

    $first = $this->book->create($customer, addressFields());
    $second = $this->book->create($customer, addressFields(['label' => 'Work']));

    expect($first->fresh()->is_default)->toBeTrue()
        ->and($second->is_default)->toBeFalse();
});

it('keeps exactly one default', function (): void {
    $customer = userWithRole('customer');

    $first = $this->book->create($customer, addressFields());
    $second = $this->book->create($customer, addressFields(['label' => 'Work']));

    $this->book->makeDefault($customer, $second);

    expect($customer->addresses()->where('is_default', true)->count())->toBe(1)
        ->and($second->fresh()->is_default)->toBeTrue()
        ->and($first->fresh()->is_default)->toBeFalse();
});

it('lets a customer edit their own address', function (): void {
    $customer = userWithRole('customer');
    $address = $this->book->create($customer, addressFields());

    $updated = $this->book->update($customer, $address, ['city' => 'Kumasi']);

    expect($updated->city)->toBe('Kumasi');
});

it('promotes another address when the default is deleted', function (): void {
    $customer = userWithRole('customer');

    $first = $this->book->create($customer, addressFields());
    $second = $this->book->create($customer, addressFields(['label' => 'Work']));

    $this->book->delete($customer, $first->fresh());

    expect($customer->addresses()->count())->toBe(1)
        ->and($second->fresh()->is_default)->toBeTrue();
});

it('requires a phone number somebody can be reached on', function (): void {
    $customer = userWithRole('customer');

    expect(fn (): Address => $this->book->create($customer, addressFields(['recipient_phone' => 'nonsense'])))
        ->toThrow(DeliveryNotAllowed::class, 'reached on');
});

// -------------------------------------------------------------- Ownership

/*
 * An address decides where a physical object is sent. An id in a request is
 * emphatically not a capability to use one.
 */
it('refuses to let a customer use another customer address', function (): void {
    $mine = userWithRole('customer');
    $theirs = userWithRole('customer');

    $theirAddress = $this->book->create($theirs, addressFields());

    expect(fn (): Address => $this->book->resolveOwned($mine, $theirAddress->id))
        ->toThrow(DeliveryNotAllowed::class, 'does not belong');
});

it('refuses to let a customer edit another customer address', function (): void {
    $mine = userWithRole('customer');
    $theirs = userWithRole('customer');

    $theirAddress = $this->book->create($theirs, addressFields());

    expect(fn (): Address => $this->book->update($mine, $theirAddress, ['city' => 'Elsewhere']))
        ->toThrow(DeliveryNotAllowed::class);

    expect($theirAddress->fresh()->city)->toBe('Accra');
});

it('refuses to let a customer delete another customer address', function (): void {
    $mine = userWithRole('customer');
    $theirs = userWithRole('customer');

    $theirAddress = $this->book->create($theirs, addressFields());

    expect(fn () => $this->book->delete($mine, $theirAddress))
        ->toThrow(DeliveryNotAllowed::class);

    expect(Address::whereKey($theirAddress->id)->exists())->toBeTrue();
});

it('does not list another customer addresses', function (): void {
    $mine = userWithRole('customer');
    $theirs = userWithRole('customer');

    $this->book->create($mine, addressFields(['label' => 'Mine to see']));
    $this->book->create($theirs, addressFields(['label' => 'Theirs to see']));

    Livewire::actingAs($mine)
        ->test(AddressBookPage::class)
        ->assertSee('Mine to see')
        ->assertDontSee('Theirs to see');
});

it('cannot load another customer address into the edit form', function (): void {
    $mine = userWithRole('customer');
    $theirs = userWithRole('customer');

    $theirAddress = $this->book->create($theirs, addressFields(['recipient_name' => 'Kwame Mensah']));

    Livewire::actingAs($mine)
        ->test(AddressBookPage::class)
        ->call('startEditing', $theirAddress->id)
        ->assertSet('editingId', null)
        ->assertDontSee('Kwame Mensah');
});

// ------------------------------------------------------------- The snapshot

/*
 * The rule the whole two-table design exists for.
 */
it('does not change a delivery when the address book is edited', function (): void {
    $customer = userWithRole('customer');
    $address = $this->book->create($customer, addressFields());

    $order = paidOrderFor($customer, $address);
    $delivery = $order->delivery;

    expect($delivery->address_line)->toBe('14 Boundary Road')
        ->and($delivery->city)->toBe('Accra');

    // The customer moves house.
    $this->book->update($customer, $address->fresh(), [
        'address_line' => '9 Completely Different Street',
        'city' => 'Kumasi',
    ]);

    // The package is still going where it was going.
    expect($delivery->fresh()->address_line)->toBe('14 Boundary Road')
        ->and($delivery->fresh()->city)->toBe('Accra');
});

it('leaves a delivery intact when its address is deleted', function (): void {
    $customer = userWithRole('customer');
    $address = $this->book->create($customer, addressFields());

    $order = paidOrderFor($customer, $address);
    $delivery = $order->delivery;

    $this->book->delete($customer, $address->fresh());

    expect($delivery->fresh()->address_line)->toBe('14 Boundary Road')
        // The pointer goes; the copy stays.
        ->and($delivery->fresh()->source_address_id)->toBeNull()
        ->and($order->fresh()->delivery_address_id)->toBeNull();
});

/*
 * Before anybody has touched the package the address may still be corrected --
 * an auction winner has to be able to say where their prize goes. The moment
 * work starts it is frozen, by a trigger as well as by the service.
 */
it('freezes the delivery address once the package is being handled', function (): void {
    $customer = userWithRole('customer');
    $address = $this->book->create($customer, addressFields());

    $order = paidOrderFor($customer, $address);
    $delivery = $order->delivery;

    // While pending, a correction is fine.
    $delivery->address_line = '15 Boundary Road';
    $delivery->save();

    expect($delivery->fresh()->address_line)->toBe('15 Boundary Road');

    app(DeliveryLifecycle::class)
        ->prepare($delivery->fresh(), userWithRole('admin'));

    $handled = $delivery->fresh();

    expect($handled->status)->toBe(DeliveryStatus::Preparing);

    $handled->address_line = 'Somewhere else entirely';

    expect(fn () => $handled->save())
        ->toThrow(QueryException::class);
});

// ------------------------------------------------------------ Authorization

it('keeps a customer address book behind authentication', function (): void {
    $this->get(route('addresses.index'))->assertRedirect(route('login'));
});

it('grants every customer the ability to manage their own addresses', function (): void {
    expect(userWithRole('customer')->can('addresses.manage'))->toBeTrue();
});

it('grants a customer no delivery permissions', function (): void {
    $customer = userWithRole('customer');

    foreach ([
        'deliveries.view', 'deliveries.update', 'deliveries.dispatch',
        'deliveries.complete', 'deliveries.retry', 'deliveries.cancel',
    ] as $permission) {
        expect($customer->can($permission))->toBeFalse("A customer must not hold {$permission}.");
    }
});

it('lets a customer save an address through the screen', function (): void {
    $customer = userWithRole('customer');

    Livewire::actingAs($customer)
        ->test(AddressBookPage::class)
        ->call('startAdding')
        ->set('form.recipient_name', 'Ama Boateng')
        ->set('form.recipient_phone', '0244000111')
        ->set('form.address_line', '3 Ridge Road')
        ->set('form.city', 'Accra')
        ->call('save')
        ->assertHasNoErrors();

    expect($customer->addresses()->count())->toBe(1)
        ->and($customer->addresses()->first()->recipient_phone)->toBe('+233244000111');
});

it('will not save an address with nothing to deliver to', function (): void {
    $customer = userWithRole('customer');

    Livewire::actingAs($customer)
        ->test(AddressBookPage::class)
        ->call('startAdding')
        ->set('form.recipient_name', '')
        ->set('form.address_line', '')
        ->call('save')
        ->assertHasErrors(['form.recipient_name', 'form.address_line', 'form.city']);

    expect(User::find($customer->id)->addresses()->count())->toBe(0);
});
