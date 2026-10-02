<div>
    <x-page-header
        title="Success stories"
        description="What customers have said about shopping and winning with us." />

    {{-- Same reasoning as the partners page: a sample testimonial with a
         sample name would be a fabricated claim about a person. The empty state
         is what an unpopulated section looks like. --}}
    @if ($stories->isEmpty())
        <x-empty-state
            title="No stories published yet"
            description="Customer stories will appear here once they have been published." />
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($stories as $story)
                <x-success-story-card :story="$story" />
            @endforeach
        </div>

        <div class="mt-6">{{ $stories->links() }}</div>
    @endif
</div>
