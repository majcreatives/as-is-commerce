<x-container class="mx-auto max-w-5xl space-y-8 py-10">
    <header class="space-y-2">
        <h1 class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">Blog</h1>
        <p class="text-lg text-slate-600">Latest news, updates and insights from the marketplace.</p>
    </header>

    @if ($posts->count() > 0)
        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($posts as $post)
                <a href="{{ route('blog.show', $post) }}" wire:navigate class="group flex flex-col rounded-xl border-[5px] border-white/25 bg-white shadow-sm transition hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                    <div class="relative flex aspect-4/3 items-center justify-center rounded-t-xl bg-slate-100">
                        @if ($post->imageUrl())
                            <img src="{{ $post->imageUrl() }}" alt="{{ $post->title }}" class="h-full w-full rounded-t-xl object-cover" loading="lazy" />
                        @else
                            <span class="text-xs font-medium text-slate-400">No image</span>
                        @endif
                    </div>
                    <div class="flex flex-1 flex-col p-4">
                        <h2 class="text-sm font-semibold text-slate-900 group-hover:text-brand-800">{{ $post->title }}</h2>
                        @if ($post->excerpt)
                            <p class="mt-2 line-clamp-3 flex-1 text-sm text-slate-600">{{ $post->excerpt }}</p>
                        @endif
                        <div class="mt-3 flex items-center justify-between border-t border-slate-100 pt-3 text-sm text-slate-500">
                            <time datetime="{{ $post->published_at?->toISOString() }}">
                                {{ $post->published_at?->format('M j, Y') }}
                            </time>
                        </div>
                    </div>
                </a>
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
