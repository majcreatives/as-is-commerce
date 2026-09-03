@props(['messages'])

@if ($messages)
    <p {{ $attributes->merge(['class' => 'mt-1.5 text-sm text-red-600']) }}>
        {{ is_array($messages) ? $messages[0] : $messages }}
    </p>
@endif
