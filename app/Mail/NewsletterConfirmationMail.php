<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Confirm this address" -- the email that turns a submitted form into a
 * subscription.
 *
 * This email is the ONLY proof we ever get that somebody owns the inbox, which
 * is why the whole double opt-in design exists. It carries one link, and
 * following it is what sets `consented_at`.
 *
 * IT ALSO CARRIES THE UNSUBSCRIBE LINK, even though nobody is subscribed yet.
 * Some mail clients check for a List-Unsubscribe header on anything that could
 * turn out to be a mailing list, and a confirmation request for a list is
 * exactly the sort of thing a client should let somebody opt out of in one
 * click. Putting it there means the first email we send already honours the
 * header it advertises.
 *
 * NO CADENCE IS PROMISED IN THE SUBJECT OR THE COPY. See the migration's note:
 * nothing in this project can send a newsletter yet, and an email promising a
 * weekly series we cannot currently deliver is the same broken promise as a
 * footer promising one, just delivered to somebody who has already acted on it.
 */
class NewsletterConfirmationMail extends Mailable
{
    public function __construct(
        public readonly string $confirmUrl,
        public readonly string $unsubscribeUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Confirm your subscription',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.newsletter-confirmation',
            with: [
                'confirmUrl' => $this->confirmUrl,
                'unsubscribeUrl' => $this->unsubscribeUrl,
            ],
        );
    }
}
