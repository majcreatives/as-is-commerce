<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\NewsletterConfirmationMail;
use App\Models\NewsletterSubscriber;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Send one address its confirmation link.
 *
 * QUEUED FOR THE SAME REASON AS EVERY OTHER EMAIL HERE: a slow or broken mail
 * transport must not hold up a request, and nothing financial depends on it
 * landing. A subscriber who never receives this email is simply `pending` --
 * an honest, visible state -- and can ask for another.
 *
 * NOTHING IS SUBSCRIBED HERE. The row is already committed as `pending` before
 * this job is dispatched, and confirming is a separate act by the address
 * owner. A job that quietly subscribed the row would make the confirmation link
 * decorative.
 *
 * THE ROW IS RE-READ BY ID AND THE ROW'S OWN STATE IS THE IDEMPOTENCY CHECK.
 * If the address has since been confirmed or unsubscribed, the confirmation
 * token is gone, and this delivery stops. That matters on retries: a job that
 * queued, failed, and retried an hour later must not send a link for a
 * subscription that has already moved on.
 */
class SendNewsletterConfirmation implements ShouldQueue
{
    use Queueable;

    /** Three attempts, then stop and say why. */
    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        /**
         * The id, not the model: the row's state is what decides whether to send.
         *
         * Public so a test can assert the dispatch carried the right row without
         * reaching into a private property.
         */
        public readonly string $subscriberId,
    ) {
        $this->onQueue(config('notifications.queue'));
    }

    public function handle(): void
    {
        $subscriber = NewsletterSubscriber::find($this->subscriberId);

        if ($subscriber === null) {
            // Nothing to confirm any more. Not an error.
            return;
        }

        // Already confirmed, already left, or re-requested and token-rotated.
        // In every case the link this job would send is dead on arrival.
        if (! $subscriber->awaitsConfirmation()) {
            return;
        }

        try {
            Mail::to($subscriber->email)->send(new NewsletterConfirmationMail(
                confirmUrl: $subscriber->confirmationUrl(),
                unsubscribeUrl: $subscriber->unsubscribeUrl(),
            ));
        } catch (Throwable $e) {
            Log::warning('Newsletter confirmation email failed', [
                'operation' => 'newsletter.confirmation_failed',
                'subscriber_id' => $subscriber->id,
                'exception' => $e::class,
                // The message, not the payload: an exception from a mail
                // transport can carry the whole rendered email, token included.
                'reason' => $e->getMessage(),
            ]);

            // Rethrown so the queue can retry. The subscriber is still `pending`,
            // which is true regardless of what the mail transport did.
            throw $e;
        }
    }
}
