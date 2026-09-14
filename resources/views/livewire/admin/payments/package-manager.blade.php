<x-admin.shell>

    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <x-page-header
            class="mb-0"
            title="Credit packages"
            description="What customers can buy. A package price says how much money buys how many credits — it has no relationship to any product's price." />

        @can('credit_packages.create')
            <x-button wire:click="create" variant="primary" class="shrink-0">New package</x-button>
        @endcan
    </div>

    @if (session('status'))
        <x-alert variant="success" class="mb-6">{{ session('status') }}</x-alert>
    @endif

    @if ($showForm)
        <x-card :title="$editingId ? 'Edit package' : 'New package'" class="mb-6">
            <form wire:submit="save" class="space-y-5">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-field label="Name" name="name" :error="$errors->first('name')">
                        <x-input id="name" wire:model="name" :error="$errors->has('name')" required />
                    </x-field>

                    <x-field label="Display order" name="sort_order" :error="$errors->first('sort_order')"
                             hint="Lower numbers appear first.">
                        <x-input id="sort_order" type="number" min="0" wire:model="sort_order"
                                 :error="$errors->has('sort_order')" required />
                    </x-field>

                    <x-field label="Credits included" name="credit_amount" :error="$errors->first('credit_amount')"
                             hint="How many bidding credits the customer receives.">
                        <x-input id="credit_amount" type="number" min="1" wire:model="credit_amount"
                                 :error="$errors->has('credit_amount')" required />
                    </x-field>

                    <x-field label="Price" name="price" :error="$errors->first('price')"
                             hint="In cedis, for example 45.00. Stored as whole pesewas.">
                        <x-input id="price" inputmode="decimal" placeholder="45.00" wire:model="price"
                                 :error="$errors->has('price')" required />
                    </x-field>

                    <div class="sm:col-span-2">
                        <x-field label="Description" name="description" :error="$errors->first('description')" optional>
                            <x-input id="description" wire:model="description"
                                     :error="$errors->has('description')" />
                        </x-field>
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <x-button type="button" wire:click="$set('showForm', false)" variant="secondary">Cancel</x-button>
                    <x-button type="submit" variant="primary" wire:loading.attr="disabled">Save package</x-button>
                </div>

                @unless ($editingId)
                    <p class="text-right text-xs text-slate-500">
                        New packages are created inactive. Activate one when it is ready to sell.
                    </p>
                @endunless
            </form>
        </x-card>
    @endif

    <x-card :padded="false">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[44rem] text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">Package</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Credits</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Price</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($packages as $package)
                        <tr>
                            <td class="px-4 py-3">
                                <span class="font-medium text-slate-900">{{ $package->name }}</span>
                                <span class="block font-mono text-xs text-slate-400">{{ $package->slug }}</span>
                            </td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900">
                                {{ number_format($package->credit_amount) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600">
                                {{ $package->currency }} {{ $package->price()->format() }}
                            </td>
                            <td class="px-4 py-3">
                                @if ($package->is_active)
                                    <x-badge classes="bg-emerald-50 text-emerald-800 ring-emerald-200">On sale</x-badge>
                                @else
                                    <x-badge classes="bg-slate-100 text-slate-700 ring-slate-200">Not on sale</x-badge>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap justify-end gap-1.5">
                                    @can('credit_packages.update')
                                        <x-button wire:click="edit({{ $package->id }})"
                                                  variant="secondary" size="sm">Edit</x-button>
                                    @endcan

                                    @if ($package->is_active)
                                        @can('credit_packages.archive')
                                            <x-button wire:click="archive({{ $package->id }})"
                                                      wire:confirm="Withdraw {{ $package->name }} from sale? Existing purchases are unaffected."
                                                      variant="ghost" size="sm">Withdraw</x-button>
                                        @endcan
                                    @else
                                        @can('credit_packages.activate')
                                            <x-button wire:click="activate({{ $package->id }})"
                                                      variant="primary" size="sm">Activate</x-button>
                                        @endcan
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10">
                                <x-empty-state
                                    title="No credit packages"
                                    description="Create a package to let customers buy credits." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</x-admin.shell>
