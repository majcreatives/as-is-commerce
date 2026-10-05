{{-- Newsletter signup.

     Rendered inside a modal on the front page. It was in the footer before. The
     modal's heading, description and icon belong to that template; this one owns
     the field, the button, and what happens after submitting -- which is the part
     that must not vary by where the form happens to sit.

     THE WORDING BELOW PROMISES NO SCHEDULE, and that is the important part. "Weekly
     deals" or "monthly drops" is a commitment to somebody's inbox, and nothing in
     this project can send one yet (see the newsletter_subscribers migration). If a
     sending mechanism is ever agreed, this copy can grow a frequency -- and only
     then, because by then we would be able to keep it.

     THE CONFIRMATION MESSAGE IS DELIBERATELY IDENTICAL whether or not the address
     was already on the list, so the form cannot be used to find out whether a
     given person is subscribed to us. --}}

<div>
    @if ($confirmationRequested)
        <p class="text-sm font-medium text-emerald-700" role="status">
            If that address can receive mail, we have sent it a link to confirm.
        </p>
    @else
        <form wire:submit="requestConfirmation" class="w-full space-y-4 text-left">
            <x-label for="newsletter-email">
                Email <span class="text-red-500" aria-hidden="true">*</span>
            </x-label>

            {{-- x-input, so this field reports an error the way every other
                 field in the project does: aria-invalid plus aria-describedby
                 pointing at the message below. That pairing needs the error
                 paragraph to carry this exact id, which is why it is hard-coded
                 in both places -- x-input derives it from the control's id. --}}
            <x-input
                id="newsletter-email"
                type="email"
                wire:model="email"
                wire:loading.attr="disabled"
                autocomplete="email"
                placeholder="you@example.com"
                :error="$errors->has('email')"
            />

            {{-- The shared button, so this is the same indigo as every other
                 primary action rather than one hand-picked colour. Uppercase by
                 class, not in the markup: assistive technology reads "Notify me"
                 either way, and the DOM stays case-stable. --}}
            <x-button
                type="submit"
                size="lg"
                wire:loading.attr="disabled"
                wire:loading.remove.class="opacity-70"
                class="w-full uppercase tracking-wider"
            >
                <span wire:loading.remove>Notify me</span>
                <span wire:loading>Sending&hellip;</span>
            </x-button>

            @error('email')
                <p id="newsletter-email-error" class="text-sm text-red-600">{{ $message }}</p>
            @enderror

            {{-- Says what will happen, so nobody is surprised by a second email.
                 In a modal this matters more than it did in a footer: the panel
                 covers the page, so this is the only place the reader is told
                 what they have just agreed to. --}}
            <p class="text-xs text-slate-500">
                One confirmation email first. Nothing is sent to the address until
                that link is followed, and every email carries a one-click
                unsubscribe.
            </p>
        </form>
    @endif
</div>