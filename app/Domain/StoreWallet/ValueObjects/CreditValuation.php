<?php

declare(strict_types=1);

namespace App\Domain\StoreWallet\ValueObjects;

use App\Domain\Shared\Money\Money;
use Illuminate\Support\Collection;

/**
 * What a user's consumed credits on one auction were actually worth in cash.
 *
 * The total is the sum of its per-lot lines and is never computed any other
 * way. A single blended rate over a mixed set of lots would be wrong whenever
 * the lots were bought at different prices, which is the normal case: 20
 * credits from a GH0.10 lot and 10 from a GH0.08 lot are worth GH2.80, not
 * 30 credits at some average.
 *
 * FREE CREDITS CONTRIBUTE NOTHING. Promotional, referral and adjustment
 * credits are lines with a value of zero. They are kept in the breakdown
 * rather than filtered out, because "15 referral credits, worth nothing" is a
 * more honest answer to a customer asking why their figure is what it is than
 * silently omitting them.
 *
 * This object decides no policy. It says what the credits cost; whether that
 * becomes a Buy Now reduction or a Store Wallet issuance is decided elsewhere,
 * and the same figure is used for both so the two can never disagree.
 */
final readonly class CreditValuation
{
    /**
     * @param  Collection<int, LotValuation>  $lines  One per lot drawn from.
     */
    public function __construct(
        public Collection $lines,
        public Money $total,
        public int $totalCredits,
        public int $paidCredits,
    ) {}

    /**
     * Build from the per-lot lines, summing rather than being told a total.
     *
     * @param  Collection<int, LotValuation>  $lines
     */
    public static function of(Collection $lines, string $currency = 'GHS'): self
    {
        $total = $lines->reduce(
            fn (Money $carry, LotValuation $line): Money => $carry->plus($line->value),
            Money::zero($currency),
        );

        return new self(
            lines: $lines->values(),
            total: $total,
            totalCredits: (int) $lines->sum('credits'),
            paidCredits: (int) $lines->filter(fn (LotValuation $l): bool => $l->isPaid())->sum('credits'),
        );
    }

    public static function empty(string $currency = 'GHS'): self
    {
        return new self(collect(), Money::zero($currency), 0, 0);
    }

    public function isZero(): bool
    {
        return $this->total->isZero();
    }

    /**
     * Credits that cost nothing, and so contributed nothing.
     */
    public function freeCredits(): int
    {
        return $this->totalCredits - $this->paidCredits;
    }

    /**
     * Only the lines that actually produced value.
     *
     * @return Collection<int, LotValuation>
     */
    public function payingLines(): Collection
    {
        return $this->lines->filter(fn (LotValuation $line): bool => $line->value->isPositive())->values();
    }

    /**
     * The full derivation, for a pricing snapshot or an issuance record.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total_minor' => $this->total->minor,
            'currency' => $this->total->currency,
            'total_credits' => $this->totalCredits,
            'paid_credits' => $this->paidCredits,
            'free_credits' => $this->freeCredits(),
            'lots' => $this->lines->map(fn (LotValuation $line): array => $line->toArray())->all(),
        ];
    }
}
