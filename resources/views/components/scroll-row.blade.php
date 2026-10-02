@props(['label'])

{{-- A row of cards that is a single swipeable line on a phone and an ordinary
     grid from `sm` up.

     WHY A SCROLLER AND NOT A ONE-COLUMN GRID. At the old one-column mobile grid
     the homepage rows stacked eight cards into eight screenfuls, and the second
     row was far enough down that nobody reached it. A line you swipe keeps the
     whole row to roughly one screen, and phones are built for swiping.

     THE SWIPER IS ONLY THE MOBILE LAYOUT. From `sm` up this is a normal grid
     again, so nothing about the desktop page changed -- the same cards, the same
     order, the same links.

     SCROLLING IT WITH A KEYBOARD IS NOT OPTIONAL. A horizontally scrollable box
     is not reachable with Tab on its own, so without `tabindex="0"` a keyboard
     user cannot get to the cards past the first one at all. `role="group"` with
     a label gives the stop a name when focus lands on it; the cards inside stay
     ordinary links in the ordinary tab order. This is a progressive
     enhancement: with CSS off it is a plain list of links.

     `snap-mandatory` is what makes a swipe land on a card rather than halfway
     between two, and `overscroll-x-contain` stops a horizontal fling at the end
     of the row from dragging the rest of the page sideways with it.

     THE CARD WIDTH LIVES HERE, NOT AT EACH CALL SITE. The `[&>*]` variants size
     whatever is dropped into the row, so a caller cannot accidentally add a card
     that stretches the full width and breaks the line into one-card-per-swipe.
     72vw leaves a sliver of the next card showing, which is what tells somebody
     there is more to swipe to at all -- a row that ends exactly at the edge of
     the screen reads as the end of the list. --}}

<div role="group"
     tabindex="0"
     aria-label="{{ $label }}"
     class="mt-4 flex snap-x snap-mandatory gap-3 overflow-x-auto overscroll-x-contain
            pb-2 [&>*]:w-[72vw] [&>*]:max-w-[17rem] [&>*]:shrink-0 [&>*]:snap-start
            sm:grid sm:grid-cols-2 sm:gap-4 sm:overflow-x-visible sm:pb-0
            sm:[&>*]:w-auto sm:[&>*]:max-w-none lg:grid-cols-4">
    {{ $slot }}
</div>