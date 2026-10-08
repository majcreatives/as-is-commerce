<x-container class="mx-auto max-w-5xl space-y-8 py-10">
    <header class="space-y-2">
        <h1 class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">Blog</h1>
        <p class="text-lg text-slate-600">Latest news, updates and insights from the marketplace.</p>
    </header>

    @if ($posts->count() > 0)
        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($posts as $post)
                <x-post-card :post="$post" />
            @endforeach
        </div>

        {{ $posts->links() }}
    @else
        <x-empty-state>
            <x-slot name="title">No posts published yet</x-slot>
            <x-slot name="description">
                We're working on some great content. Check back soon for updates.
            </x-slot>
        </x-empty-state>
    @endif
</x-container>