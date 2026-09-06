<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Enums\NotificationType;
use App\Jobs\SendNotificationEmail;
use App\Mail\PlatformNotificationMail;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one way anything tells a customer anything.
 *
 * NOTIFICATIONS ARE INFORMATIONAL, NEVER AUTHORITATIVE. Nothing in this class
 * can fail a bid, a credit consumption, an auction closure, a payment
 * verification, an order or an inventory sale. Every failure path here ends in
 * a log line and a return, because the alternative -- a customer's payment
 * rolled back because an SMTP server was slow -- is not a trade anyone would
 * make.
 *
 * That guarantee only holds if callers respect one rule: **dispatch after the
 * transaction has committed**, never inside it. A notification written inside
 * a transaction that later rolls back would tell somebody about an event that
 * did not happen, and one that throws inside a transaction would take the
 * transaction with it.
 *
 * IDEMPOTENCY IS THE DATABASE'S JOB. Every notification carries an
 * `event_key` derived from the business event and its recipient -- never from
 * the message text, which may be reworded without changing what happened. A
 * unique index turns "this webhook arrived five times" into one notification,
 * and it does so without an application check that two simultaneous retries
 * could both pass.
 *
 * EMAIL IS SUPPLEMENTARY. The in-app notification is the notification; mail is
 * an extra. It is attempted after the row exists, failures are recorded on
 * that row rather than raised, and a customer whose email bounces still has
 * everything waiting for them when they sign in.
 */
class NotificationDispatcher
{
    /**
     * Tell one person about one thing.
     *
     * @param  string|null  $eventKey  Deterministic identity for this event and
     *                                 this recipient. Supplied for anything a
     *                                 provider or a sweep may deliver twice;
     *                                 null only where a repeat is genuinely a
     *                                 separate occurrence.
     * @param  array<string, mixed>  $context  Identifiers the interface needs.
     *                                         Never credentials, never payment
     *                                         details, never another person's
     *                                         contact information.
     * @return Notification|null The notification, or null when none was
     *                           written -- a duplicate, or a preference.
     */
    public function send(
        User $recipient,
        NotificationType $type,
        string $title,
        string $message,
        ?string $eventKey = null,
        ?string $actionUrl = null,
        ?string $actionLabel = null,
        array $context = [],
    ): ?Notification {
        $preferences = $recipient->notificationPreferences();

        if (! $preferences->allowsInApp($type)) {
            // Switched off, and permitted to be: a transactional type would
            // have returned true regardless.
            return null;
        }

        $notification = $this->record(
            $recipient, $type, $title, $message, $eventKey, $actionUrl, $actionLabel, $context,
        );

        if ($notification === null) {
            // Already told them. Not an error -- the whole point of the key.
            return null;
        }

        if ($preferences->allowsEmail($type) && $this->hasEmail($recipient)) {
            $this->attemptEmail($recipient, $notification);
        }

        return $notification;
    }

