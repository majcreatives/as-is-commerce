@props(['label', 'name', 'error' => null, 'optional' => false, 'hint' => null])

{{-- Groups label, control, hint and error so every form in the app has the
     same vertical rhythm and the same error placement.

     The hint and the error get ids derived from the field name, because a
     control inside the slot cannot be handed attributes from here and the field
     name is the one thing both sides already agree on. The controls in
     components/input and components/password-input derive the same ids from
     their own id attribute and point at them, which is what carries the message
     to a screen reader when focus lands on the field.

     Dots become dashes: a Livewire-bound name like "form.city" is not a usable
     id token, and the label already targets the control by its own id. --}}
@php
    $controlId = str_replace('.', '-', $name);
    $errorId = $controlId.'-error';
    $hintId = $controlId.'-hint';
@endphp

<div {{ $attributes->merge(['class' => 'space-y-1.5']) }}>
    <x-label :for="$name" :optional="$optional">{{ $label }}</x-label>

    {{ $slot }}

    @if ($hint && ! $error)
        <p id="{{ $hintId }}" class="text-xs text-slate-500">{{ $hint }}</p>
    @endif

    <x-error :id="$errorId" :messages="$error" />
</div>
