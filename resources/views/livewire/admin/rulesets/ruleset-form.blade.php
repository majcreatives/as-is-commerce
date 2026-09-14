<x-admin.shell>

    <x-page-header
        :title="$isEditing ? 'Edit draft ruleset' : 'New auction ruleset'"
        description="Every value here is configuration, not code. Auctions copy these rules when they are created and never read them again, so changing a draft cannot affect an auction that already exists." />

    @error('invariants')
        <x-alert variant="danger" class="mb-6">
            <p class="font-semibold">This combination of rules is contradictory:</p>
            <ul class="mt-1.5 list-inside list-disc space-y-1">
                @foreach ($errors->get('invariants') as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </x-alert>
    @enderror

    <form wire:submit="save" class="space-y-6">

        {{-- ---------------------------------------------------------- Basic --}}
        <x-card title="Basic" subtitle="How this ruleset is identified.">
            <div class="space-y-5">
                <x-field label="Name" name="name" :error="$errors->first('name')"
                         hint="Rulesets are versioned by name. Reusing an existing name creates the next version of that lineage.">
                    <x-input id="name" wire:model="name" :error="$errors->has('name')" required />
                </x-field>

                <x-field label="Description" name="description" :error="$errors->first('description')" optional
                         hint="Why this configuration exists, for whoever reads it next.">
                    <textarea id="description" wire:model="description" rows="3"
                              class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm"></textarea>
                </x-field>
            </div>
        </x-card>

        {{-- -------------------------------------------------------- Bidding --}}
        <x-card title="Bidding"
                subtitle="Bidders choose how many credits to commit. These rules decide which amounts are valid.">
            <x-alert variant="info" class="mb-5">
                <strong class="font-semibold">The highest valid credit bid wins</strong> when the auction
                closes normally &mdash; not the last bidder, and not whoever bid most often. A bidder who is
                overtaken and later bids higher still wins on that highest bid.
            </x-alert>

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Minimum bid (credits)" name="minimum_bid_credits"
                         :error="$errors->first('minimum_bid_credits')" optional
                         hint="The smallest bid that can ever be submitted. Leave blank for no minimum.">
                    <x-input id="minimum_bid_credits" inputmode="numeric" wire:model="minimum_bid_credits"
                             :error="$errors->has('minimum_bid_credits')" />
                </x-field>

                <x-field label="Minimum increment (credits)" name="minimum_bid_increment_credits"
                         :error="$errors->first('minimum_bid_increment_credits')" optional
                         hint="How far a new bid must exceed the current highest. Leave blank for no minimum.">
                    <x-input id="minimum_bid_increment_credits" inputmode="numeric"
                             wire:model="minimum_bid_increment_credits"
                             :error="$errors->has('minimum_bid_increment_credits')" />
                </x-field>

                <x-field label="May a bidder raise their own bid?" name="allow_bid_increase"
                         :error="$errors->first('allow_bid_increase')" optional
                         hint="Leave unset while this rule is undecided. Unset is not the same as no.">
                    <select id="allow_bid_increase" wire:model="allow_bid_increase"
                            class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                        <option value="">Not decided</option>
                        <option value="yes">Yes</option>
                        <option value="no">No</option>
                    </select>
                </x-field>

                <x-field label="Minimum bid interval (ms)" name="minimum_bid_interval_ms"
                         :error="$errors->first('minimum_bid_interval_ms')"
                         hint="Shortest gap between two bids from the same user on the same auction. 1000 = one second.">
                    <x-input id="minimum_bid_interval_ms" type="number" min="0" wire:model="minimum_bid_interval_ms"
                             :error="$errors->has('minimum_bid_interval_ms')" required />
                </x-field>
            </div>
        </x-card>

        {{-- -------------------------------------------------------- Buy Now --}}
        <x-card title="Buy Now"
                subtitle="Buying the product outright ends the auction immediately, whatever the highest bid.">
            <div class="space-y-5">
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model="buy_now_enabled"
                           class="mt-0.5 size-4 rounded border-slate-300 text-brand-700 focus:ring-brand-600">
                    <span>
                        <span class="block text-sm font-medium text-slate-700">Buy Now available</span>
                        <span class="block text-xs text-slate-500">
                            When a customer completes a Buy Now purchase the auction ends at once and the
                            standing highest bidder does not win. With this off, the auction runs to its
                            normal close.
                        </span>
                    </span>
                </label>

                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model="buy_now_credit_discount_enabled"
                           class="mt-0.5 size-4 rounded border-slate-300 text-brand-700 focus:ring-brand-600">
                    <span>
                        <span class="block text-sm font-medium text-slate-700">Credit discount on Buy Now</span>
                        <span class="block text-xs text-slate-500">
                            Credits a customer already consumed bidding on this auction reduce the Buy Now
                            price, valued at what those credits actually cost. The credits stay consumed
                            &mdash; this lowers a separate purchase price rather than refunding them.
                        </span>
                    </span>
                </label>
            </div>
        </x-card>

        {{-- --------------------------------------------------------- Timing --}}
        <x-card title="Timing"
                subtitle="How long an auction runs, and whether late bids extend it. Extension is anti-sniping only — it never changes who wins.">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Base duration (seconds)" name="base_duration_seconds"
                         :error="$errors->first('base_duration_seconds')"
                         hint="Starting countdown. 300 = five minutes.">
                    <x-input id="base_duration_seconds" type="number" min="1" wire:model="base_duration_seconds"
                             :error="$errors->has('base_duration_seconds')" required />
                </x-field>

                <x-field label="Closing window (seconds)" name="closing_window_seconds"
                         :error="$errors->first('closing_window_seconds')"
                         hint="A bid placed with this much time or less remaining triggers an extension.">
                    <x-input id="closing_window_seconds" type="number" min="0" wire:model="closing_window_seconds"
                             :error="$errors->has('closing_window_seconds')" required />
                </x-field>

                <x-field label="Extension length (seconds)" name="extension_seconds"
                         :error="$errors->first('extension_seconds')"
                         hint="Time added each time an extension triggers. Set to 0 to disable extensions.">
                    <x-input id="extension_seconds" type="number" min="0" wire:model="extension_seconds"
                             :error="$errors->has('extension_seconds')" required />
                </x-field>

                <x-field label="Maximum extensions" name="max_extensions" :error="$errors->first('max_extensions')"
                         hint="How many times the auction may be extended. Set to 0 to disable extensions.">
                    <x-input id="max_extensions" type="number" min="0" wire:model="max_extensions"
                             :error="$errors->has('max_extensions')" required />
                </x-field>

                <x-field label="Maximum total extension (seconds)" name="max_extension_total_seconds"
                         :error="$errors->first('max_extension_total_seconds')"
                         hint="Absolute ceiling on added time. Both this and the extension count apply, whichever is reached first.">
                    <x-input id="max_extension_total_seconds" type="number" min="0"
                             wire:model="max_extension_total_seconds"
                             :error="$errors->has('max_extension_total_seconds')" required />
                </x-field>
            </div>

            <x-alert variant="info" class="mt-5">
                The total-extension ceiling is what guarantees an auction terminates. Without it, a busy
                auction that resets on every bid could run indefinitely past its advertised end time.
            </x-alert>
        </x-card>

        {{-- ---------------------------------------------- Winner / checkout --}}
        <x-card title="Winner and checkout" subtitle="What the winner must do, and what happens if they do not.">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Checkout deadline (minutes)" name="checkout_deadline_minutes"
                         :error="$errors->first('checkout_deadline_minutes')"
                         hint="How long the winner has to complete payment after the auction closes.">
                    <x-input id="checkout_deadline_minutes" type="number" min="1"
                             wire:model="checkout_deadline_minutes"
                             :error="$errors->has('checkout_deadline_minutes')" required />
                </x-field>

                <x-field label="If the winner does not check out" name="forfeit_policy"
                         :error="$errors->first('forfeit_policy')">
                    <select id="forfeit_policy" wire:model="forfeit_policy"
                            class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                        @foreach ($forfeitPolicies as $policy)
                            <option value="{{ $policy->value }}">{{ $policy->label() }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>
        </x-card>

        {{-- -------------------------------------------------------- Pricing --}}
        <x-card title="Pricing" subtitle="Amounts are entered in major units and stored as whole minor units.">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Currency" name="currency" :error="$errors->first('currency')"
                         hint="ISO 4217 code, for example GHS.">
                    <x-input id="currency" wire:model="currency" maxlength="3"
                             :error="$errors->has('currency')" required />
                </x-field>

                <x-field label="Delivery fee" name="delivery_fee" :error="$errors->first('delivery_fee')"
                         hint="Added at checkout. Enter 0 for free delivery.">
                    <x-input id="delivery_fee" inputmode="decimal" placeholder="0.00"
                             wire:model="delivery_fee" :error="$errors->has('delivery_fee')" required />
                </x-field>

                <x-field label="Tax (basis points)" name="tax_bps" :error="$errors->first('tax_bps')"
                         hint="100 basis points = 1%. So 1000 = 10%, and 0 = no tax. Stored as a whole number to avoid rounding drift.">
                    <x-input id="tax_bps" type="number" min="0" max="10000" wire:model="tax_bps"
                             :error="$errors->has('tax_bps')" required />
                </x-field>
            </div>
        </x-card>

        <div class="flex flex-wrap justify-end gap-2">
            <x-button href="{{ route('admin.rulesets.index') }}" wire:navigate variant="secondary">Cancel</x-button>

            <x-button type="submit" variant="primary" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">
                    {{ $isEditing ? 'Save draft' : 'Create draft' }}
                </span>
                <span wire:loading wire:target="save">Saving&hellip;</span>
            </x-button>
        </div>

        @unless ($isEditing)
            <p class="text-center text-xs text-slate-500">
                New rulesets are always created as drafts. Nothing takes effect until you activate it.
            </p>
        @endunless
    </form>
</x-admin.shell>
