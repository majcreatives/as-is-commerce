@props(['label', 'name', 'error' => null, 'optional' => false, 'hint' => null])

{{-- Groups label, control, hint and error so every form in the app has the
     same vertical rhythm and the same error placement. --}}
<div {{ $attributes->merge(['class' => 'space-y-1.5']) }}>
    <x-label :for="$name" :optional="$optional">{{ $label }}</x-label>

    {{ $slot }}

    @if ($hint && ! $error)
        <p class="text-xs text-slate-500">{{ $hint }}</p>
    @endif

    <x-error :messages="$error" />
</div>
