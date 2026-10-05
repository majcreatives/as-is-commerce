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
     two booleans. The form inside is the existing newsletter.signup component,
     unmodified, and it already needed Livewire's JavaScript in the footer, so
     nothing about it became less available than it was.

     KEYBOARD AND SCREEN READER. Escape closes from anywhere, the backdrop
     closes, the close button closes, and all three leave the reader where they
     were. Focus moves into the panel when it opens, which is the only way a
     screen reader announces a dialog that appeared on its own. aria-modal plus
     the labelled heading is what makes it a dialog rather than a decorative
     overlay.

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
            x-transition.opacity.duration.200ms
            class="fixed inset-0 z-50 bg-slate-900/60"
            aria-hidden="true"
        ></div>

        <div
            x-show="open"
            x-cloak
            x-transition.duration.200ms
            class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center"
        >
            <div
                x-ref="panel"
                x-on:click.outside="dismiss()"
                tabindex="-1"
                role="dialog"
                aria-modal="true"
                aria-labelledby="newsletter-prompt-title"
                class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl"
            >
                <div class="flex items-start justify-between gap-4">
                    <h2 id="newsletter-prompt-title" class="text-lg font-semibold text-slate-900">
                        Hear about new auctions
                    </h2>

                    {{-- A real button with a name, not a bare cross. A close
                         control a screen reader cannot find is not a close
                         control. --}}
                    <button
                        type="button"
                        x-on:click="dismiss()"
                        class="shrink-0 rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                    >
                        <span class="sr-only">Close</span>
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2"
                             viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                {{-- The frequency-neutral wording and the double opt-in
                     reassurance live in the component, and travel with it. --}}
                <div class="mt-2">
                    <livewire:newsletter.signup />
                </div>
            </div>
        </div>
    </div>
@endunless
