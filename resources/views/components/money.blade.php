@props(['amount'])

{{-- A monetary amount with the symbol the settings say to use.

     Money::format() deliberately returns digits only -- a currency symbol
     belongs to the presentation layer, not to a stored value. This component
     is that layer, in one place, so every screen writes an amount the same way.

     Only ever used for money. A count of credits is not money and must never
     be rendered through here. --}}

<span {{ $attributes }}>{{ settings()->getString('currency_symbol', 'GH₵') }} {{ $amount->format() }}</span>
