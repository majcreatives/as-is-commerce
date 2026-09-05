<?php

declare(strict_types=1);

namespace App\Domain\Payments\ValueObjects;

/**
 * The provider's own account of a refund.
 *
 * The only evidence the platform accepts that money went back. An
 * administrator clicking a button, an HTTP request being accepted, or a
 * reference typed into a form are none of them proof: a refund is recorded as
 * succeeded on the strength of this object and nothing else.
 *
 * THE STATUS IS THE PROVIDER'S WORD, KEPT VERBATIM. Paystack settles refunds
 * asynchronously and reports `pending`, `processing`, `processed`, `failed` or
 * `reversed`. The interpretation lives in {@see self::isSucceeded()} and its
 * neighbours; the raw string is carried alongside so reconciliation can
 * compare against what the provider actually said rather than against a
 * conclusion we drew and then forgot the basis of.
 *
 * ANYTHING UNRECOGNISED IS NOT SUCCESS. A status this class has never heard of
 * is treated as still in flight, never as money returned. Guessing in the
 * optimistic direction would mean telling a customer their refund completed on
 * the strength of a word nobody has checked.
 *
 * Amounts are integer minor units, as the provider reports them.
 */
final readonly class ProviderRefund
{
    /**
     * Paystack's terminal success. The money has gone back.
     */
    private const SUCCEEDED = ['processed'];

    /**
     * Terminal failures. `reversed` is included deliberately: a refund the
     * provider undid did not return the money, whatever it was called on the
     * way there.
     */
    private const FAILED = ['failed', 'reversed'];

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?string $providerReference,
        public string $status,
        public int $amountMinor,
        public string $currency,
        public array $raw = [],
    ) {}

    public function isSucceeded(): bool
    {
        return in_array(mb_strtolower($this->status), self::SUCCEEDED, true);
    }

    public function isFailed(): bool
    {
        return in_array(mb_strtolower($this->status), self::FAILED, true);
    }

    /**
     * Still being decided -- including any status this class does not
     * recognise, which is deliberately not treated as either outcome.
     */
    public function isPending(): bool
    {
        return ! $this->isSucceeded() && ! $this->isFailed();
    }
}
