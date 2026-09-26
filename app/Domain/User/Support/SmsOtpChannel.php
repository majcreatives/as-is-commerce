<?php

declare(strict_types=1);

namespace App\Domain\User\Support;

use App\Domain\User\Contracts\OtpChannel;
use App\Domain\User\Contracts\SmsGateway;
use App\Domain\User\Exceptions\OtpDeliveryException;
use App\Domain\User\Exceptions\SmsGatewayError;
use App\Domain\User\ValueObjects\OtpDelivery;
use Throwable;

/**
 * One-time codes delivered by text message to a phone number.
 *
 * The recipient number comes from the delivery, which the issue flow fills
 * from the account's own stored phone -- never from browser input. Delivery is
 * synchronous: a code that sat in a queue would be a code the user could not
 * use yet, so nothing is deferred.
 *
 * Failing loudly is the point. A provider outage, an unregistered sender or a
 * rejected destination must all throw, so the transaction that issued the code
 * rolls it back rather than leaving a code in the database that no handset
 * will ever receive. A customer who is told "we sent you a code" and never
 * gets one is locked out of the very action the code was meant to unlock, and
 * that failure is invisible to everyone but them.
 *
 * Formatting the message is kept here rather than in the provider adapter, so
 * switching gateways does not change what a customer reads.
 */
final class SmsOtpChannel implements OtpChannel
{
    public function __construct(
        private readonly SmsGateway $gateway,
    ) {}

    public function send(OtpDelivery $delivery): void
    {
        $phone = $delivery->phone;

        if ($phone === null || $phone === '') {
            throw OtpDeliveryException::noDestinationForSms();
        }

        $message = sprintf(
            'Your %s code is %s.%s If you did not ask for this, ignore this message.',
            $delivery->purpose->label(),
            $delivery->code,
            $this->expiryClause($delivery),
        );

        try {
            $this->gateway->send($phone, $message);
        } catch (SmsGatewayError $e) {
            // A provider failure is a delivery failure as far as the issue flow
            // is concerned: the code must not survive it.
            throw OtpDeliveryException::deliveryFailed($e);
        } catch (Throwable $e) {
            throw OtpDeliveryException::deliveryFailed($e);
        }
    }

    /**
     * A sentence stating how long the code lasts, or nothing at all.
     *
     * Derived from the delivery's own expiry so the text cannot promise a
     * window the server does not honour. Returns an empty string when the
     * caller did not say, because "it expires in 10 minutes" is better than
     * a confident guess -- but silence about expiry is better than a lie.
     */
    private function expiryClause(OtpDelivery $delivery): string
    {
        if ($delivery->expiresAt === null) {
            return '';
        }

        $seconds = $delivery->expiresAt->getTimestamp() - now()->getTimestamp();

        $minutes = max(1, (int) ceil($seconds / 60));

        return sprintf(' It expires in %d %s.', $minutes, $minutes === 1 ? 'minute' : 'minutes.');
    }
}
