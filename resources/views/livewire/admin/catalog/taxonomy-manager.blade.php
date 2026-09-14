<x-admin.shell>

    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <x-page-header
            class="mb-0"
            title="Categories &amp; brands"
            description="How the catalog is organised. Neither can be deleted — a category holding products or child categories is refused, so nothing is ever orphaned. Archive instead." />

        <x-button wire:click="create" variant="primary" class="shrink-0">
            New {{ $tab === 'categories' ? 'category' : 'brand' }}
        </x-button>
    </div>

    @if (session('status'))
        <x-alert variant="success" class="mb-6">{{ session('status') }}</x-alert>
    @endif

    <div class="mb-4 flex flex-wrap gap-1 border-b border-slate-200 pb-3" role="tablist">
        @foreach (['categories' => 'Categories', 'brands' => 'Brands'] as $key => $label)
            <button type="button" role="tab" wire:click="switchTab('{{ $key }}')"
                    aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    @class([
                        'rounded-md px-3 py-2 text-sm font-medium transition',
                        'bg-brand-50 text-brand-800' => $tab === $key,
                        'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => $tab !== $key,
                    ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($showForm)
        <x-card :title="($editingId ? 'Edit ' : 'New ').($tab === 'categories' ? 'category' : 'brand')" class="mb-6">
            <form wire:submit="save" class="space-y-5">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-field label="Name" name="name" :error="$errors->first('name')">
                        <x-input id="name" wire:model="name" :error="$errors->has('name')" required />
                    </x-field>

                    @if ($tab === 'categories')
                        <x-field label="Parent category" name="parent_id" :error="$errors->first('parent_id')" optional
                                 hint="Leave blank for a top-level category.">
                            <select id="parent_id" wire:model="parent_id"
                                    class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                                <option value="">Top level</option>
                                @foreach ($parentOptions as $option)
                                    @continue($editingId === $option->id)
                                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                                @endforeach
                            </select>
                        </x-field>

                        <x-field label="Display order" name="sort_order" :error="$errors->first('sort_order')"
                                 hint="Lower numbers appear first.">
                            <x-input id="sort_order" type="number" min="0" wire:model="sort_order"
                                     :error="$errors->has('sort_order')" required />
                        </x-field>
                    @endif

                    <div class="sm:col-span-2">
                        <x-field label="Description" name="description" :error="$errors->first('description')" optional>
                            <x-input id="description" wire:model="description"
                                     :error="$errors->has('description')" />
                        </x-field>
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <x-button type="button" wire:click="cancel" variant="secondary">Cancel</x-button>
                    <x-button type="submit" variant="primary" wire:loading.attr="disabled">Save</x-button>
                </div>
            </form>
        </x-card>
    @endif

    <x-card :padded="false">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[40rem] text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">Name</th>
                        @if ($tab === 'categories')
                            <th scope="col" class="px-4 py-3 font-semibold">Parent</th>
                        @endif
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Products</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @php($rows = $tab === 'categories' ? $categories : $brands)

                    @forelse ($rows as $row)
                        <tr>
                            <td class="px-4 py-3">
                                <span class="font-medium text-slate-900">{{ $row->name }}</span>
                                <span class="block font-mono text-xs text-slate-400">{{ $row->slug }}</span>
                            </td>

                            @if ($tab === 'categories')
                                <td class="px-4 py-3 text-slate-600">{{ $row->parent?->name ?? 'Top level' }}</td>
                            @endif

                            <td class="px-4 py-3 text-right tabular-nums text-slate-600">
                                {{ number_format($row->products_count) }}
                            </td>
                            <td class="px-4 py-3">
                                <x-badge :classes="$row->status->badgeClasses()">{{ $row->status->label() }}</x-badge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap justify-end gap-1.5">
                                    <x-button wire:click="edit({{ $row->id }})"
                                              variant="secondary" size="sm">Edit</x-button>

                                    @if ($row->status !== \App\Enums\CatalogStatus::Active)
                                        <x-button wire:click="setStatus({{ $row->id }}, 'active')"
                                                  variant="primary" size="sm">Activate</x-button>
                                    @else
                                        <x-button wire:click="setStatus({{ $row->id }}, 'inactive')"
                                                  variant="ghost" size="sm">Deactivate</x-button>
                                    @endif

                                    @if ($row->status !== \App\Enums\CatalogStatus::Archived)
                                        <x-button wire:click="setStatus({{ $row->id }}, 'archived')"
                                                  variant="ghost" size="sm">Archive</x-button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10">
                                <x-empty-state
                                    :title="'No '.$tab.' yet'"
                                    description="Create one to start organising the catalog." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</x-admin.shell>
