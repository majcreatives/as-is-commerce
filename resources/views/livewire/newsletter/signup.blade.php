{{-- Newsletter signup.

     Rendered inside a modal on the front page. It was in the footer before, and
     the wording note below is the important part of this template: the heading
     and the button both avoid promising a schedule. "Weekly deals" or "monthly
     drops" is a commitment to somebody's inbox, and nothing in this project can
     send one yet (see the newsletter_subscribers migration). If a sending
     mechanism is ever agreed, this copy can grow a frequency -- and only then,
     because by then we would be able to keep it.

     The confirmation message is deliberately identical whether or not the
     address was already on the list, so the form cannot be used to find out
     whether a given person is subscribed to us. --}}

<div>
    @if ($confirmationRequested)
        <p class="text-sm font-medium text-emerald-700" role="status">
            If that address can receive mail, we have sent it a link to confirm.
        </p>
    @else
        <form wire:submit="requestConfirmation" class="space-y-2">
            <label for="newsletter-email" class="block text-sm font-medium text-slate-700">
                Occasional email about new auctions
            </label>

            <div class="flex gap-2">
                <input
                    id="newsletter-email"
                    type="email"
                    wire:model="email"
                    wire:loading.attr="disabled"
                    autocomplete="email"
                    placeholder="you@example.com"
                    @class([
                        'w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500',
                        'border-red-400' => $errors->has('email'),
                    ])
                >

                {{-- Disabled while the request is in flight, so a double-click
                     cannot queue a second confirmation email -- and visibly so,
                     because the request may be the only thing happening on the
                     page at that moment. --}}
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:loading.remove.class="opacity-70"
                    class="shrink-0 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700"
                >
                    <span wire:loading.remove>Notify me</span>
                    <span wire:loading>Sending&hellip;</span>
                </button>
            </div>

            @error('email')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror

            {{-- Says what will happen, so nobody is surprised by a second email. --}}
            <p class="text-xs text-slate-500">
                One confirmation email first. Nothing is sent to the address until
                that link is followed, and every email carries a one-click
                unsubscribe.
            </p>
        </form>
    @endif
</div>