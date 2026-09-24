<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Credit\Services\CreditLedgerService;
use App\Models\CreditLot;
use App\Models\CreditWallet;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Write off the unspent remainder of credit lots that have passed their expiry.
 *
 * WHY THIS EXISTS. Purchased credits do not expire by default, but promotional
 * credits may carry an `expires_at`. A wallet balance is a projection of the
 * ledger, and the ledger balance keeps counting an expired lot's remainder
 * until an EXPIRATION transaction is posted -- so without something nudging it,
 * an expired promotional grant would sit on the ledger (and in the materialized
 * balance) indefinitely while being invisible to `spendableBalance()`. This is
 * that something.
 *
 * It does not invent an expiry policy. A lot has an expiry only because the
 * grant that created it said so; the command only acts on lots whose own
 * `expires_at` has passed, decided by server time.
 *
 * Idempotent by construction: `CreditLedgerService::expireLots()` re-reads each
 * wallet's expired lots under a row lock, and the first pass takes a lot's
 * remainder to zero so later passes find nothing. A missed run delays a
 * write-off; it never writes off credits that were spent, and a duplicated run
 * writes off nothing twice.
 *
 * One wallet failing must not strand the rest -- a single problem wallet should
 * not stop every other expired grant on the platform being written off.
 */
class ExpireUnusedCredits extends Command
{
    protected $signature = 'credits:expire-unused {--limit=200 : Most wallets to write off in one pass}';

    protected $description = 'Write off the unspent remainder of credit lots that have passed their expiry';

    public function handle(CreditLedgerService $credits): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $walletIds = CreditLot::query()
            ->expiredWithRemainder()
            ->select('credit_wallet_id')
            ->distinct()
            ->orderBy('credit_wallet_id')
            ->limit($limit)
            ->pluck('credit_wallet_id');

        $writtenOff = 0;

        foreach ($walletIds as $walletId) {
            $wallet = CreditWallet::find($walletId);

            if ($wallet === null) {
                continue;
            }

            try {
                if ($credits->expireLots($wallet) !== null) {
                    $writtenOff++;
                }
            } catch (Throwable $e) {
                // Logged and skipped. A wallet that cannot be written off must
                // not stop every other expired grant being cleaned up.
                Log::error('Credit expiry failed', [
                    'operation' => 'credit.expire-unused',
                    'wallet_id' => $walletId,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                $this->warn("Wallet #{$walletId} could not be written off: {$e->getMessage()}");
            }
        }

        $this->info("Wrote off unused credits for {$writtenOff} wallet(s).");

        Cache::put('sweeps:expire_unused:last_run', Carbon::now()->toIso8601String());

        return self::SUCCESS;
    }
}
