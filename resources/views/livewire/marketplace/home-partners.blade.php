{{-- Who we work with. Hidden for the same reason as the stories above: an
     empty strip of partner logos would imply there are no partners, which
     is a statement about the business rather than about this page.

     A Lazy component: it renders nothing until a reader actually scrolls to
     it, and this inside stays empty when no partner is published. The shop,
     the auctions, and the blog stay in the eager HTML; this section is
     supplemental. The outer div is the element Livewire remembers each
     render -- always a single root node, empty or not, so a scroll-in of an
     empty block is a swap for nothing rather than an error. --}}

<div>
    @if ($partners->isNotEmpty())
        <section class="mt-12">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-xl font-bold tracking-tight text-slate-900">Partners</h2>
                    <p class="mt-1 text-sm text-slate-600">
                        Businesses that work with us.
                    </p>
                </div>

                <a href="{{ route('partners.index') }}" wire:navigate
                   class="text-sm font-semibold text-brand-800 underline">All partners</a>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($partners as $partner)
                    <x-partner-card :partner="$partner" />
                @endforeach
            </div>
        </section>
    @endif
</div>