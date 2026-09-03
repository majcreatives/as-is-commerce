@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-lg font-semibold transition '
          . 'focus-visible:outline-2 focus-visible:outline-offset-2 '
          . 'disabled:cursor-not-allowed disabled:opacity-60';

    $variants = [
        'primary'   => 'bg-brand-700 text-white hover:bg-brand-800 active:bg-brand-900 shadow-sm',
        'accent'    => 'bg-accent-400 text-brand-950 hover:bg-accent-300 active:bg-accent-500 shadow-sm',
        'secondary' => 'bg-white text-brand-800 ring-1 ring-inset ring-brand-200 hover:bg-brand-50',
        'ghost'     => 'text-brand-800 hover:bg-brand-50',
        'danger'    => 'bg-red-600 text-white hover:bg-red-700 active:bg-red-800 shadow-sm',
    ];

    $sizes = [
        'sm' => 'px-3 py-1.5 text-sm',
        'md' => 'px-4 py-2.5 text-sm',
        'lg' => 'px-6 py-3 text-base',
    ];

    $classes = $base.' '.($variants[$variant] ?? $variants['primary']).' '.($sizes[$size] ?? $sizes['md']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
