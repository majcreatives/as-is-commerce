@props(['label', 'title', 'toggle' => null, 'toggleAttributes' => []])

{{-- One formatting button for the x-editor toolbar. The `toggle` name (and
     optional attributes) is passed to the Alpine helpers, which re-evaluate on
     every ProseMirror transaction to paint the active state, and to
     `aria-pressed` so a screen reader hears the same truth the button shows.
     The click handler is forwarded through the attributes bag. --}}
<button type="button"
    {{ $attributes->merge(['class' => 'rounded-md px-2 py-1 text-xs font-semibold ring-1 ring-inset ring-transparent transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600']) }}
    x-bind:class="toolbarClass(@js($toggle), @js($toggleAttributes))"
    :aria-pressed="toggle ? isActive(@js($toggle), @js($toggleAttributes)) : undefined"
    title="{{ $title }}"
    aria-label="{{ $title }}">
    {{ $label }}
</button>