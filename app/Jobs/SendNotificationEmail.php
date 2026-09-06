<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\PlatformNotificationMail;
use App\Models\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Send one notification's email, away from the request that caused it.
 *
 * THE ONLY QUEUED WORK ON THIS PLATFORM, AND DELIBERATELY SO. Nothing
 * financial is queued: bids, credit consumption, Buy Now acquisition,
 * inventory, payment verification, order transitions, closure, settlement,
 * refunds and delivery all remain synchronous and transactional. This job
 * carries a message about something that has already happened, and it can fail
 * without any of that becoming untrue.
 *
 * IT RUNS AFTER THE FACT, ALWAYS. The in-app notification row is written and
 * committed before this is dispatched, so a customer signing in sees the event
 * whether or not the email ever leaves. The email is supplementary.
 *
 * IDEMPOTENT ENOUGH FOR A RETRY. The row is re-read by id and skipped if it
 * has already been marked sent, so a redelivery does not send twice. It is
 * keyed on the notification rather than on the message text, for the same
 * reason the notification itself is: wording changes without the event
 * changing.
 *
 * IT NEVER THROWS INTO ANYTHING THAT MATTERS. A failure is recorded on the
 * notification row and logged, exactly as the inline path does, so the staff
 * notification screen reads the same either way.
 */
class SendNotificationEmail implements ShouldQueue
{
    use Queueable;

    /**
     * Three attempts, then give up and leave the reason on the row.
     *
     * A transient mail outage deserves a retry; a rejected address does not
     * deserve to be retried for ever.
     */
    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        /** The id rather than the model: the row may be gone by the time a worker runs. */
        private readonly string $notificationId,
        private readonly string $email,
    ) {
        $this->onQueue(config('notifications.queue'));
    }

    public function handle(): void
    {
        $notification = Notification::find($this->notificationId);

        if ($notification === null) {
            // Nothing to describe any more. Not an error.
            return;
        }

        if ($notification->mail_status === 'sent') {
            // Already delivered, and this is a redelivery of the job.
            return;
        }

        try {
            Mail::to($this->email)->send(new PlatformNotificationMail($notification));

            $notification->mail_status = 'sent';
            $notification->mail_sent_at = now();
            $notification->save();
        } catch (Throwable $e) {
            $notification->mail_status = 'failed';
            $notification->mail_failure_reason = mb_substr($e->getMessage(), 0, 500);
            $notification->save();

            Log::warning('Notification email failed', [
                'operation' => 'notification.mail_failed',
                'notification_id' => $notification->id,
                'event_type' => $notification->event_type,
                'exception' => $e::class,
                // The message, not the payload: an exception from a mail
                // transport can carry the whole rendered email.
                'reason' => $e->getMessage(),
            ]);

            // Rethrown so the queue can retry it. The business event is long
            // committed; only the message is in doubt.
            throw $e;
        }
    }
}
