<x-container class="mx-auto max-w-5xl space-y-8 py-10">
    <header class="space-y-2">
        <nav aria-label="Breadcrumb" class="text-sm text-slate-600">
            <ol class="flex items-center gap-2">
                <li><a href="{{ route('blog.index') }}" wire:navigate class="hover:text-brand-700">Blog</a></li>
                <li aria-hidden="true">/</li>
                <li aria-current="page" class="font-medium text-slate-900">{{ $tag->name }}</li>
            </ol>
        </nav>
        <h1 class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">Posts tagged “{{ $tag->name }}”</h1>
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
            <x-slot name="title">No posts with this tag yet</x-slot>
            <x-slot name="description">
                Nothing has been tagged {{ $tag->name }} yet. Check back soon.
            </x-slot>
        </x-empty-state>
    @endif
</x-container>