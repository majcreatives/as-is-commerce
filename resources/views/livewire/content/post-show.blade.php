<x-container class="mx-auto max-w-3xl space-y-8 py-10">
    <header class="space-y-4">
        <nav aria-label="Breadcrumb" class="text-sm text-slate-600">
            <ol class="flex flex-wrap items-center gap-2">
                <li><a href="{{ route('blog.index') }}" wire:navigate class="hover:text-brand-700">Blog</a></li>
                @if ($post->category)
                    <li aria-hidden="true">/</li>
                    <li>
                        <a href="{{ route('blog.category', $post->category) }}" wire:navigate
                           class="hover:text-brand-700">{{ $post->category->name }}</a>
                    </li>
                @endif
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

    {{-- The body is server-side sanitized before it is stored, so it is safe to
         render as written; the CSS lock step with the editor (prose) is why a
         post looks the same in the form and on the page. --}}
    <div class="prose prose-slate max-w-none">
        {!! $post->body !!}
    </div>

    @if ($post->tags->isNotEmpty())
        <footer class="border-t border-slate-200 pt-5">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm font-medium text-slate-700">Tags:</span>
                @foreach ($post->tags as $tag)
                    <a href="{{ route('blog.tag', $tag) }}" wire:navigate
                       class="rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-700 transition hover:bg-brand-50 hover:text-brand-800">
                        {{ $tag->name }}
                    </a>
                @endforeach
            </div>
        </footer>
    @endif

    @if ($related->isNotEmpty())
        <section class="pt-2">
            <h2 class="mb-4 text-lg font-semibold text-slate-900">Related articles</h2>

            <div class="grid gap-6 sm:grid-cols-2">
                @foreach ($related as $other)
                    <x-post-card :post="$other" />
                @endforeach
            </div>
        </section>
    @endif
</x-container>