    /**
     * Write the in-app notification, or recognise it as one already sent.
     *
     * The unique index does the recognising, not a preceding SELECT, which
     * would race two simultaneous deliveries of the same event.
     *
     * @param  array<string, mixed>  $context
     */
    private function record(
        User $recipient,
        NotificationType $type,
        string $title,
        string $message,
        ?string $eventKey,
        ?string $actionUrl,
        ?string $actionLabel,
        array $context,
    ): ?Notification {
        try {
            $notification = new Notification;

            $notification->id = (string) Str::uuid();
            // The framework stores the notification class here. This platform
            // sends one shape of notification and distinguishes them by
            // event_type, so the column records that shape.
            $notification->type = PlatformNotificationMail::class;
            $notification->notifiable_type = $recipient->getMorphClass();
            $notification->notifiable_id = $recipient->getKey();
            $notification->event_type = $type->value;
            $notification->event_key = $eventKey;
            $notification->data = [
                'title' => $title,
                'message' => $message,
                'action_url' => $actionUrl,
                'action_label' => $actionLabel,
                'context' => $context,
            ];
            $notification->save();

            return $notification;
        } catch (UniqueConstraintViolationException) {
            Log::info('Skipped a duplicate notification', [
                'operation' => 'notification.duplicate',
                'event_type' => $type->value,
                'event_key' => $eventKey,
                'user_id' => $recipient->id,
            ]);

            return null;
        } catch (Throwable $e) {
            // Even writing the row must not take a caller down with it. The
            // business event has already committed; losing the notification is
            // a worse outcome than the alternative only if the alternative is
            // losing the event, which it is not.
            Log::error('Could not record a notification', [
                'operation' => 'notification.record_failed',
                'event_type' => $type->value,
                'user_id' => $recipient->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Try to email it, and write down what happened either way.
     *
     * TWO MODES, AND THE DEFAULT IS THE OLD ONE. Inline unless
     * `notifications.queue_mail` says otherwise, because queued mail needs a
     * running worker for anybody to hear anything and the initial production
     * target offers cron rather than persistent processes. A deployment
     * without a worker therefore behaves exactly as before rather than going
     * quiet.
     *
     * Where a worker does exist, switching it on takes the SMTP round trips
     * out of `auctions:tick` -- which closes auctions and emails their
     * winners, on a schedule of every minute.
     *
     * NEITHER MODE PUTS EMAIL IN FRONT OF ANYTHING THAT MATTERS. The in-app
     * notification is already written and committed by the time this runs, and
     * a failure is recorded on that row rather than raised.
     */
    private function attemptEmail(User $recipient, Notification $notification): void
    {
        if (config('notifications.queue_mail') === true) {
            $this->queueEmail($recipient, $notification);

            return;
        }

        try {
            Mail::to($recipient->email)->send(new PlatformNotificationMail($notification));

            $notification->mail_status = 'sent';
            $notification->mail_sent_at = now();
            $notification->save();
        } catch (Throwable $e) {
            // Recorded, never raised. A mail server being unreachable is not a
            // reason for a customer's order to fail, and the in-app
            // notification is already saved and visible.
            $notification->mail_status = 'failed';
            $notification->mail_failure_reason = mb_substr($e->getMessage(), 0, 500);
            $notification->save();

            Log::warning('Notification email failed', [
                'operation' => 'notification.mail_failed',
                'notification_id' => $notification->id,
                'event_type' => $notification->event_type,
                'user_id' => $recipient->id,
                'exception' => $e::class,
                // The message, not the payload: an exception from a mail
                // transport can carry the whole rendered email.
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Hand the email to the queue, and say so on the row.
     *
     * `queued` is an honest third state beside `sent` and `failed`: the
     * message has not left yet, and claiming either would be wrong. The worker
     * replaces it with the outcome.
     *
     * Dispatch itself is guarded. A queue that refuses the job -- an
     * unreachable driver, a full table -- must not take down the business
     * event that is already committed, so it is recorded as a failure and the
     * caller never hears about it.
     */
    private function queueEmail(User $recipient, Notification $notification): void
    {
        try {
            $notification->mail_status = 'queued';
            $notification->save();

            SendNotificationEmail::dispatch($notification->id, (string) $recipient->email);
        } catch (Throwable $e) {
            $notification->mail_status = 'failed';
            $notification->mail_failure_reason = mb_substr($e->getMessage(), 0, 500);
            $notification->save();

            Log::warning('Notification email could not be queued', [
                'operation' => 'notification.mail_queue_failed',
                'notification_id' => $notification->id,
                'event_type' => $notification->event_type,
                'user_id' => $recipient->id,
                'exception' => $e::class,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Whether there is anywhere to send an email.
     *
     * Email is optional on this platform -- phone is the primary identity --
     * so most accounts legitimately have none, and that is not a failure.
     */
    private function hasEmail(User $recipient): bool
    {
        return is_string($recipient->email) && $recipient->email !== '';
    }
}
