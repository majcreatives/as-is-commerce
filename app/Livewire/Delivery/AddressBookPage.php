<?php

declare(strict_types=1);

namespace App\Livewire\Delivery;

use App\Domain\Delivery\Exceptions\DeliveryNotAllowed;
use App\Domain\Delivery\Services\AddressBook;
use App\Models\Address;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * A customer's own addresses.
 *
 * SCOPED IN THE QUERY, NOT AFTER IT. Every read starts from the signed-in
 * user's own relation and every write goes through the address book service,
 * which refuses an address the caller does not own. There is no path by which
 * somebody else's address could be listed, edited or deleted -- and that
 * matters more here than on most screens, because an address is where a
 * physical object gets sent.
 *
 * EDITING CHANGES NOTHING THAT HAS SHIPPED. A delivery holds its own frozen
 * copy, so a customer who moves house updates where their next package goes
 * and nothing about the ones already out. The page says so, because somebody
 * correcting a typo has a reasonable right to wonder.
 */
#[Layout('components.layouts.app')]
#[Title('Delivery addresses')]
class AddressBookPage extends Component
{
    /** The address being edited, or null when adding a new one. */
    public ?int $editingId = null;

    public bool $showingForm = false;

    /** @var array<string, string> */
    public array $form = [
        'label' => '',
        'recipient_name' => '',
        'recipient_phone' => '',
        'address_line' => '',
        'area' => '',
        'city' => '',
        'region' => '',
        'digital_address' => '',
        'landmark' => '',
        'instructions' => '',
    ];

    public function mount(): void
    {
        $this->authorize('addresses.manage');
    }

    public function startAdding(): void
    {
        $this->authorize('addresses.manage');

        $this->reset('form', 'editingId');
        $this->resetErrorBag();
        $this->showingForm = true;
    }

    public function startEditing(int $addressId): void
    {
        $this->authorize('addresses.manage');

        // Resolved through the owner, so an id belonging to somebody else
        // simply finds nothing rather than loading their address into a form.
        $address = auth()->user()->addresses()->whereKey($addressId)->first();

        if ($address === null) {
            return;
        }

        $this->editingId = $address->id;
        $this->form = [
            'label' => (string) $address->label,
            'recipient_name' => $address->recipient_name,
            'recipient_phone' => $address->recipient_phone,
            'address_line' => $address->address_line,
            'area' => (string) $address->area,
            'city' => $address->city,
            'region' => (string) $address->region,
            'digital_address' => (string) $address->digital_address,
            'landmark' => (string) $address->landmark,
            'instructions' => (string) $address->instructions,
        ];
        $this->resetErrorBag();
        $this->showingForm = true;
    }

    public function cancel(): void
    {
        $this->reset('form', 'editingId');
        $this->showingForm = false;
    }

    public function save(AddressBook $addresses): void
    {
        $this->authorize('addresses.manage');

        $this->validate([
            'form.recipient_name' => ['required', 'string', 'max:120'],
            'form.recipient_phone' => ['required', 'string', 'max:20'],
            'form.address_line' => ['required', 'string', 'max:200'],
            'form.city' => ['required', 'string', 'max:120'],
            'form.label' => ['nullable', 'string', 'max:60'],
            'form.area' => ['nullable', 'string', 'max:120'],
            'form.region' => ['nullable', 'string', 'max:120'],
            'form.digital_address' => ['nullable', 'string', 'max:20'],
            'form.landmark' => ['nullable', 'string', 'max:200'],
            'form.instructions' => ['nullable', 'string', 'max:500'],
        ], attributes: [
            'form.recipient_name' => 'recipient name',
            'form.recipient_phone' => 'phone number',
            'form.address_line' => 'address',
            'form.city' => 'city or town',
        ]);

        $user = auth()->user();

        try {
            if ($this->editingId === null) {
                $addresses->create($user, $this->form);
            } else {
                $existing = $user->addresses()->whereKey($this->editingId)->firstOrFail();
                $addresses->update($user, $existing, $this->form);
            }
        } catch (DeliveryNotAllowed $e) {
            $this->addError('form.recipient_phone', $e->getMessage());

            return;
        }

        $this->cancel();
        session()->flash('addresses', 'Your addresses were saved.');
    }

    public function makeDefault(int $addressId, AddressBook $addresses): void
    {
        $this->authorize('addresses.manage');

        $address = auth()->user()->addresses()->whereKey($addressId)->first();

        if ($address !== null) {
            $addresses->makeDefault(auth()->user(), $address);
        }
    }

    public function delete(int $addressId, AddressBook $addresses): void
    {
        $this->authorize('addresses.manage');

        $address = auth()->user()->addresses()->whereKey($addressId)->first();

        if ($address !== null) {
            $addresses->delete(auth()->user(), $address);
        }
    }

    public function render(): View
    {
        return view('livewire.delivery.address-book-page', [
            /** @var list<Address> */
            'addresses' => auth()->user()->addresses()->get(),
        ]);
    }
}
