@props(['active' => false])

{{-- A navigation link. The active one is marked with aria-current="page", not
     only with colour: a customer who cannot see that the background is tinted
     still needs to know where they are, and aria-current is what tells a screen
     reader the link points at the page being viewed.

     The focus ring is here too rather than on every caller. This is the most
     repeated interactive element in the product and it had none of its own, so
     a keyboard user tabbing the header could not see where they were. --}}
<a @if ($active) aria-current="page" @endif
   {{ $attributes->merge([
       'class' => 'rounded-md px-3 py-2 text-sm font-medium transition '
                . 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 '
                . ($active
                    ? 'bg-brand-50 text-brand-800'
                    : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'),
   ]) }}>
    {{ $slot }}
</a>
