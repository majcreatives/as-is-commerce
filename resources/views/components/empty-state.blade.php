@props(['title', 'description' => null])

{{-- Used wherever a feature exists but has no data yet. The platform shows an
     honest empty state rather than placeholder numbers. --}}
<div {{ $attributes->merge(['class' => 'rounded-lg border border-dashed border-slate-300 bg-slate-50/60 px-6 py-10 text-center']) }}>
    <p class="text-sm font-semibold text-slate-700">{{ $title }}</p>

    @if ($description)
        <p class="mx-auto mt-1 max-w-sm text-sm text-slate-500">{{ $description }}</p>
    @endif

    @if (trim($slot) !== '')
        <div class="mt-4">{{ $slot }}</div>
    @endif
</div>
