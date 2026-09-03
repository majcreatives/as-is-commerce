<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Payments;

use App\Domain\Shared\Money\Money;
use App\Models\CreditPackage;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Define what customers can buy.
 *
 * Packages are ordinary configuration and stay editable, because a purchase
 * never reads them at fulfilment time -- it carries its own snapshot. Changing
 * a price here affects what the next customer pays, never what a previous one
 * bought.
 *
 * Activation is separate from editing so a package cannot go on sale by
 * accident while it is being drafted.
 */
#[Layout('components.layouts.app')]
#[Title('Credit packages')]
class PackageManager extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    /** Entered in cedis and converted to minor units on save. Never a float. */
    public string $price = '';

    public int $credit_amount = 0;

    public int $sort_order = 0;

    public bool $showForm = false;

    public function mount(): void
    {
        $this->authorize('credit_packages.view');
    }

    public function create(): void
    {
        $this->authorize('credit_packages.create');

        $this->reset('editingId', 'name', 'description', 'price', 'credit_amount', 'sort_order');
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('credit_packages.update');

        $package = CreditPackage::findOrFail($id);

        $this->editingId = $package->id;
        $this->name = $package->name;
        $this->description = $package->description ?? '';
        $this->price = $package->price()->toDecimalString();
        $this->credit_amount = $package->credit_amount;
        $this->sort_order = $package->sort_order;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize($this->editingId === null ? 'credit_packages.create' : 'credit_packages.update');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'credit_amount' => ['required', 'integer', 'min:1', 'max:10000000'],
            // A decimal string, validated by shape, so nothing passes through
            // a float on its way to the database.
            'price' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ], [
            'price.regex' => 'Enter an amount such as 45 or 45.00, with no currency symbol.',
            'credit_amount.min' => 'A package must contain at least one credit.',
        ]);

        $currency = settings()->getString('currency', 'GHS') ?? 'GHS';
        $priceMinor = Money::fromDecimalString($validated['price'], $currency)->minor;

        if ($priceMinor <= 0) {
            $this->addError('price', 'A package must cost more than zero.');

            return;
        }

        $package = $this->editingId === null
            ? new CreditPackage
            : CreditPackage::findOrFail($this->editingId);

        $package->fill([
            'name' => $validated['name'],
            'description' => $validated['description'] !== '' ? $validated['description'] : null,
            'credit_amount' => $validated['credit_amount'],
            'price_minor' => $priceMinor,
            'currency' => $currency,
            'sort_order' => $validated['sort_order'],
        ]);

        if (! $package->exists) {
            $package->slug = $this->uniqueSlug($validated['name']);
            $package->created_by = auth()->id();
            // Never live on creation: activation is a deliberate, separate act.
            $package->is_active = false;
        }

        $package->updated_by = auth()->id();
        $package->save();

        $this->reset('editingId', 'name', 'description', 'price', 'credit_amount', 'sort_order', 'showForm');

        session()->flash('status', 'Credit package saved. Activate it when it is ready to sell.');
    }

    public function activate(int $id): void
    {
        $this->authorize('credit_packages.activate');
        $this->setActive($id, true, 'activated', 'Package is now on sale.');
    }

    public function archive(int $id): void
    {
        $this->authorize('credit_packages.archive');
        $this->setActive($id, false, 'archived', 'Package withdrawn from sale. Existing purchases are unaffected.');
    }

    private function setActive(int $id, bool $active, string $event, string $message): void
    {
        $package = CreditPackage::findOrFail($id);
        $package->is_active = $active;
        $package->updated_by = auth()->id();
        $package->save();

        activity('credit_package')
            ->performedOn($package)
            ->causedBy(auth()->user())
            ->withProperties(['slug' => $package->slug, 'is_active' => $active])
            ->log($event);

        session()->flash('status', $message);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'package';
        $slug = $base;
        $suffix = 2;

        while (CreditPackage::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * @return Collection<int, CreditPackage>
     */
    public function packages(): Collection
    {
        return CreditPackage::orderBy('sort_order')->orderBy('id')->get();
    }

    public function render(): View
    {
        return view('livewire.admin.payments.package-manager', [
            'packages' => $this->packages(),
        ]);
    }
}
