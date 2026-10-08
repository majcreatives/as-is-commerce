@props(['post'])

{{-- One post, as shown on the homepage blog strip.

     SHARED MARKUP. This is the same card the blog listing itself renders inline,
     so a reader lands on a page that looks exactly like the card that sent them
     there. Route through the model, which publishes only when both `active` and
     `published_at` are set -- a post can never be reached while a draft. --}}

<a href="{{ route('blog.show', $post) }}" wire:navigate
   {{ $attributes->merge([
       'class' => 'group flex flex-col rounded-xl border-[5px] border-white/25 bg-white shadow-sm transition hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600',
   ]) }}
   aria-label="{{ $post->title }}">

    <div class="relative flex aspect-4/3 items-center justify-center rounded-t-xl bg-slate-100">
        @if ($post->imageUrl())
            <img src="{{ $post->imageUrl() }}" alt="{{ $post->title }}"
                 class="h-full w-full rounded-t-xl object-cover" loading="lazy">
        @else
            <span class="text-xs font-medium text-slate-400">No image</span>
        @endif
    </div>

    <div class="flex flex-1 flex-col p-4">
        <h3 class="text-sm font-semibold text-slate-900 group-hover:text-brand-800">{{ $post->title }}</h3>

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