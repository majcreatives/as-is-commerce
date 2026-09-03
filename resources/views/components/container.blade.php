@props(['class' => ''])

<div {{ $attributes->merge(['class' => 'mx-auto w-full max-w-6xl px-4 sm:px-6 lg:px-8 '.$class]) }}>
    {{ $slot }}
</div>
