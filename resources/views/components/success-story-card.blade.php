@props(['story'])

{{-- One success story, exactly as an administrator entered it.

     A QUOTE, NOT A REVIEW SCORE. Nothing here averages, ranks or summarises
     anything, and nothing is linked to an order: the story is an attributed
     statement, and the page never implies a verified transaction behind it.

     THE PHOTO IS OPTIONAL. A story without one renders as the quote alone
     rather than beside a placeholder image. --}}

@php($image = $story->imageUrl())

<x-card>
    {{-- The whole card is one figure, so the quote and its attribution belong
         to each other rather than being two loose elements. --}}
    <figure>
        @if ($image)
            {{-- Decorative: the words carry the story, so the photo repeats
                 nothing for a screen reader. --}}
            <img src="{{ $image }}" alt="" loading="lazy" decoding="async"
                 class="mb-4 h-40 w-full rounded-lg border border-slate-200 bg-slate-50 object-cover" />
        @endif

        {{-- The words themselves. A blockquote is the honest element here: it is
             marked up as a quotation rather than as a paragraph of our copy. --}}
        <blockquote class="text-sm leading-relaxed text-slate-700">
            <p>&ldquo;{{ $story->quote }}&rdquo;</p>
        </blockquote>

        <figcaption class="mt-4">
            <p class="text-sm font-semibold text-slate-900">{{ $story->name }}</p>

            @if ($story->title)
                <p class="text-sm text-slate-500">{{ $story->title }}</p>
            @endif
        </figcaption>
    </figure>
</x-card>
