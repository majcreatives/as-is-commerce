<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The email form of a notification.
 *
 * One mailable for every type rather than one per type: the notification row
 * already carries the title, the message and the link, and fourteen classes
 * differing only in their subject line would be fourteen places to keep the
 * same wording consistent.
 *
 * NOTHING SENSITIVE TRAVELS. The template renders the title, the message and
 * an optional link into the application. No payment credentials, no card
 * details, no tokens, and no other customer's contact information -- the
 * message text is written for the recipient and says only what they can
 * already see on their own pages.
 *
 * The default mailer is `log`, so this works in development and in tests
 * without a provider and without pretending an email was delivered.
 */
class PlatformNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Notification $notification,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->notification->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.notification',
            with: [
                'title' => $this->notification->title,
                'body' => $this->notification->message,
                'actionUrl' => $this->actionUrl(),
                'actionLabel' => $this->notification->action_label,
            ],
        );
    }

    /**
     * The link, made absolute for an email client.
     *
     * Stored as a path so it stays correct across environments; only here does
     * it need a host. The page it reaches still does its own authorization --
     * a link in an inbox is not a capability.
     */
    private function actionUrl(): ?string
    {
        $path = $this->notification->action_url;

        if ($path === null) {
            return null;
        }

        return str_starts_with($path, 'http') ? $path : url($path);
    }
}
