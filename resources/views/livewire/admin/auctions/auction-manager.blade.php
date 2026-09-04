{{-- Auction administration.

     The create form deliberately shows the chosen product's Buy Now price
     immediately beside the settlement amount field, read-only. They are
     different figures for different things, and seeing both at once is what
     stops one being typed in place of the other. --}}

<div>
    <x-admin.nav />

    <x-page-header
        title="Auctions"
        description="Each auction freezes its rules and its settlement amount when it is created." />

    @if (session('status'))
        <x-alert variant="success" class="mb-6">{{ session('status') }}</x-alert>
    @endif

    {{-- ------------------------------------------------------ Create form --}}
    @if ($showForm)
        <x-card title="New auction"
                subtitle="Created as a draft. Publishing it reserves a unit of stock."
                class="mb-6">
            <form wire:submit="save" class="space-y-5">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-field label="Product" name="product_id" :error="$errors->first('product_id')">
                        <select wire:model.live="product_id" id="product_id"
                                class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                            <option value="">Choose a product…</option>
                            @foreach ($this->products() as $product)
                                <option value="{{ $product->id }}">
                                    {{ $product->name }} ({{ $product->sku }})
                                </option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Ruleset" name="auction_ruleset_id"
                             :error="$errors->first('auction_ruleset_id')"
                             hint="Copied into the auction now. Editing it later cannot change this auction.">
                        <select wire:model="auction_ruleset_id" id="auction_ruleset_id"
                                class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                            @foreach ($this->rulesets() as $ruleset)
                                <option value="{{ $ruleset->id }}">
                                    {{ $ruleset->name }} (v{{ $ruleset->version }})
                                </option>
                            @endforeach
                        </select>
                    </x-field>
                </div>

                {{-- The three numbers, side by side and clearly labelled. --}}
                <div class="rounded-lg bg-slate-50 p-4 ring-1 ring-inset ring-slate-200">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Three separate figures
                    </p>

                    <div class="mt-3 grid gap-5 sm:grid-cols-2">
                        <div>
                            <p class="text-sm text-slate-600">Buy Now price (the product's own price)</p>
                            <p class="mt-1 text-lg font-semibold tabular-nums text-slate-900">
                                @if ($this->selectedProduct())
                                    <x-money :amount="$this->selectedProduct()->buyNowPrice()" />
                                @else
                                    —
                                @endif
                            </p>
                            <p class="mt-1 text-xs text-slate-500">
                                Set on the product, not here. What a customer pays to buy it outright.
                            </p>
                        </div>

                        <x-field label="Auction Settlement Amount (GH₵)" name="settlement_amount"
                                 :error="$errors->first('settlement_amount')"
                                 hint="What the highest valid credit bidder pays if this auction closes normally, before delivery and tax.">
                            <x-input wire:model="settlement_amount" placeholder="100.00"
                                     :error="$errors->has('settlement_amount')" />
                        </x-field>
                    </div>

                    <p class="mt-4 text-xs text-slate-500">
                        These two are unrelated by design. A GH₵5,500 product may settle at GH₵50 in one
                        auction and GH₵150 in another. Neither has any relationship to the
                        <strong>credits</strong> bidders commit — those are a count, not money, and are
                        permanently consumed.
                    </p>
                </div>

                <div class="flex gap-3">
                    <x-button type="submit">Create draft auction</x-button>
                    <x-button type="button" variant="ghost" wire:click="cancelForm">Cancel</x-button>
                </div>
            </form>
        </x-card>
    @endif

    {{-- ----------------------------------------------------------- Filters --}}
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="w-full sm:w-64">
            <x-field label="Search" name="search">
                <x-input wire:model.live.debounce.300ms="search" placeholder="Product name or SKU" />
            </x-field>
        </div>

        <div class="w-full sm:w-56">
            <x-field label="Status" name="status">
                <select wire:model.live="status" id="status"
                        class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </select>
            </x-field>
        </div>

        @can('auctions.create')
            <div class="ml-auto">
                <x-button wire:click="create">New auction</x-button>
            </div>
        @endcan
    </div>

    {{-- ------------------------------------------------------------- List --}}
    <x-card :padded="false">
        @if ($auctions->isEmpty())
            <div class="p-5">
                <x-empty-state
                    title="No auctions yet"
                    description="Create one from a product and an active ruleset. Nothing is listed here that has not been created." />
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Auction</th>
                            <th class="px-5 py-3 font-semibold">Status</th>
                            <th class="px-5 py-3 font-semibold">Highest Bid (Credits)</th>
                            <th class="px-5 py-3 font-semibold">Settlement</th>
                            <th class="px-5 py-3 font-semibold">Buy Now</th>
                            <th class="px-5 py-3 font-semibold">Ends</th>
                            <th class="px-5 py-3"><span class="sr-only">Open</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($auctions as $auction)
                            <tr>
                                <td class="px-5 py-3">
                                    <p class="font-semibold text-slate-900">#{{ $auction->id }}
                                        {{ $auction->product->name }}</p>
                                    <p class="text-xs text-slate-500">
                                        {{ $auction->ruleset?->name ?? 'Ruleset removed' }}
                                        @if ($auction->ruleset)
                                            v{{ $auction->ruleset->version }}
                                        @endif
                                    </p>
                                </td>

                                <td class="px-5 py-3">
                                    <x-badge :classes="$auction->status->badgeClasses()">
                                        {{ $auction->status->label() }}
                                    </x-badge>

                                    @if ($auction->closure_reason)
                                        <p class="mt-1 text-xs text-slate-500">
                                            {{ $auction->closure_reason->label() }}
                                        </p>
                                    @endif
                                </td>

                                {{-- A count of credits. No currency symbol, ever. --}}
                                <td class="px-5 py-3 font-semibold tabular-nums text-slate-900">
                                    {{ $auction->highest_bid_credits === null
                                        ? '—'
                                        : number_format($auction->highest_bid_credits) }}
                                </td>

                                <td class="px-5 py-3 tabular-nums text-slate-700">
                                    <x-money :amount="$auction->settlementAmount()" />
                                </td>

                                <td class="px-5 py-3 tabular-nums text-slate-500">
                                    <x-money :amount="$auction->product->buyNowPrice()" />
                                </td>

                                <td class="px-5 py-3 text-slate-500">
                                    {{ $auction->ends_at?->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i') ?? '—' }}
                                </td>

                                <td class="px-5 py-3 text-right">
                                    <x-button variant="ghost" size="sm"
                                              href="{{ route('admin.auctions.show', $auction) }}" wire:navigate>
                                        Open
                                    </x-button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-100 px-5 py-4">{{ $auctions->links() }}</div>
        @endif
    </x-card>
</div>
