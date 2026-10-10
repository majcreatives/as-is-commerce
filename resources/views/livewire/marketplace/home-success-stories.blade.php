{{-- What customers have said. Only published, featured stories reach here,
     and only three. The section disappears entirely when nothing qualifies:
     a "Success stories" heading over nothing reads as an absence of
     customers rather than an absence of content, which is not the same
     claim and is not one we get to make.

     A Lazy component: it renders nothing until a reader actually scrolls to
     it, and this inside stays empty when no story qualifies. The shop, the
     auctions, and the blog stay in the eager HTML; this section is
     supplemental. The outer div is the element Livewire remembers each
     render -- always a single root node, empty or not, so a scroll-in of an
     empty block is a swap for nothing rather than an error. --}}

<div>
    @if ($stories->isNotEmpty())
        <section class="mt-12">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-xl font-bold tracking-tight text-slate-900">Success stories</h2>
                    <p class="mt-1 text-sm text-slate-600">
                        In their own words.
                    </p>
                </div>

                <a href="{{ route('success-stories.index') }}" wire:navigate
                   class="text-sm font-semibold text-brand-800 underline">All stories</a>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($stories as $story)
                    <x-success-story-card :story="$story" />
                @endforeach
            </div>
        </section>
    @endif
</div>