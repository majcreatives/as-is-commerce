<x-layouts.app :title="config('app.name').' — Credit-based auctions in Ghana'">
    {{-- Hero --}}
    <section class="overflow-hidden rounded-2xl bg-brand-900 px-6 py-12 sm:px-10 sm:py-16">
        <div class="max-w-2xl">
            <span class="inline-flex items-center rounded-full bg-brand-800 px-3 py-1 text-xs font-semibold text-accent-300 ring-1 ring-inset ring-brand-700">
                Built for Ghana &middot; GH&#8373;
            </span>

            <h1 class="mt-4 text-3xl font-bold tracking-tight text-white sm:text-5xl">
                Win real products at auction.
            </h1>

            <p class="mt-4 text-base text-brand-100 sm:text-lg">
                Buy bidding credits, join live auctions, and pay a reduced checkout price
                when you win. Every auction is timed and settled by our servers, so the
                result is the same for everyone watching.
            </p>

            <div class="mt-8 flex flex-wrap gap-3">
                @guest
                    <x-button href="{{ route('register') }}" variant="accent" size="lg">Create your account</x-button>
                    <x-button href="{{ route('how-it-works') }}" variant="secondary" size="lg">How it works</x-button>
                @else
                    <x-button href="{{ route('dashboard') }}" variant="accent" size="lg">Go to dashboard</x-button>
                    <x-button href="{{ route('auctions.index') }}" variant="secondary" size="lg">Browse auctions</x-button>
                @endguest
            </div>
        </div>
    </section>

    {{-- Value propositions --}}
    <section class="mt-10 grid gap-4 sm:grid-cols-3">
        <x-card>
            <h2 class="text-sm font-semibold text-slate-900">Server-timed auctions</h2>
            <p class="mt-1.5 text-sm text-slate-600">
                Countdowns, extensions and winners are decided on our servers — never in your browser.
            </p>
        </x-card>

        <x-card>
            <h2 class="text-sm font-semibold text-slate-900">Every credit accounted for</h2>
            <p class="mt-1.5 text-sm text-slate-600">
                Each credit you buy or spend is written to a permanent transaction record you can review.
            </p>
        </x-card>

        <x-card>
            <h2 class="text-sm font-semibold text-slate-900">Mobile money ready</h2>
            <p class="mt-1.5 text-sm text-slate-600">
                Designed around how Ghana actually pays, with prices always shown in GH&#8373;.
            </p>
        </x-card>
    </section>

    {{-- Auctions: no data exists yet, so the page says so plainly. --}}
    <section class="mt-10">
        <x-page-header
            title="Live auctions"
            description="Auctions open as soon as the marketplace launches." />

        <x-empty-state
            title="No auctions are running yet"
            description="The auction engine is still being built. Create an account now and you will be ready when the first auction opens.">
            @guest
                <x-button href="{{ route('register') }}" variant="primary" size="sm">Create account</x-button>
            @endguest
        </x-empty-state>
    </section>
</x-layouts.app>
