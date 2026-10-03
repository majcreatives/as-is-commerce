{{-- Landing page for a confirmation link.

     Three outcomes and all of them are ordinary, so none of them is styled as
     an error. Being told "you already confirmed this" is not a failure and
     making it look like one would punish the person who clicked the link a
     second time out of caution.

     No address is shown. These URLs end up in browser history, screenshots and
     the occasional forwarded email, and there is no reason any of that should
     carry a readable address. --}}

<div>
    <x-card>
        @if ($outcome === 'confirmed')
            <h1 class="text-xl font-bold tracking-tight text-slate-900">
                You are on the list
            </h1>

            <p class="mt-3 text-sm text-slate-600">
                Thanks for confirming. We will only use this address for the
                occasional auction email, and every one of those emails carries
                a link to stop them.
            </p>
        @else
            <h1 class="text-xl font-bold tracking-tight text-slate-900">
                This link has already been used
            </h1>

            <p class="mt-3 text-sm text-slate-600">
                Confirmation links only work once, so nothing further is needed if
                you have already confirmed. If you are not on the list and were
                expecting to be, submit the address again from the footer and we
                will send a fresh link.
            </p>
        @endif

        <a href="{{ route('home') }}" class="mt-6 inline-block text-sm font-semibold text-accent-700 hover:text-accent-800">
            Back to the marketplace
        </a>
    </x-card>
</div>