{{-- The newsletter signup, as a modal on the front page.

     IT REPLACED A FORM IN THE FOOTER, and that was a deliberate reversal rather
     than a tidy-up. The footer said asking quietly beats interrupting somebody,
     and that a signup form arriving unasked over the catalogue is a decision to
     interrupt rather than offer. The decision taken is that the interruption is
     worth it -- offered late, once, and not again for a month.

     THE SERVER DECIDES WHETHER TO OFFER IT AT ALL. Signed-in visitors are
     skipped, which also covers staff: the admin area shares this layout, and
     "does not offer a signup form inside the admin screen" is a held test. A
     visitor who has dismissed it in the last thirty days is skipped. In every
     one of those cases this renders NOTHING -- no panel, no backdrop, no Livewire
     payload -- rather than markup that Alpine would immediately hide. The cookie
     answers only "have we already asked"; it decides nothing about subscribing.

     NO LIVEWIRE COMPONENT FOR THE PROMPT ITSELF. It does no server work beyond
     the checks above, and a component would ship state and a payload to carry
     two booleans. The form inside is the existing newsletter.signup component.

     BACKDROP AND CARD ARE ONE LAYER. The blur belongs behind the card, and a
     backdrop-filter only affects what is painted behind the element it sits on,
     so the card stays sharp as a child of it. Separating them bought a second
     full-viewport element and an aria-hidden div that the click-outside handler
     already made redundant.

     overflow-y-auto IS LOAD-BEARING, not decoration. reveal() locks body scroll
     while the panel is open, so without this a card taller than a landscape
     phone could not be scrolled to and its button could not be reached at all.

     KEYBOARD AND SCREEN READER. Escape closes from anywhere, the backdrop
     closes, the close button closes, and all three leave the reader where they
     were. Focus moves into the panel when it opens, which is the only way a
     screen reader announces a dialog that appeared on its own. aria-modal plus
     the labelled heading is what makes it a dialog rather than a decorative
     overlay. The heading's id is held by a test, because it is what the
     aria-labelledby points at.

     The close control is a real <button> with a name, positioned out of the
     flow. A bare cross in the corner is not a close control a screen reader can
     find, and one that a mouse is expected to aim for.

     KNOWN LIMIT, ON PURPOSE: Tab is not trapped inside the panel. Trapping needs
     Alpine's focus plugin, and one dependency is a poor price for a panel a
     reader can leave with Escape or a visible button. --}}

@php
    $newsletterPromptDismissed = request()->cookie('as_is_newsletter_prompt') !== null;
@endphp

@unless (auth()->check() || $newsletterPromptDismissed)
    <div x-data="newsletterPrompt()" x-on:keydown.escape.window="dismiss()">
        <div
            x-show="open"
            x-cloak
            x-transition.duration.200ms
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-900/60 p-4 backdrop-blur-sm"
        >
            <div
                x-ref="panel"
                x-on:click.outside="dismiss()"
                tabindex="-1"
                role="dialog"
                aria-modal="true"
                aria-labelledby="newsletter-prompt-title"
                class="relative flex w-full max-w-md flex-col items-center rounded-xl bg-white p-8 text-center shadow-2xl"
            >
                <button
                    type="button"
                    x-on:click="dismiss()"
                    class="absolute right-4 top-4 rounded-lg p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                >
                    <span class="sr-only">Close</span>
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2"
                         viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>

                {{-- Decorative, so it is hidden from assistive technology and
                     never given a name. The heading below already says what this
                     is. Inline rather than an icon package: two glyphs are not a
                     dependency. --}}
                <svg class="mb-4 h-12 w-12 text-brand-700" fill="none" stroke="currentColor"
                     stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                    <rect width="20" height="16" x="2" y="4" rx="2" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />
                </svg>

                <h2 id="newsletter-prompt-title" class="text-2xl font-bold leading-snug text-slate-900">
                    Don&rsquo;t miss the next great deal
                </h2>

                {{-- No frequency here, and that is load-bearing. Nothing in this
                     project can send a newsletter yet, so promising a weekly
                     drop here would be a promise made to somebody who has only
                     read it. There is a held test for the absence of cadence
                     words across this whole page. --}}
                <p class="mx-auto mb-6 mt-2 max-w-xs text-sm text-slate-500">
                    Be the first to hear about new products, special offers, and live auctions on As-Is-Commerce.
                </p>

                {{-- The heading, description and reassurance above are
                     presentation and live here. The field, the button and what
                     happens after submitting live in the component, which is
                     where the validation and the double opt-in are. --}}
                <livewire:newsletter.signup />
            </div>
        </div>
    </div>
@endunless