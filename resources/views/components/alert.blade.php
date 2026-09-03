@props(['variant' => 'info'])

@php
    $variants = [
        'info'    => 'bg-brand-50 text-brand-900 ring-brand-200',
        'success' => 'bg-emerald-50 text-emerald-900 ring-emerald-200',
        'warning' => 'bg-accent-50 text-accent-900 ring-accent-200',
        'danger'  => 'bg-red-50 text-red-900 ring-red-200',
    ];
@endphp

<div role="alert"
     {{ $attributes->merge(['class' => 'rounded-lg px-4 py-3 text-sm ring-1 ring-inset '.($variants[$variant] ?? $variants['info'])]) }}>
    {{ $slot }}
</div>
