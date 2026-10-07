<x-container class="mx-auto max-w-3xl space-y-8 py-10">
    <header class="space-y-4">
        <nav aria-label="Breadcrumb" class="text-sm text-slate-600">
            <ol class="flex items-center gap-2">
                <li><a href="{{ route('blog.index') }}" wire:navigate class="hover:text-brand-700">Blog</a></li>
                <li aria-hidden="true">/</li>
                <li aria-current="page" class="line-clamp-1 font-medium text-slate-900">{{ $post->title }}</li>
            </ol>
        </nav>
        <h1 class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">{{ $post->title }}</h1>
        @if ($post->published_at)
            <time datetime="{{ $post->published_at->toISOString() }}" class="text-sm text-slate-600">
                Published {{ $post->published_at->format('M j, Y') }}
            </time>
        @endif
    </header>

    @if ($post->imageUrl())
        <figure class="overflow-hidden rounded-xl border border-slate-200 bg-slate-100">
            <img src="{{ $post->imageUrl() }}" alt="{{ $post->title }}" class="h-full w-full object-cover object-center" />
        </figure>
    @endif

    @if ($post->excerpt)
        <p class="text-lg leading-relaxed text-slate-700">{{ $post->excerpt }}</p>
    @endif

    <div class="prose prose-slate max-w-none">
        {!! nl2br(e($post->body)) !!}
    </div>
</x-container>
