@props(['amount', 'bare' => false])

@php
    // Accepts either a CreditAmount or a raw subcredit count, so the many
    // call sites that pass an integer straight out of a model keep working.
    // The value object owns the formatting either way -- there is one
    // definition of how a credit figure reads, and it is not in a template.
    $creditAmount = $amount instanceof \App\Domain\Credit\ValueObjects\CreditAmount
        ? $amount
        : \App\Domain\Credit\ValueObjects\CreditAmount::fromSubcredits((int) $amount);
@endphp

{{-- A count of credits.

     Credits are a count, never money. This component exists so that fact is
     enforced by which component a page reaches for: `x-money` renders a
     currency symbol and `x-credits` renders a unit, and neither can be used
     for the other without it being obvious in the markup.

     Never render a credit figure through `x-money`, and never write "GH₵" in
     front of one. 180 credits is not GH₵180, and the platform's whole
     economics depend on nobody reading it that way.

     What a customer reads here is the *displayed* figure, which is the raw
     ledger count divided down by CreditAmount::SUBCREDITS_PER_CREDIT. While
     that divisor is 1 the two are the same number.

     `bare` renders the grouped number alone, no unit word -- for a column or
     label that already says "Credits", where repeating the word on every row
     would be clutter rather than clarity. Everywhere else, the unit stays:
     a bare number beside a price is how a credit count gets read as cedis. --}}

<span {{ $attributes }}>{{ $bare ? $creditAmount->formatNumber() : $creditAmount->format() }}</span>
