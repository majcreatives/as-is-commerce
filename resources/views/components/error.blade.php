@props(['messages', 'id' => null])

{{-- A field's validation message.

     Two things make this reachable, not just visible. It carries role="alert",
     so when Livewire swaps the message into the DOM after a failed submit it is
     announced even for a control we cannot wire up -- several forms use a bare
     <input> or a raw <select> inside the field. And it takes an id, so a control
     that CAN be wired points at it with aria-describedby, which is what makes
     the message re-read when focus returns to the field. --}}
@if ($messages)
    <p @if ($id) id="{{ $id }}" @endif
       role="alert"
       {{ $attributes->merge(['class' => 'mt-1.5 text-sm text-red-600']) }}>
        {{ is_array($messages) ? $messages[0] : $messages }}
    </p>
@endif
