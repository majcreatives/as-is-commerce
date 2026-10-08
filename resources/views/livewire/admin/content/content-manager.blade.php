@php
    $tabLabel = match ($tab) {
        'partners' => 'partner',
        'stories' => 'story',
        default => 'post',
    };
@endphp

<x-admin.shell>

    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <x-page-header
            class="mb-0"
            title="Partners, stories &amp; posts"
            description="The content shown on the public site. Records are saved unpublished — publishing is a separate action, so nothing goes in front of customers by being typed and forgotten. Nothing is deleted; unpublish to retire one." />

        @if ($this->can('create'))
            <x-button wire:click="create" variant="primary" class="shrink-0">
                New {{ $tabLabel }}
            </x-button>
        @endif
    </div>

    @if (session('status'))
        <x-alert variant="success" class="mb-6">{{ session('status') }}</x-alert>
    @endif

    {{-- Only tabs the signed-in administrator may actually view. A tab shown and
         then refused on click is worse than a tab that is not offered. --}}
    <div class="mb-4 flex flex-wrap gap-1 border-b border-slate-200 pb-3" role="tablist">
        @foreach ([
            'partners' => ['label' => 'Partners', 'permission' => 'partners.view'],
            'stories' => ['label' => 'Success stories', 'permission' => 'success_stories.view'],
            'posts' => ['label' => 'Blog posts', 'permission' => 'posts.view'],
        ] as $key => $config)
            @continue(! auth()->user()->can($config['permission']))

            <button type="button" role="tab" wire:click="switchTab('{{ $key }}')"
                    aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    @class([
                        'rounded-md px-3 py-2 text-sm font-medium transition',
                        'bg-brand-50 text-brand-800' => $tab === $key,
                        'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => $tab !== $key,
                    ])>{{ $config['label'] }}</button>
        @endforeach
    </div>

    @if ($showForm)
        <x-card :title="($editingId ? 'Edit ' : 'New ').$tabLabel" class="mb-6">
            <form wire:submit="save" class="space-y-5">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-field label="Name" name="name" :error="$errors->first('name')">
                        <x-input id="name" wire:model="name" :error="$errors->has('name')" required />
                    </x-field>

                    <x-field label="Display order" name="sortOrder" :error="$errors->first('sortOrder')"
                             hint="Lower numbers appear first.">
                        <x-input id="sortOrder" type="number" min="0" wire:model="sortOrder"
                                 :error="$errors->has('sortOrder')" required />
                    </x-field>

                    @if ($tab === 'partners')
                        <div class="sm:col-span-2">
                            <x-field label="Website" name="url" :error="$errors->first('url')" optional
                                     hint="The partner's own address. Leave blank to list them by name alone.">
                                <x-input id="url" type="url" wire:model="url" placeholder="https://"
                                         :error="$errors->has('url')" />
                            </x-field>
                        </div>

                        <div class="sm:col-span-2">
                            <x-field label="Description" name="description" :error="$errors->first('description')" optional>
                                <x-input id="description" wire:model="description"
                                         :error="$errors->has('description')" />
                            </x-field>
                        </div>

                        <div class="sm:col-span-2">
                            <x-field label="Logo" name="logo" :error="$errors->first('logo')" optional
                                     hint="JPEG, PNG, WebP or GIF, up to 2 MB. Optional — a partner without one is listed by name.">
                                <input type="file" wire:model="logo" accept="image/jpeg,image/png,image/webp,image/gif"
                                       class="block w-full text-sm text-slate-700 file:mr-4 file:rounded-lg file:border-0 file:bg-brand-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-brand-800 hover:file:bg-brand-100">
                            </x-field>
                        </div>
                    @elseif ($tab === 'stories')
                        <div class="sm:col-span-2">
                            <x-field label="Title or location" name="storyTitle" :error="$errors->first('storyTitle')"
                                     hint="The line under the name — a role, or where they are.">
                                <x-input id="storyTitle" wire:model="storyTitle"
                                         :error="$errors->has('storyTitle')" required />
                            </x-field>
                        </div>

                        <div class="sm:col-span-2">
                            <x-field label="What they said" name="quote" :error="$errors->first('quote')"
                                     hint="Their words, in their own voice. Keep it to what they actually said — this is published as a quotation.">
                                <textarea id="quote" wire:model="quote" rows="4"
                                          class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm @error('quote') ring-red-400 @enderror">{{ $quote }}</textarea>
                            </x-field>
                        </div>

                        <div class="sm:col-span-2">
                            <x-field label="Photo" name="photo" :error="$errors->first('photo')" optional
                                     hint="JPEG, PNG, WebP or GIF, up to 2 MB. Optional — a story without one shows the words alone.">
                                <input type="file" wire:model="photo" accept="image/jpeg,image/png,image/webp,image/gif"
                                       class="block w-full text-sm text-slate-700 file:mr-4 file:rounded-lg file:border-0 file:bg-brand-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-brand-800 hover:file:bg-brand-100">
                            </x-field>
                        </div>

                        {{-- Only meaningful for a published story, so it is not
                             offered on a record that is not on the site yet.
                             Saved with the record rather than toggled, because it
                             is editorial content rather than a separate act. --}}
                        @if ($editingId)
                            <div class="sm:col-span-2">
                                <label class="flex items-start gap-2.5">
                                    <input type="checkbox" wire:model="featured"
                                           class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-600">
                                    <span class="text-sm">
                                        <span class="font-medium text-slate-900">Show on the homepage</span>
                                        <span class="block text-xs text-slate-500">
                                            Up to three stories appear there, in display order. A story can be listed
                                            on the stories page without taking a homepage slot.
                                        </span>
                                    </span>
                                </label>
                            </div>
                        @endif
                    @else
                        <div class="sm:col-span-2">
                            <x-field label="Title" name="postTitle" :error="$errors->first('postTitle')">
                                <x-input id="postTitle" wire:model="postTitle"
                                         :error="$errors->has('postTitle')" required />
                            </x-field>
                        </div>

                        <div class="sm:col-span-2">
                            <x-field label="Slug" name="slug" :error="$errors->first('slug')"
                                     hint="Lowercase letters, numbers and hyphens; the post's web address. Leave the shape of the URL in your control.">
                                <x-input id="slug" wire:model="slug" :error="$errors->has('slug')" required />
                            </x-field>
                        </div>

                        <div class="sm:col-span-2">
                            <x-field label="Excerpt" name="excerpt" :error="$errors->first('excerpt')" optional
                                     hint="One or two sentences shown on the blog list and homepage. Omit to lead with the first words of the body.">
                                <textarea id="excerpt" wire:model="excerpt" rows="2"
                                          class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm @error('excerpt') ring-red-400 @enderror">{{ $excerpt }}</textarea>
                            </x-field>
                        </div>

                        <div class="sm:col-span-2">
                            <x-field label="Body" name="body" :error="$errors->first('body')"
                                     hint="Plain text; blank lines become paragraph breaks.">
                                <textarea id="body" wire:model="body" rows="10"
                                          class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm @error('body') ring-red-400 @enderror">{{ $body }}</textarea>
                            </x-field>
                        </div>

                        <div class="sm:col-span-2">
                            <x-field label="Featured image" name="image" :error="$errors->first('image')" optional
                                     hint="JPEG, PNG, WebP or GIF, up to 2 MB. Optional — a post without one shows the words alone.">
                                <input type="file" wire:model="image" accept="image/jpeg,image/png,image/webp,image/gif"
                                       class="block w-full text-sm text-slate-700 file:mr-4 file:rounded-lg file:border-0 file:bg-brand-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-brand-800 hover:file:bg-brand-100">
                            </x-field>
                        </div>
                    @endif
                </div>

                <p class="text-xs text-slate-500">
                    Saved unpublished. Use <span class="font-medium">Publish</span> in the list below when it is ready
                    for the public site.
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
            <table class="w-full min-w-[44rem] text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">{{ $tab === 'posts' ? 'Title' : 'Name' }}</th>

                        @if ($tab === 'partners')
                            <th scope="col" class="px-4 py-3 font-semibold">Website</th>
                        @elseif ($tab === 'stories')
                            <th scope="col" class="px-4 py-3 font-semibold">What they said</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Homepage</th>
                        @else
                            <th scope="col" class="px-4 py-3 font-semibold">Slug</th>
                        @endif

                        @if ($tab === 'posts')
                            <th scope="col" class="px-4 py-3 font-semibold">Published</th>
                        @else
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Order</th>
                        @endif
                        <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($records as $row)
                        <tr>
                            <td class="px-4 py-3">
                                <span class="font-medium text-slate-900">{{ $row->name ?? $row->title }}</span>

                                @if ($row instanceof \App\Models\SuccessStory && $row->title)
                                    <span class="block text-xs text-slate-500">{{ $row->title }}</span>
                                @elseif ($row instanceof \App\Models\Post && $row->excerpt)
                                    <span class="block max-w-md truncate text-xs text-slate-500">{{ $row->excerpt }}</span>
                                @endif
                            </td>

                            @if ($tab === 'partners')
                                <td class="px-4 py-3">
                                    @if ($row->url)
                                        <a href="{{ $row->url }}" target="_blank" rel="noopener noreferrer"
                                           class="text-brand-700 underline">{{ $row->url }}</a>
                                    @else
                                        <span class="text-slate-400">None</span>
                                    @endif
                                </td>
                            @elseif ($tab === 'stories')
                                <td class="max-w-md px-4 py-3 text-slate-600">
                                    <span class="line-clamp-2">{{ $row->quote }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    @if ($row->featured)
                                        <x-badge classes="bg-accent-50 text-brand-800 ring-accent-200">Featured</x-badge>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                            @else
                                <td class="px-4 py-3 font-mono text-xs text-slate-500">
                                    <a href="{{ route('blog.show', $row->slug) }}" target="_blank" rel="noopener noreferrer"
                                       class="text-brand-700 underline">/blog/{{ $row->slug }}</a>
                                </td>
                            @endif

                            @if ($tab === 'posts')
                                <td class="px-4 py-3 tabular-nums text-slate-600">
                                    {{ $row->published_at?->format('M j, Y') ?? '—' }}
                                </td>
                            @else
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600">{{ $row->sort_order }}</td>
                            @endif

                            <td class="px-4 py-3">
                                @php
                                    $isLive = $tab === 'posts'
                                        ? $row->active && $row->published_at !== null
                                        : (bool) $row->active;
                                @endphp
                                <x-badge :classes="$isLive
                                    ? 'bg-emerald-50 text-emerald-800 ring-emerald-200'
                                    : 'bg-slate-100 text-slate-600 ring-slate-200'">
                                    {{ $isLive ? 'Published' : 'Draft' }}
                                </x-badge>
                            </td>

                            <td class="px-4 py-3">
                                <div class="flex flex-wrap justify-end gap-1.5">
                                    @if ($this->can('update'))
                                        <x-button wire:click="edit({{ $row->id }})"
                                                  variant="secondary" size="sm">Edit</x-button>

                                        @php
                                            $hasImage = match ($tab) {
                                                'partners' => $row->logo_path,
                                                'stories' => $row->image_path,
                                                default => $row->image_path,
                                            };
                                        @endphp
                                        @if ($hasImage)
                                            <x-button wire:click="removeImage({{ $row->id }})"
                                                      variant="ghost" size="sm">Remove image</x-button>
                                        @endif
                                    @endif

                                    @if ($this->can('activate'))
                                        @if ($isLive)
                                            <x-button wire:click="togglePublished({{ $row->id }})"
                                                      variant="ghost" size="sm">Unpublish</x-button>
                                        @else
                                            <x-button wire:click="togglePublished({{ $row->id }})"
                                                      variant="primary" size="sm">Publish</x-button>
                                        @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $tab === 'partners' ? 6 : ($tab === 'stories' ? 7 : 6) }}" class="px-4 py-10">
                                <x-empty-state
                                    :title="match ($tab) {
                                        'partners' => 'No partners yet',
                                        'stories' => 'No success stories yet',
                                        default => 'No blog posts yet',
                                    }"
                                    :description="match ($tab) {
                                        'partners' => 'Create one, then publish it when it is ready to appear on the site.',
                                        'stories' => 'Create one, then publish it when it is ready to appear on the site.',
                                        default => 'Write one, then publish it when it is ready to appear on the site.',
                                    }" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</x-admin.shell>
