/*
 * The newsletter signup prompt.
 *
 * A modal, on the front page only, and only for a visitor who is neither
 * signed in nor has already dismissed it -- both of those are decided on the
 * server, so a visitor in either state is never sent the markup at all.
 *
 * WAITED, NOT SHOWN ON ARRIVAL. The reader is given the hero and a moment of
 * the catalogue first. A modal that covers the shop the moment it loads is the
 * thing the footer copy used to argue against, and this is the compromise: long
 * enough to have looked at the page, short enough to still be worth reading.
 *
 * DISMISSAL IS A THIRTY DAY COOKIE, written on every way out. Nothing about
 * subscribing depends on it. It only answers one question -- have we already
 * asked this person -- and a "no" must not become a permanent "never".
 *
 * Degrades by doing nothing: the prompt is a promotional extra, so if this file
 * is missing or throws, the page it sits on is unchanged.
 */

const DELAY_MS = 8000;

const COOKIE_NAME = 'as_is_newsletter_prompt';

const THIRTY_DAYS = 60 * 60 * 24 * 30;

export function newsletterPrompt(delay = DELAY_MS) {
    return {
        open: false,

        init() {
            // Not on a zero delay, so the markup can be read and reasoned about
            // the same way whether it is shown on load or on a timer.
            if (delay > 0) {
                this.timer = setTimeout(() => this.reveal(), delay);
            } else {
                this.reveal();
            }
        },

        reveal() {
            // There is no trigger to go back to -- this appears on a timer, not
            // on a click -- so what gets focus back is whatever the reader had
            // reached before the panel took the page off them. If they had
            // reached nothing, this is null and focus simply goes to the body.
            this.returnFocusTo = document.activeElement;
            this.open = true;
            // Locking the page behind the panel stops a touch on the catalogue
            // underneath scrolling it, which is disorienting and looks like a
            // bug. Removed again by dismiss() and by destroy().
            document.body.classList.add('overflow-hidden');
            this.$nextTick(() => this.$refs.panel?.focus());
        },

        dismiss() {
            if (!this.open) {
                return;
            }

            this.open = false;
            document.body.classList.remove('overflow-hidden');
            this.remember();

            const target = this.returnFocusTo;
            this.$nextTick(() => {
                if (target && target.isConnected) {
                    target.focus();
                }
            });
        },

        remember() {
            document.cookie = `${COOKIE_NAME}=1; max-age=${THIRTY_DAYS}; path=/; SameSite=Lax`;
        },

        /**
         * If the reader navigates away with the panel open, the scroll lock must
         * not survive them onto the next page.
         */
        destroy() {
            clearTimeout(this.timer);
            document.body.classList.remove('overflow-hidden');
        },
    };
}
