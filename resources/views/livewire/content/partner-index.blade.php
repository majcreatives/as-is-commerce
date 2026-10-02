<div>
    <x-page-header
        title="Partners"
        description="Businesses that work with As-Is Commerce." />

    {{-- Nothing published yet says so. An example partner would be a claim
         about a real business that we cannot make, so the empty state is the
         honest state and the page simply says it. --}}
    @if ($partners->isEmpty())
        <x-empty-state
            title="No partners listed yet"
            description="Partners will appear here once they have been published." />
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($partners as $partner)
                <x-partner-card :partner="$partner" />
            @endforeach
        </div>

        <div class="mt-6">{{ $partners->links() }}</div>
    @endif
</div>
