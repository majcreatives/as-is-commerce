<x-admin.shell>

    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <x-page-header
            class="mb-0"
            title="Blog categories &amp; tags"
            description="The vocabulary of the blog. Archive retires a category or tag without destroying its history; Delete removes it permanently. Deleting a category leaves posts working without one, and a category or tag appears on the public site only once a published post uses it." />

        @if ($tab === 'categories' || $tab === 'tags')
            <x-button wire:click="create" variant="primary" class="shrink-0">
                New {{ $tab === 'categories' ? 'category' : 'tag' }}
            </x-button>
        @endif
    </div>

    @if (session('status'))
        <x-alert variant="success" class="mb-6">{{ session('status') }}</x-alert>
    @endif

    <div class="mb-4 flex flex-wrap gap-1 border-b border-slate-200 pb-3" role="tablist">
        @foreach (['categories' => 'Categories', 'tags' => 'Tags'] as $key => $label)
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
        <x-card :title="($editingId ? 'Edit ' : 'New ').($tab === 'categories' ? 'category' : 'tag')" class="mb-6">
            <form wire:submit="save" class="space-y-5">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-field label="Name" name="name" :error="$errors->first('name')">
                        <x-input id="name" wire:model="name" :error="$errors->has('name')" required />
                    </x-field>

                    @if ($tab === 'categories')
                        <x-field label="Display order" name="sort_order" :error="$errors->first('sort_order')"
                                 hint="Lower numbers appear first.">
                            <x-input id="sort_order" type="number" min="0" wire:model="sort_order"
                                     :error="$errors->has('sort_order')" required />
                        </x-field>

                        <div class="sm:col-span-2">
                            <x-field label="Description" name="description" :error="$errors->first('description')" optional>
                                <x-input id="description" wire:model="description"
                                         :error="$errors->has('description')" />
                            </x-field>
                        </div>
                    @endif
                </div>

                <p class="text-xs text-slate-500">
                    The web address is derived from the name.
                </p>

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
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Posts</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @php($rows = $tab === 'categories' ? $categories : $tags)

                    @forelse ($rows as $row)
                        <tr>
                            <td class="px-4 py-3">
                                <span class="font-medium text-slate-900">{{ $row->name }}</span>
                                <span class="block font-mono text-xs text-slate-400">/blog/{{ $row->slug }}</span>
                            </td>

                            <td class="px-4 py-3 text-right tabular-nums text-slate-600">
                                {{ number_format($row->posts_count) }}
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

                                    @can($tab === 'categories' ? 'blog_categories.delete' : 'blog_tags.delete')
                                        <x-button wire:click="deleteRecord({{ $row->id }})"
                                                  wire:confirm="Delete this {{ $tab === 'categories' ? 'category' : 'tag' }} permanently? Posts are left intact. This cannot be undone."
                                                  variant="danger" size="sm">Delete</x-button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-10">
                                <x-empty-state
                                    :title="'No '.$tab.' yet'"
                                    description="Create one to start organising the blog." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</x-admin.shell>