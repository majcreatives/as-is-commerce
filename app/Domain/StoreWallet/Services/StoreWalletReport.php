<?php

declare(strict_types=1);

namespace App\Domain\StoreWallet\Services;

/**
 * The outcome of checking one Store Wallet's internal consistency.
 *
 * Carries the figures as well as the problems, so a report is useful for
 * diagnosis rather than just a pass/fail.
 */
final readonly class StoreWalletReport
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(
        public int $walletId,
        public int $userId,
        public int $storedBalance,
        public int $ledgerBalance,
        public array $problems,
    ) {}

    public function isHealthy(): bool
    {
        return $this->problems === [];
    }

    public function problemCount(): int
    {
        return count($this->problems);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'wallet_id' => $this->walletId,
            'user_id' => $this->userId,
            'stored_balance' => $this->storedBalance,
            'ledger_balance' => $this->ledgerBalance,
            'healthy' => $this->isHealthy(),
            'problems' => $this->problems,
        ];
    }
}
