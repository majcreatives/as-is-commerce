<div>
    <x-admin.nav />

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
        <x-card title="Bidding" subtitle="What it costs to bid, and how often a user may do so.">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Bid cost (credits)" name="bid_cost_credits" :error="$errors->first('bid_cost_credits')"
                         hint="Credits consumed by one valid bid. This is unrelated to the checkout price.">
                    <x-input id="bid_cost_credits" type="number" min="1" wire:model="bid_cost_credits"
                             :error="$errors->has('bid_cost_credits')" required />
                </x-field>

                <x-field label="Minimum bid interval (ms)" name="minimum_bid_interval_ms"
                         :error="$errors->first('minimum_bid_interval_ms')"
                         hint="Shortest gap between two bids from the same user on the same auction. 1000 = one second.">
                    <x-input id="minimum_bid_interval_ms" type="number" min="0" wire:model="minimum_bid_interval_ms"
                             :error="$errors->has('minimum_bid_interval_ms')" required />
                </x-field>

                <div class="sm:col-span-2">
                    <label class="flex items-start gap-3">
                        <input type="checkbox" wire:model="unique_leader"
                               class="mt-0.5 size-4 rounded border-slate-300 text-brand-700 focus:ring-brand-600">
                        <span>
                            <span class="block text-sm font-medium text-slate-700">Prevent consecutive leads</span>
                            <span class="block text-xs text-slate-500">
                                A user who already holds the lead cannot bid again until someone outbids them.
                                Without this, a user can spend credits bidding against themselves for no change in position.
                            </span>
                        </span>
                    </label>
                </div>
            </div>
        </x-card>

        {{-- --------------------------------------------------------- Timing --}}
        <x-card title="Timing" subtitle="How long an auction runs, and how late bids extend it.">
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
                <x-field label="Default checkout price" name="default_checkout_price"
                         :error="$errors->first('default_checkout_price')" optional
                         hint="Leave blank unless every auction using this ruleset genuinely sells at the same price. The checkout price belongs to the product, so auctions normally supply their own.">
                    <x-input id="default_checkout_price" inputmode="decimal" placeholder="5500.00"
                             wire:model="default_checkout_price"
                             :error="$errors->has('default_checkout_price')" />
                </x-field>

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
</div>
