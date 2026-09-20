/*
 * The product image viewer.
 *
 * PRESENTATION ONLY. Nothing here reads or decides anything about price,
 * stock, an auction or an order. It moves between pictures the server already
 * rendered into the page.
 *
 * IT MUST DEGRADE. The featured image and the thumbnails are real markup, so
 * a page with this file absent, blocked or still loading shows the product
 * exactly as it did before the viewer existed. Opening the large view is the
 * enhancement; seeing the product is not.
 *
 * The index arithmetic and the swipe threshold are exported on their own so
 * they can be tested without a browser (`npm run test:js`), in the same shape
 * as the auction stream's ordering rules.
 */

/** How far a finger must travel before it counts as a swipe rather than a tap. */
export const SWIPE_THRESHOLD_PX = 40;

/**
 * The next image, wrapping round at the end.
 *
 * Wrapping rather than stopping: a gallery of three that refuses to advance
 * from the third looks broken, and there is nothing to lose by cycling.
 */
export function nextIndex(current, total) {
    if (total <= 0) {
        return 0;
    }

    return (current + 1) % total;
}

/** The previous image, wrapping round at the start. */
export function previousIndex(current, total) {
    if (total <= 0) {
        return 0;
    }

    return (current - 1 + total) % total;
}

/**
 * Which way a touch gesture went, or null when it was too small to mean one.
 *
 * A horizontal distance only. A gesture that travelled further vertically is
 * somebody scrolling the page, and stealing it would make the product page
 * impossible to read on a phone.
 */
export function swipeDirection(start, end, threshold = SWIPE_THRESHOLD_PX) {
    const dx = end.x - start.x;
    const dy = end.y - start.y;

    if (Math.abs(dx) < threshold || Math.abs(dx) <= Math.abs(dy)) {
        return null;
    }

    return dx < 0 ? 'next' : 'previous';
}

/**
 * The Alpine component behind the gallery and its large view.
 *
 * `total` is rendered by the server from the images it actually emitted, so
 * the browser is never counting DOM nodes to decide what exists.
 */
export function productGallery(total = 0) {
    return {
        total,
        active: 0,
        open: false,

        // Where focus was before the viewer opened, so it can be put back.
        // A dialog that returns focus to the top of the document makes a
        // keyboard user start the page again.
        returnFocusTo: null,

        touchStart: null,

        show(index) {
            if (index >= 0 && index < this.total) {
                this.active = index;
            }
        },

        next() {
            this.active = nextIndex(this.active, this.total);
        },

        previous() {
            this.active = previousIndex(this.active, this.total);
        },

        openViewer(index = null) {
            if (this.total === 0) {
                return;
            }

            if (index !== null) {
                this.show(index);
            }

            this.returnFocusTo = document.activeElement;
            this.open = true;

            // The page behind a full-screen viewer must not scroll under it.
            document.body.style.overflow = 'hidden';

            this.$nextTick(() => this.$refs.closeButton?.focus());
        },

        closeViewer() {
            if (!this.open) {
                return;
            }

            this.open = false;
            document.body.style.overflow = '';

            const target = this.returnFocusTo;
            this.returnFocusTo = null;

            if (target && typeof target.focus === 'function') {
                target.focus();
            }
        },

        /**
         * Keep Tab inside the dialog while it is open.
         *
         * Without this, tabbing walks out of a full-screen overlay into the
         * page underneath it, which a sighted keyboard user cannot see and a
         * screen-reader user is told nothing about.
         */
        trapTab(event) {
            const focusable = this.$refs.dialog?.querySelectorAll(
                'button:not([disabled]), [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
            );

            if (!focusable || focusable.length === 0) {
                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();

                return;
            }

            if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },

        onTouchStart(event) {
            const touch = event.changedTouches?.[0];

            this.touchStart = touch ? { x: touch.clientX, y: touch.clientY } : null;
        },

        onTouchEnd(event) {
            const touch = event.changedTouches?.[0];

            if (!this.touchStart || !touch) {
                return;
            }

            const direction = swipeDirection(this.touchStart, {
                x: touch.clientX,
                y: touch.clientY,
            });

            this.touchStart = null;

            if (direction === 'next') {
                this.next();
            } else if (direction === 'previous') {
                this.previous();
            }
        },
    };
}
