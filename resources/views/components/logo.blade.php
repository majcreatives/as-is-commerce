@props(['class' => 'h-8'])

{{-- Wordmark only. An original mark can replace this without touching layouts. --}}
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 font-bold tracking-tight '.$class]) }}>
    <span class="grid size-8 place-items-center rounded-lg bg-brand-700 text-sm font-black text-white">A</span>
    <span class="text-lg text-slate-900">As-Is<span class="text-brand-700">Commerce</span></span>
</span>
