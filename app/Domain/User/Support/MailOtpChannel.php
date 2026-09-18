<?php

declare(strict_types=1);

namespace App\Domain\User\Support;

use App\Domain\User\Contracts\OtpChannel;
use App\Domain\User\Exceptions\OtpDeliveryException;
use App\Domain\User\ValueObjects\OtpDelivery;
use App\Mail\OtpMail;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * One-time codes delivered by email.
 *
 * The recipient address comes from the delivery, which the issue flow fills
 * from the account's own stored email -- never from browser input. Delivery is
 * synchronous: a verification code that sat in a queue would become a code the
 * user could not use yet, so nothing is deferred.
 *
 * Uses the application's configured mailer as-is. Where that mailer is `log`
 * (development and tests) the code lands in the log rather than being
 * silently dropped -- a fake success is not permitted.
 */
final class MailOtpChannel implements OtpChannel
{
    public function send(OtpDelivery $delivery): void
    {
        $email = $delivery->email;

        if ($email === null || $email === '') {
            throw OtpDeliveryException::noDestination();
        }

        // A broken transport (SMTP down, connection refused...) surfaces as a
        // thrown exception from the mailer. That is exactly what must reach
        // the issue flow: an undelivered code must fail loudly and be rolled
        // back, never silently "sent". Successful sends return normally.
        try {
            Mail::to($email)->send(new OtpMail($delivery->code, $delivery->purpose));
        } catch (Throwable $e) {
            throw OtpDeliveryException::deliveryFailed($e);
        }
    }
}
