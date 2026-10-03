{{-- Landing page for an unsubscribe link.

     There is deliberately no "are you sure?" and no confirmation step. An
     unsubscribe link that takes two clicks to act is an unsubscribe link people
     click once and distrust.

     The unknown-token case says the address may already have been removed rather
     than implying they are still subscribed. Being vague on purpose here is not
     hedging: the alternative is telling somebody they are still receiving email
     when they have asked to stop. --}}

<div>
    <x-card>
        @if ($outcome === 'unknown')
            <h1 class="text-xl font-bold tracking-tight text-slate-900">
                We could not find that subscription
            </h1>

            <p class="mt-3 text-sm text-slate-600">
                The link may be from a very old email. Nothing needs doing if you
                no longer receive mail from us. If you are still hearing from us,
                reply to any email we send and we will remove the address by hand.
            </p>
        @elseif ($outcome === 'already')
            <h1 class="text-xl font-bold tracking-tight text-slate-900">
                Already unsubscribed
            </h1>

            <p class="mt-3 text-sm text-slate-600">
                This address was removed already, so there is nothing further to do.
                It will stay off the list unless somebody asks to be added back
                and confirms it.
            </p>
        @else
            <h1 class="text-xl font-bold tracking-tight text-slate-900">
                You are unsubscribed
            </h1>

            <p class="mt-3 text-sm text-slate-600">
                This address is off the newsletter list as of now, and the
                unsubscribe link in any past email will keep working. You can sign
                up again from the footer at any time.
            </p>
        @endif

        <a href="{{ route('home') }}" class="mt-6 inline-block text-sm font-semibold text-accent-700 hover:text-accent-800">
            Back to the marketplace
        </a>
    </x-card>
</div>