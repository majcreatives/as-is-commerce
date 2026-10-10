@props(['partner'])

{{-- One partner, as published by an administrator.

     A LOGO IS NOT THE ONLY CASE. A partner with no logo is shown by name and
     description, because inventing a placeholder mark would attribute a
     trademark to a business that supplied none.

     THE LINK IS THE PARTNER'S OWN and is left alone by the browser: no
     wire:navigate (it is not a page of ours to navigate), and
     rel="noopener noreferrer" because a partner opens a context we do not
     control. --}}

@php($logo = $partner->logoUrl())

<x-card>
    <div class="flex items-start gap-4">
        @if ($logo)
            <img src="{{ $logo }}" alt="{{ $partner->name }}" loading="lazy" decoding="async"
                 class="h-14 w-14 shrink-0 rounded-lg border border-slate-200 bg-white object-contain p-1" />
        @endif

        <div class="min-w-0">
            @if ($partner->url)
                <a href="{{ $partner->url }}" target="_blank" rel="noopener noreferrer"
                   class="font-semibold text-slate-900 underline decoration-slate-300 underline-offset-4 hover:decoration-slate-500">
                    {{ $partner->name }}
                </a>
            @else
                <p class="font-semibold text-slate-900">{{ $partner->name }}</p>
            @endif

            @if ($partner->description)
                <p class="mt-1 text-sm text-slate-600">{{ $partner->description }}</p>
            @endif
        </div>
    </div>
</x-card>
