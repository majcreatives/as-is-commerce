<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\User\Actions\SendOtp;
use App\Enums\OtpPurpose;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The one-time code delivered by email.
 *
 * Carries only the code and its purpose. No links, no tokens, no credentials:
 * the code alone never signs anybody in, and the page it unlocks authorizes
 * its own effect server-side.
 *
 * Contains the code in plaintext because that is the entire point of an email
 * OTP -- the mail IS the delivery. Everything at rest (the otp_codes row)
 * holds only a hash.
 */
class OtpMail extends Mailable
{
    public function __construct(
        public readonly string $code,
        public readonly OtpPurpose $purpose,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->purpose->label(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.otp',
            with: [
                'code' => $this->code,
                'purposeLabel' => $this->purpose->label(),
                'expiresInMinutes' => SendOtp::TTL_MINUTES,
            ],
        );
    }
}
