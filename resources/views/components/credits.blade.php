@props(['amount'])

{{-- A count of credits.

     Credits are a count, never money. This component exists so that fact is
     enforced by which component a page reaches for: `x-money` renders a
     currency symbol and `x-credits` renders a unit, and neither can be used
     for the other without it being obvious in the markup.

     Never render a credit figure through `x-money`, and never write "GH₵" in
     front of one. 180 credits is not GH₵180, and the platform's whole
     economics depend on nobody reading it that way. --}}

<span {{ $attributes }}>{{ number_format($amount) }} {{ Str::plural('credit', $amount) }}</span>
