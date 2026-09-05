@props(['auction'])

{{-- What state an auction is in, in the customer's own terms.

     The status enum supplies the wording, except for one case it cannot know:
     a settled auction that ended because somebody bought the product outright
     is not "won" in the bidding sense, and saying so to the people who were
     bidding would be misleading. That is a fact about the auction, so it is
     decided here.

     The Buy Now buyer is never named. --}}

@php
    $label = $auction->endedByBuyNow()
        ? 'Sold via Buy Now'
        : $auction->status->customerLabel();
@endphp

<x-badge :classes="$auction->status->badgeClasses()" {{ $attributes }}>{{ $label }}</x-badge>
